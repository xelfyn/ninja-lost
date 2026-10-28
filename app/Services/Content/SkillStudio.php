<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Content\SkillBlueprint;
use App\Models\User;
use App\Support\Rewards\RewardClock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Authoring, immutable release and explicit activation; never writes arbitrary live files. */
final class SkillStudio
{
    public function save(User $actor, array $input, ?int $draftId = null, ?int $expectedRevision = null): array
    {
        return $this->write($actor, function () use ($actor, $input, $draftId, $expectedRevision) {
            $d = SkillBlueprint::normalize($input); $json = SkillBlueprint::json($d); $hash = hash('sha256', $json); $time = RewardClock::now();
            if ($draftId === null) {
                if (DB::table('nl_studio_drafts')->where('content_key', $d['id'])->exists() || DB::table('skills')->where('skill_id', $d['id'])->exists()
                    || DB::table('nl_catalog')->where('kind', 'skill')->where('content_key', $d['id'])->exists()
                    || DB::table('character_skills')->where('skill_id', $d['id'])->exists()) SkillBlueprint::reject('ID sudah digunakan; impor legacy tidak dilakukan dengan menimpa.');
                $id = DB::table('nl_studio_drafts')->insertGetId(['content_key' => $d['id'], 'revision' => 1, 'definition' => $json, 'definition_hash' => $hash,
                    'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => $time, 'updated_at' => $time]);
                $revision = 1;
            } else {
                $row = DB::table('nl_studio_drafts')->where('id', $draftId)->lockForUpdate()->first(); abort_unless($row, 404);
                abort_unless($expectedRevision === (int) $row->revision, 409, 'Draft berubah pada sesi lain. Muat ulang sebelum menyimpan.');
                if ($d['id'] !== $row->content_key) SkillBlueprint::reject('ID konten tidak dapat diganti. Buat draft baru untuk ID baru.');
                if (hash_equals($row->definition_hash, $hash)) return ['id' => $draftId, 'revision' => (int) $row->revision, 'hash' => $hash, 'unchanged' => true];
                if ((int) $row->revision >= 4294967295) SkillBlueprint::reject('Batas revision tercapai.');
                $id = $draftId; $revision = (int) $row->revision + 1;
                DB::table('nl_studio_drafts')->where('id', $id)->update(['revision' => $revision, 'definition' => $json, 'definition_hash' => $hash, 'updated_by' => $actor->id, 'updated_at' => $time]);
            }
            $this->audit($actor, 'studio.draft.save', 'draft:'.$id, ['revision' => $revision, 'hash' => $hash]);
            return ['id' => $id, 'revision' => $revision, 'hash' => $hash, 'unchanged' => false];
        });
    }

    public function publish(User $actor, int $draftId, int $expectedRevision, string $expectedHash): array
    {
        return $this->write($actor, function () use ($actor, $draftId, $expectedRevision, $expectedHash) {
            $row = DB::table('nl_studio_drafts')->where('id', $draftId)->lockForUpdate()->first(); abort_unless($row, 404);
            abort_unless($expectedRevision === (int) $row->revision && hash_equals($row->definition_hash, $expectedHash), 409, 'Simpan atau muat ulang draft terbaru sebelum menerbitkan.');
            $d = SkillBlueprint::normalize(json_decode($row->definition, true, 32, JSON_THROW_ON_ERROR));
            if (!hash_equals($row->definition_hash, hash('sha256', SkillBlueprint::json($d)))) SkillBlueprint::reject('Integritas draft gagal.');
            $prior = DB::table('nl_studio_releases')->where('draft_id', $draftId)->where('draft_revision', $expectedRevision)->first();
            if ($prior) { $this->decode($prior); return ['id' => $prior->id, 'hash' => $prior->payload_hash, 'replayed' => true]; }
            $id = (string) Str::uuid(); $payload = SkillBlueprint::json(SkillBlueprint::compile($d, $id)); $hash = hash('sha256', $payload);
            DB::table('nl_studio_releases')->insert(['id' => $id, 'draft_id' => $draftId, 'draft_revision' => $expectedRevision,
                'definition_hash' => $row->definition_hash, 'payload' => $payload, 'payload_hash' => $hash, 'created_by' => $actor->id, 'created_at' => RewardClock::now()]);
            $this->audit($actor, 'studio.release.publish', 'release:'.$id, ['draft_id' => $draftId, 'draft_revision' => $expectedRevision, 'hash' => $hash]);
            return ['id' => $id, 'hash' => $hash, 'replayed' => false];
        });
    }

    /** CAS prevents a stale browser tab from undoing another operator's activation. */
    public function activate(User $actor, string $releaseId, ?string $expectedHead): array
    {
        abort_unless(Str::isUuid($releaseId) && ($expectedHead === null || Str::isUuid($expectedHead)), 422);
        return $this->write($actor, function () use ($actor, $releaseId, $expectedHead) {
            $release = DB::table('nl_studio_releases')->where('id', $releaseId)->first(); abort_unless($release, 404);
            $draft = DB::table('nl_studio_drafts')->where('id', $release->draft_id)->lockForUpdate()->first(); abort_unless($draft, 404);
            // A locking current read is required: the earlier immutable release read may have
            // established an older REPEATABLE READ snapshot while this transaction waited.
            $head = DB::table('nl_studio_heads')->where('draft_id', $draft->id)->lockForUpdate()->first();
            $payload = $this->decode($release); $d = $payload['definition'];
            if ($d['id'] !== $draft->content_key) SkillBlueprint::reject('Identitas release tidak cocok dengan draft.');
            if ($head && $head->release_id === $releaseId) {
                $this->assertProjection($draft, $payload);
                return ['id' => $releaseId, 'catalog_id' => (int) $draft->catalog_id, 'replayed' => true];
            }
            abort_unless(($head?->release_id) === $expectedHead, 409, 'Versi aktif berubah. Muat ulang sebelum aktivasi atau rollback.');
            $time = RewardClock::now();
            if ($head) {
                $old = DB::table('nl_studio_releases')->where('id', $head->release_id)->first();
                if (!$old || (int) $old->draft_id !== (int) $draft->id) SkillBlueprint::reject('Head tidak konsisten.');
                $oldPayload = $this->decode($old);
                $this->assertProjection($draft, $oldPayload);
                $catalog = DB::table('nl_catalog')->where('id', $draft->catalog_id)->lockForUpdate()->first();
                $limit = $catalog->supply_limit === null ? null : (int) $catalog->supply_limit;
                if ($limit !== $d['supply_limit']) SkillBlueprint::reject('Batas supply tidak diubah melalui release mekanik. Pertahankan cap awal.');
                DB::table('skills')->where('id', $draft->backend_skill_id)->update($this->projection($d) + ['updated_at' => $time]);
                DB::table('nl_catalog')->where('id', $draft->catalog_id)->update($this->registry($payload) + ['enabled' => true, 'updated_at' => $time]);
                $catalogId = (int) $draft->catalog_id;
            } else {
                if ($draft->backend_skill_id || $draft->catalog_id) SkillBlueprint::reject('Referensi backend tanpa head; perlu audit operator.');
                if (DB::table('skills')->where('skill_id', $d['id'])->exists() || DB::table('nl_catalog')->where('kind', 'skill')->where('content_key', $d['id'])->exists()
                    || DB::table('character_skills')->where('skill_id', $d['id'])->exists()) SkillBlueprint::reject('ID berbenturan dengan data backend. Tidak ada data yang ditimpa.');
                $skillId = DB::table('skills')->insertGetId($this->projection($d) + ['skill_id' => $d['id'], 'price_gold' => 0, 'price_tokens' => 0, 'created_at' => $time, 'updated_at' => $time]);
                $catalogId = DB::table('nl_catalog')->insertGetId($this->registry($payload) + ['kind' => 'skill', 'content_key' => $d['id'],
                    'supply_limit' => $d['supply_limit'], 'opening_issued' => 0, 'issued' => 0, 'enabled' => true, 'created_by' => $actor->id, 'created_at' => $time, 'updated_at' => $time]);
                DB::table('nl_studio_drafts')->where('id', $draft->id)->update(['backend_skill_id' => $skillId, 'catalog_id' => $catalogId]);
            }
            DB::table('nl_studio_heads')->updateOrInsert(['draft_id' => $draft->id], ['release_id' => $releaseId, 'activated_by' => $actor->id, 'updated_at' => $time]);
            $this->audit($actor, 'studio.release.activate', 'release:'.$releaseId, ['draft_id' => (int) $draft->id, 'previous' => $head?->release_id, 'scope' => 'evaluation_mechanics_only']);
            return ['id' => $releaseId, 'catalog_id' => $catalogId, 'replayed' => false];
        });
    }

    public function decode(object $release): array
    {
        if (strlen($release->payload) > 65536 || !hash_equals($release->payload_hash, hash('sha256', $release->payload))) SkillBlueprint::reject('Checksum release gagal.');
        $payload = json_decode($release->payload, true, 32, JSON_THROW_ON_ERROR);
        if (($payload['profile'] ?? null) !== SkillBlueprint::PROFILE || !isset($payload['definition'])) SkillBlueprint::reject('Profil release tidak didukung.');
        $expected = SkillBlueprint::json(SkillBlueprint::compile($payload['definition'], $release->id));
        if (!hash_equals($expected, $release->payload) || !hash_equals($release->definition_hash, hash('sha256', SkillBlueprint::json($payload['definition'])))) SkillBlueprint::reject('Isi release berbeda dari compiler yang didukung.');
        return $payload;
    }

    /** Must be called inside account-first transaction; detect out-of-band legacy metadata edits. */
    public function assertProjection(object $draft, array $payload): void
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Projection requires transaction.');
        $catalog = DB::table('nl_catalog')->where('id', $draft->catalog_id)->lockForUpdate()->first();
        $skill = DB::table('skills')->where('id', $draft->backend_skill_id)->lockForUpdate()->first();
        $d = $payload['definition'];
        if (!$catalog || !$skill || $catalog->kind !== 'skill' || $catalog->content_key !== $d['id'] || $skill->skill_id !== $d['id']
            || $catalog->source_revision !== $payload['revision']) SkillBlueprint::reject('Proyeksi backend tidak cocok dengan release aktif.');
        foreach ($this->projection($d) as $key => $value) {
            if ($key === 'premium') { $same = (bool) $skill->{$key} === $value; }
            elseif (is_int($value)) { $same = (int) $skill->{$key} === $value; }
            else { $same = $skill->{$key} === $value; }
            if (!$same) SkillBlueprint::reject('Definisi backend diubah di luar Content Studio; audit sebelum aktivasi.');
        }
    }

    private function projection(array $d): array
    {
        return ['name' => $d['name'], 'level' => $d['level'], 'element' => $d['element'], 'premium' => $d['premium'], 'swf' => $d['animation_key'] ?? ''];
    }

    private function registry(array $payload): array
    {
        $d = $payload['definition'];
        return ['title' => $d['name'], 'source_url' => $d['source_url'], 'source_revision' => $payload['revision'], 'rights_basis' => $d['rights_basis'], 'rights_evidence' => $d['rights_evidence'],
            'compatibility_note' => 'Content Studio scoped mechanics only. Animation reference unverified; not live client activation.',
            'effect_metadata' => SkillBlueprint::json(['profile' => $payload['profile'], 'effects' => $d['effects'], 'client_ready' => false])];
    }

    private function write(User $actor, callable $operation): mixed
    {
        abort_unless(app()->environment(['local', 'testing']), 403, 'Evaluation runtime only.');
        try {
            return DB::transaction(function () use ($actor, $operation) {
                $account = DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
                abort_unless($account && (int) $account->account_type === 99, 403);
                return $operation();
            }, 3);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) SkillBlueprint::reject('Identitas sudah digunakan oleh operasi lain; muat ulang.');
            throw $e;
        }
    }

    private function audit(User $actor, string $action, string $subject, array $details): void
    {
        DB::table('nl_operations_audit')->insert(['actor_id' => $actor->id, 'action' => $action, 'subject' => $subject, 'details' => SkillBlueprint::json($details), 'created_at' => RewardClock::now()]);
    }
}
