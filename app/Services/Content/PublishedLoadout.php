<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Content\SkillBlueprint;
use App\Models\User;
use App\Services\Combat\CombatLoadout;
use Illuminate\Support\Facades\DB;

/** Internal server bundle. Not a browser-submitted loadout or native-client endpoint. */
final class PublishedLoadout
{
    public function prepare(User $actor, int $characterId): array
    {
        abort_unless(app()->environment(['local', 'testing']), 403);
        return DB::transaction(function () use ($actor, $characterId) {
            $snapshot = app(CombatLoadout::class)->prepare($actor, $characterId);
            $releases = [];
            foreach ($snapshot['skills'] as $ref) {
                $draft = DB::table('nl_studio_drafts')->where('content_key', $ref['id'])->first();
                if (!$draft) SkillBlueprint::reject('Skill bukan release Content Studio. Gunakan resolver sumber yang sesuai.');
                $head = DB::table('nl_studio_heads')->where('draft_id', $draft->id)->first();
                $release = $head ? DB::table('nl_studio_releases')->where('id', $head->release_id)->first() : null;
                if (!$release || (int) $release->draft_id !== (int) $draft->id) SkillBlueprint::reject('Skill belum memiliki release aktif.');
                $studio = app(SkillStudio::class); $payload = $studio->decode($release);
                if ($payload['revision'] !== $ref['source_revision']) SkillBlueprint::reject('Versi snapshot dan mekanik tidak cocok.');
                // CombatLoadout already holds shared catalog/definition locks until this outer transaction ends.
                $releases[] = ['id' => $release->id, 'sha256' => $release->payload_hash, 'payload' => $release->payload];
            }
            return ['snapshot' => $snapshot, 'releases' => $releases, 'scope' => 'Pinned server input only; no battle reservation, native client or reward settlement.'];
        }, 3);
    }
}
