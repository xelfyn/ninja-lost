<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Content\EquipmentBlueprint as Blueprint;
use App\Content\SkillBlueprint;
use App\Models\User;
use App\Support\Rewards\RewardClock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One bundle release atomically publishes its pieces and cumulative set rules. */
final class EquipmentStudio
{
    public function save(User $actor, array $input, ?int $draftId = null, ?int $expectedRevision = null): array
    {
        return $this->write($actor, function () use ($actor, $input, $draftId, $expectedRevision) {
            $d = Blueprint::normalize($input); $json = SkillBlueprint::json($d); $hash = hash('sha256', $json); $time = RewardClock::now();
            if ($draftId === null) {
                if (DB::table('nl_studio_drafts')->where('content_key', $d['id'])->exists()) SkillBlueprint::reject('ID paket sudah digunakan.');
                $id = DB::table('nl_studio_drafts')->insertGetId(['content_key' => $d['id'], 'revision' => 1, 'definition' => $json, 'definition_hash' => $hash,
                    'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => $time, 'updated_at' => $time]);
                $revision = 1;
            } else {
                $row = $this->draft($draftId, true);
                abort_unless($expectedRevision === (int) $row->revision, 409, 'Draft berubah; muat ulang.');
                if ($d['id'] !== $row->content_key) SkillBlueprint::reject('ID paket tidak dapat diubah.');
                if (hash_equals($row->definition_hash, $hash)) return ['id' => $draftId, 'revision' => (int) $row->revision, 'hash' => $hash, 'unchanged' => true];
                if ((int) $row->revision >= 4294967295) SkillBlueprint::reject('Batas revision tercapai.');
                $id = $draftId; $revision = (int) $row->revision + 1;
                DB::table('nl_studio_drafts')->where('id', $id)->update(['revision' => $revision, 'definition' => $json, 'definition_hash' => $hash, 'updated_by' => $actor->id, 'updated_at' => $time]);
            }
            $this->audit($actor, 'equipment.draft.save', $id, ['revision' => $revision, 'hash' => $hash]);
            return ['id' => $id, 'revision' => $revision, 'hash' => $hash, 'unchanged' => false];
        });
    }

    public function publish(User $actor, int $draftId, int $revision, string $hash): array
    {
        return $this->write($actor, function () use ($actor, $draftId, $revision, $hash) {
            $row = $this->draft($draftId, true);
            abort_unless((int) $row->revision === $revision && hash_equals($row->definition_hash, $hash), 409, 'Simpan draft terbaru sebelum menerbitkan.');
            $d = Blueprint::normalize(json_decode($row->definition, true, 32, JSON_THROW_ON_ERROR));
            if (!hash_equals($hash, hash('sha256', SkillBlueprint::json($d)))) SkillBlueprint::reject('Integritas draft gagal.');
            $prior = DB::table('nl_studio_releases')->where('draft_id', $draftId)->where('draft_revision', $revision)->sharedLock()->first();
            if ($prior) { $this->decode($prior); return ['id' => $prior->id, 'hash' => $prior->payload_hash, 'replayed' => true]; }
            $id = (string) Str::uuid(); $payload = SkillBlueprint::json(Blueprint::compile($d, $id)); $digest = hash('sha256', $payload);
            DB::table('nl_studio_releases')->insert(['id' => $id, 'draft_id' => $draftId, 'draft_revision' => $revision, 'definition_hash' => $hash,
                'payload' => $payload, 'payload_hash' => $digest, 'created_by' => $actor->id, 'created_at' => RewardClock::now()]);
            $this->audit($actor, 'equipment.release.publish', $draftId, ['release' => $id, 'hash' => $digest]);
            return ['id' => $id, 'hash' => $digest, 'replayed' => false];
        });
    }

    public function activate(User $actor, string $releaseId, ?string $expectedHead): array
    {
        abort_unless(Str::isUuid($releaseId) && ($expectedHead === null || Str::isUuid($expectedHead)), 422);
        return $this->write($actor, function () use ($actor, $releaseId, $expectedHead) {
            $release = DB::table('nl_studio_releases')->where('id', $releaseId)->first(); abort_unless($release, 404);
            $draft = $this->draft((int) $release->draft_id, true);
            $head = DB::table('nl_studio_heads')->where('draft_id', $draft->id)->lockForUpdate()->first();
            $payload = $this->decode($release); $d = $payload['definition'];
            if ($d['id'] !== $draft->content_key || $draft->backend_skill_id || $draft->catalog_id) SkillBlueprint::reject('Identitas paket tidak konsisten.');
            $members = DB::table('nl_studio_equipment')->where('draft_id', $draft->id)->orderBy('content_key')->sharedLock()->get();
            if ($head && $head->release_id === $releaseId) {
                $this->assertProjection($members, $payload, true);
                return ['id' => $releaseId, 'replayed' => true];
            }
            abort_unless($head?->release_id === $expectedHead, 409, 'Versi aktif berubah; muat ulang.');
            $time = RewardClock::now();
            if ($head) {
                $old = DB::table('nl_studio_releases')->where('id', $head->release_id)->sharedLock()->first();
                if (!$old || (int) $old->draft_id !== (int) $draft->id) SkillBlueprint::reject('Head paket tidak valid.');
                $before = $this->decode($old); $this->assertProjection($members, $before, true);
                $identities = fn ($p) => array_map(fn ($item) => [$item['id'], $item['slot'], $item['supply_limit']], $p['definition']['pieces']);
                if ($identities($before) !== $identities($payload)) SkillBlueprint::reject('Anggota, slot, dan cap awal tidak dapat diganti melalui versi mekanik v1. Buat paket baru.');
                foreach ($d['pieces'] as $p) {
                    $member = $members->firstWhere('content_key', $p['id']);
                    DB::table('items')->where('id', $member->backend_item_id)->update($this->projection($p) + ['updated_at' => $time]);
                    DB::table('nl_catalog')->where('id', $member->catalog_id)->update($this->registry($payload, $p) + ['enabled' => true, 'updated_at' => $time]);
                }
            } else {
                if ($members->isNotEmpty()) SkillBlueprint::reject('Mapping tanpa head; audit operator diperlukan.');
                foreach ($d['pieces'] as $p) {
                    if (DB::table('nl_studio_equipment')->where('content_key', $p['id'])->sharedLock()->exists()
                        || DB::table('items')->where('item_id', $p['id'])->sharedLock()->exists()
                        || DB::table('nl_catalog')->where('kind', 'item')->where('content_key', $p['id'])->sharedLock()->exists()
                        || DB::table('character_items')->where('item_id', $p['id'])->sharedLock()->exists()) SkillBlueprint::reject('ID bagian sudah digunakan; data tidak ditimpa.');
                    $item = DB::table('items')->insertGetId($this->projection($p) + ['item_id' => $p['id'], 'price_gold' => 0, 'price_tokens' => 0, 'created_at' => $time, 'updated_at' => $time]);
                    $catalog = DB::table('nl_catalog')->insertGetId($this->registry($payload, $p) + ['kind' => 'item', 'content_key' => $p['id'],
                        'supply_limit' => $p['supply_limit'], 'opening_issued' => 0, 'issued' => 0, 'enabled' => true, 'created_by' => $actor->id, 'created_at' => $time, 'updated_at' => $time]);
                    DB::table('nl_studio_equipment')->insert(['content_key' => $p['id'], 'draft_id' => $draft->id, 'slot' => $p['slot'], 'backend_item_id' => $item, 'catalog_id' => $catalog]);
                }
            }
            DB::table('nl_studio_heads')->updateOrInsert(['draft_id' => $draft->id], ['release_id' => $releaseId, 'activated_by' => $actor->id, 'updated_at' => $time]);
            $this->audit($actor, 'equipment.release.activate', (int) $draft->id, ['release' => $releaseId, 'previous' => $head?->release_id, 'scope' => 'evaluation_only']);
            return ['id' => $releaseId, 'replayed' => false];
        });
    }

    /** Lock before catalog/definition reads. Caller must already hold the account/character lock. */
    public function active(int $draftId): array
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Active bundle requires transaction.');
        $draft = $this->draft($draftId, false);
        $head = DB::table('nl_studio_heads')->where('draft_id', $draftId)->sharedLock()->first();
        $release = $head ? DB::table('nl_studio_releases')->where('id', $head->release_id)->sharedLock()->first() : null;
        if (!$release || (int) $release->draft_id !== $draftId) SkillBlueprint::reject('Paket belum aktif.');
        $payload = $this->decode($release);
        if ($payload['definition']['id'] !== $draft->content_key) SkillBlueprint::reject('Identitas release tidak cocok.');
        $members = DB::table('nl_studio_equipment')->where('draft_id', $draftId)->orderBy('content_key')->sharedLock()->get();
        $this->assertProjection($members, $payload, false);
        return ['payload' => $payload, 'release' => ['id' => $release->id, 'sha256' => $release->payload_hash, 'payload' => $release->payload]];
    }

    public function decode(object $release): array
    {
        if (strlen($release->payload) > 131072 || !hash_equals($release->payload_hash, hash('sha256', $release->payload))) SkillBlueprint::reject('Checksum release equipment gagal.');
        $p = json_decode($release->payload, true, 32, JSON_THROW_ON_ERROR);
        if (($p['profile'] ?? null) !== Blueprint::PROFILE || !isset($p['definition'])) SkillBlueprint::reject('Profil bukan equipment yang didukung.');
        if (!hash_equals($release->payload, SkillBlueprint::json(Blueprint::compile($p['definition'], $release->id)))
            || !hash_equals($release->definition_hash, hash('sha256', SkillBlueprint::json($p['definition'])))) SkillBlueprint::reject('Compiler atau definisi release equipment tidak cocok.');
        return $p;
    }

    private function draft(int $id, bool $exclusive): object
    {
        $q = DB::table('nl_studio_drafts')->where('id', $id); $r = ($exclusive ? $q->lockForUpdate() : $q->sharedLock())->first();
        abort_unless($r && str_starts_with($r->content_key, 'gear_nl_'), 404); return $r;
    }

    private function assertProjection($members, array $payload, bool $exclusive): void
    {
        if ($members->count() !== count($payload['definition']['pieces'])) SkillBlueprint::reject('Mapping anggota paket tidak lengkap.');
        foreach ($payload['definition']['pieces'] as $p) {
            $m = $members->firstWhere('content_key', $p['id']); if (!$m || $m->slot !== $p['slot']) SkillBlueprint::reject('Mapping slot tidak cocok.');
            $q = DB::table('nl_catalog')->where('id', $m->catalog_id); $catalog = ($exclusive ? $q->lockForUpdate() : $q->sharedLock())->first();
            $q = DB::table('items')->where('id', $m->backend_item_id); $item = ($exclusive ? $q->lockForUpdate() : $q->sharedLock())->first();
            if (!$catalog || !$item || $catalog->kind !== 'item' || $catalog->content_key !== $p['id'] || $item->item_id !== $p['id']) SkillBlueprint::reject('Proyeksi equipment tidak cocok.');
            foreach ($this->projection($p) as $key => $value) {
                $actual = is_bool($value) ? (bool) $item->{$key} : (is_int($value) ? (int) $item->{$key} : $item->{$key});
                if ($actual !== $value) SkillBlueprint::reject('Definisi item diubah di luar Studio.');
            }
            foreach ($this->registry($payload, $p) as $key => $value) {
                if ($key === 'effect_metadata') { if (json_decode($catalog->{$key}, true) != json_decode($value, true)) SkillBlueprint::reject('Metadata mekanik berbeda.'); }
                elseif ($catalog->{$key} !== $value) SkillBlueprint::reject('Registri berubah di luar Studio.');
            }
            if (($catalog->supply_limit === null ? null : (int) $catalog->supply_limit) !== $p['supply_limit']) SkillBlueprint::reject('Cap awal berubah di luar Studio.');
        }
    }

    private function projection(array $p): array { return ['name' => $p['name'], 'category' => $p['slot'], 'level' => $p['level'], 'premium' => $p['premium']]; }
    private function registry(array $payload, array $p): array
    {
        $d = $payload['definition'];
        return ['title' => $p['name'], 'source_url' => $d['source_url'], 'source_revision' => $payload['revision'], 'rights_basis' => $d['rights_basis'], 'rights_evidence' => $d['rights_evidence'],
            'compatibility_note' => 'Equipment Studio base-stat evaluation only; native assets and client parity unverified.',
            'effect_metadata' => SkillBlueprint::json(['profile' => Blueprint::PROFILE, 'bundle' => $d['id'], 'client_ready' => false, 'modifiers' => $p['modifiers']])];
    }
    private function write(User $actor, callable $operation): mixed
    {
        abort_unless(app()->environment(['local', 'testing']), 403);
        try {
            return DB::transaction(function () use ($actor, $operation) {
                $account = DB::table('users')->where('id', $actor->id)->lockForUpdate()->first(); abort_unless($account && (int) $account->account_type === 99, 403);
                return $operation();
            }, 3);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) SkillBlueprint::reject('ID sedang digunakan operasi lain; muat ulang.');
            throw $e;
        }
    }
    private function audit(User $actor, string $action, int $draftId, array $details): void
    {
        DB::table('nl_operations_audit')->insert(['actor_id' => $actor->id, 'action' => $action, 'subject' => 'draft:'.$draftId,
            'details' => SkillBlueprint::json($details), 'created_at' => RewardClock::now()]);
    }
}
