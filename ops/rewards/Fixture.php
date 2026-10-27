<?php

namespace NinjaLost\QA;

use App\Models\Character;
use App\Models\User;
use App\Services\Rewards\RewardManagement;
use Illuminate\Support\Facades\DB;

final class Fixture
{
    public static function guard(): void
    {
        if (getenv('CI') !== 'true' || config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'ninja_lost_eval' || !app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Synthetic reward fixtures require isolated CI MySQL.');
        }
    }

    public static function actor(int $role = 0, ?string $email = null, ?string $password = null): User
    {
        self::guard();
        $key = 'nlqa_'.bin2hex(random_bytes(8));
        return User::create(['name' => 'QA synthetic account', 'username' => $key, 'email' => $email ?? $key.'@example.invalid', 'account_type' => $role, 'tokens' => 0, 'password' => $password ?? bin2hex(random_bytes(24))]);
    }

    public static function character(User $user): Character
    {
        self::guard();
        return Character::create(['user_id' => $user->id, 'name' => 'QA Character '.$user->id, 'gold' => 0]);
    }

    public static function content(User $admin, string $kind = 'skill', ?int $limit = 20): array
    {
        self::guard();
        $key = 'nlqa_'.bin2hex(random_bytes(8));
        $table = ['skill' => 'skills', 'item' => 'items', 'pet' => 'pets'][$kind];
        $values = [$kind.'_id' => $key, 'name' => 'QA Synthetic '.$kind, 'created_at' => now(), 'updated_at' => now()];
        if ($kind === 'item') { $values['category'] = 'item'; }
        if ($kind === 'pet') { $values['swf'] = 'qa-no-client-asset.swf'; }
        DB::table($table)->insert($values);
        $ops = app(RewardManagement::class);
        $id = $ops->register($admin, [
            'kind' => $kind, 'content_key' => $key, 'title' => 'QA Synthetic '.$kind,
            'source_url' => 'https://example.invalid/synthetic-fixture', 'source_revision' => 'mb02-fixture-v1',
            'rights_basis' => 'self_authored', 'rights_evidence' => 'Synthetic database fixture authored for CI; no game assets.',
            'compatibility_note' => 'Database grant test only; no battle or Android compatibility claim.', 'supply_limit' => $limit,
        ]);
        $ops->setCatalogEnabled($admin, $id, true);
        return ['id' => $id, 'type' => $kind, 'key' => $key, 'quantity' => 1];
    }

    public static function campaign(User $admin, array $rewards = [], array $overrides = [], bool $active = true): int
    {
        self::guard();
        $ops = app(RewardManagement::class);
        $id = $ops->createCampaign($admin, array_replace([
            'slug' => 'nlqa-'.bin2hex(random_bytes(8)), 'title' => 'QA Synthetic Starter', 'kind' => 'starter',
            'starts_at' => now()->subMinute()->toIso8601String(), 'ends_at' => now()->addDay()->toIso8601String(), 'max_claims' => 20,
            'choices' => [['key' => 'default', 'label' => 'QA Default', 'rewards' => $rewards ?: [['type'=>'tokens','key'=>'','quantity'=>5000], ['type'=>'gold','key'=>'','quantity'=>2500]]]],
        ], $overrides));
        if ($active) { $ops->setCampaignStatus($admin, $id, 'active'); }
        return $id;
    }

    /** Delete only fixture IDs created by this process, children before restricted parents. */
    public static function cleanup(array $userIds, array $campaignIds, array $catalogIds): void
    {
        self::guard();
        $claims = DB::table('nl_claims')->whereIn('campaign_id', $campaignIds)->pluck('id');
        DB::table('nl_reward_ledger')->whereIn('claim_id', $claims)->delete();
        DB::table('nl_claims')->whereIn('id', $claims)->delete();
        DB::table('nl_campaigns')->whereIn('id', $campaignIds)->delete();
        $catalogs = DB::table('nl_catalog')->whereIn('id', $catalogIds)->get();
        DB::table('nl_catalog')->whereIn('id', $catalogIds)->delete();
        DB::table('nl_operations_audit')->whereIn('actor_id', $userIds)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
        foreach ($catalogs as $row) {
            $table = ['skill'=>'skills', 'item'=>'items', 'pet'=>'pets'][$row->kind] ?? null;
            if ($table && str_starts_with($row->content_key, 'nlqa_')) {
                DB::table($table)->where($row->kind.'_id', $row->content_key)->delete();
            }
        }
    }
}
