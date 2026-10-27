<?php

namespace App\Services\Rewards;

use App\Models\User;
use App\Support\Rewards\RewardRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class RewardManagement
{
    public static function authorize(User $actor): void
    {
        abort_unless(app()->environment(['local', 'testing']) && (int) DB::table('users')->where('id', $actor->id)->value('account_type') === 99, 403);
    }

    private function audit(User $actor, string $action, string $subject, array $details): void
    {
        DB::table('nl_operations_audit')->insert(['actor_id' => $actor->id, 'action' => $action, 'subject' => $subject, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public function register(User $actor, array $input): int
    {
        self::authorize($actor);
        $data = Validator::make($input, [
            'kind' => ['required', 'in:item,skill,pet,effect'],
            'content_key' => ['required', 'string', 'regex:/\A[A-Za-z0-9_.-]{1,100}\z/D'],
            'title' => ['required', 'string', 'max:160'],
            'source_url' => ['required', 'url', 'starts_with:https://', 'max:500'],
            'source_revision' => ['required', 'string', 'max:100'],
            'rights_basis' => ['required', 'in:self_authored,permission_documented'],
            'rights_evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'compatibility_note' => ['required', 'string', 'min:10', 'max:2000'],
            'supply_limit' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'effect_metadata' => ['nullable', 'array'],
        ])->validate();
        return DB::transaction(function () use ($actor, $data) {
            if (DB::table('nl_catalog')->where('kind', $data['kind'])->where('content_key', $data['content_key'])->exists()) {
                RewardRules::reject('Konten sudah terdaftar; supply dan provenance tidak ditimpa.');
            }
            $opening = 0;
            if (isset(RewardRules::CONTENT[$data['kind']])) {
                [$definitions, $column, $inventory] = RewardRules::CONTENT[$data['kind']];
                if (!DB::table($definitions)->where($column, $data['content_key'])->exists()) {
                    RewardRules::reject('ID belum ada di katalog backend; buat definisinya terlebih dahulu.');
                }
                $rows = DB::table($inventory)->where($column, $data['content_key']);
                if ($data['kind'] === 'item' && (clone $rows)->where('quantity', '<', 0)->exists()) {
                    RewardRules::reject('Inventory lama tidak valid; audit sebelum registrasi.');
                }
                $opening = $data['kind'] === 'item' ? (int) $rows->sum('quantity') : $rows->count();
            }
            $limit = isset($data['supply_limit']) ? (int) $data['supply_limit'] : null;
            if ($opening > 4294967295 || ($limit !== null && $opening > $limit)) {
                RewardRules::reject('Supply tidak boleh lebih kecil dari inventory yang sudah ada.');
            }
            $id = DB::table('nl_catalog')->insertGetId([
                'kind' => $data['kind'], 'content_key' => $data['content_key'], 'title' => $data['title'],
                'source_url' => $data['source_url'], 'source_revision' => $data['source_revision'],
                'rights_basis' => $data['rights_basis'], 'rights_evidence' => $data['rights_evidence'],
                'compatibility_note' => $data['compatibility_note'],
                'effect_metadata' => isset($data['effect_metadata']) ? json_encode($data['effect_metadata'], JSON_THROW_ON_ERROR) : null,
                'supply_limit' => $limit, 'opening_issued' => $opening, 'issued' => $opening,
                'enabled' => false, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($actor, 'catalog.register', 'catalog:'.$id, ['opening_issued' => $opening, 'supply_limit' => $limit]);
            return $id;
        }, 3);
    }

    public function setCatalogEnabled(User $actor, int $id, bool $enabled): void
    {
        self::authorize($actor);
        DB::transaction(function () use ($actor, $id, $enabled) {
            $row = DB::table('nl_catalog')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            DB::table('nl_catalog')->where('id', $id)->update(['enabled' => $enabled, 'updated_at' => now()]);
            $this->audit($actor, 'catalog.state', 'catalog:'.$id, ['enabled' => $enabled]);
        }, 3);
    }

    public function createCampaign(User $actor, array $input): int
    {
        self::authorize($actor);
        $data = Validator::make($input, [
            'slug' => ['required', 'string', 'regex:/\A[a-z0-9-]{1,80}\z/D'],
            'title' => ['required', 'string', 'max:160'],
            'kind' => ['required', 'in:starter,redeem'],
            'code' => ['nullable', 'string', 'max:64'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'max_claims' => ['required', 'integer', 'min:1', 'max:1000000'],
            'choices' => ['required', 'array'],
        ])->validate();
        $choices = RewardRules::choices($data['choices']);
        $hash = $data['kind'] === 'redeem' ? RewardRules::codeHash($data['code'] ?? '') : null;
        if ($data['kind'] === 'starter' && !empty($data['code'])) {
            RewardRules::reject('Starter pack tidak menggunakan kode redeem.');
        }
        return DB::transaction(function () use ($actor, $data, $choices, $hash) {
            if (DB::table('nl_campaigns')->where('slug', $data['slug'])->exists() || ($hash && DB::table('nl_campaigns')->where('code_hash', $hash)->exists())) {
                RewardRules::reject('Slug/kode sudah digunakan.');
            }
            $id = DB::table('nl_campaigns')->insertGetId([
                'slug' => $data['slug'], 'title' => $data['title'], 'kind' => $data['kind'], 'status' => 'draft',
                'code_hash' => $hash, 'starts_at' => CarbonImmutable::parse($data['starts_at'])->utc(),
                'ends_at' => CarbonImmutable::parse($data['ends_at'])->utc(), 'max_claims' => (int) $data['max_claims'],
                'choices' => json_encode($choices, JSON_THROW_ON_ERROR), 'created_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            // Never retain plaintext reward codes in the database, audit log or notifications.
            $this->audit($actor, 'campaign.create', 'campaign:'.$id, ['kind' => $data['kind'], 'claim_scope' => 'account', 'max_claims' => (int) $data['max_claims']]);
            return $id;
        }, 3);
    }

    public function setCampaignStatus(User $actor, int $id, string $status): void
    {
        self::authorize($actor);
        abort_unless(in_array($status, ['active', 'paused'], true), 422);
        DB::transaction(function () use ($actor, $id, $status) {
            $row = DB::table('nl_campaigns')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($status === 'active') {
                if (now()->gte($row->ends_at) || $row->claimed_count >= $row->max_claims) {
                    RewardRules::reject('Campaign kedaluwarsa atau kuotanya habis.');
                }
                foreach (RewardRules::choices(json_decode($row->choices, true, 32, JSON_THROW_ON_ERROR)) as $choice) {
                    foreach ($choice['rewards'] as $line) {
                        if (isset(RewardRules::CONTENT[$line['type']]) && !DB::table('nl_catalog')->where('kind', $line['type'])->where('content_key', $line['key'])->where('enabled', true)->exists()) {
                            RewardRules::reject('Seluruh konten hadiah harus aktif di registri dahulu.');
                        }
                    }
                }
            }
            DB::table('nl_campaigns')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
            $this->audit($actor, 'campaign.state', 'campaign:'.$id, ['from' => $row->status, 'to' => $status]);
        }, 3);
    }
}
