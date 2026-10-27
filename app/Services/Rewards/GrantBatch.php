<?php

declare(strict_types=1);

namespace App\Services\Rewards;

use App\Support\Economy\StateRevision;
use App\Support\Rewards\RewardRules;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Internal issuer shared by campaign and staff adapters; never exposed as a client endpoint. */
final class GrantBatch
{
    public function __construct(private RewardWriter $writer) {}

    public function apply(object $account, object $character, array $lines, CarbonInterface $time): array
    {
        if (DB::transactionLevel() < 1 || (int) $character->user_id !== (int) $account->id) {
            throw new \LogicException('Issuer requires locked matching account and character in a transaction.');
        }
        $lines = RewardRules::choices([['key' => 'batch', 'label' => 'Internal grant', 'rewards' => $lines]])[0]['rewards'];
        $catalogs = [];
        $content = array_values(array_filter($lines, fn ($line) => isset(RewardRules::CONTENT[$line['type']])));
        usort($content, fn ($a, $b) => strcmp(strtolower($a['type'].':'.$a['key']), strtolower($b['type'].':'.$b['key'])));
        foreach ($content as $line) {
            $row = DB::table('nl_catalog')->where('kind', $line['type'])->where('content_key', $line['key'])->lockForUpdate()->first();
            if (!$row || !$row->enabled || !in_array($row->rights_basis, ['self_authored', 'permission_documented'], true)) {
                RewardRules::reject('Konten belum diizinkan dan diaktifkan pada registri.');
            }
            if ($row->issued > 4294967295 - $line['quantity'] || ($row->supply_limit !== null && $row->issued + $line['quantity'] > $row->supply_limit)) {
                RewardRules::reject('Supply limited sudah habis.');
            }
            $catalogs[$line['type'].':'.$line['key']] = $row;
        }
        $granted = []; $entries = [];
        foreach ($lines as $line) {
            $catalog = $catalogs[$line['type'].':'.$line['key']] ?? null;
            $result = $this->writer->apply($account, $character, $line, $catalog);
            $granted[] = $result;
            if ($catalog) {
                DB::table('nl_catalog')->where('id', $catalog->id)->increment('issued', $line['quantity'], ['updated_at' => $time]);
            }
            $entries[] = ['catalog_id' => $catalog?->id, 'kind' => $result['type'], 'content_key' => $result['key'], 'quantity' => $result['quantity'], 'balance_before' => $result['before'], 'balance_after' => $result['after'], 'created_at' => $time];
        }
        StateRevision::bump((int) $account->id);
        return ['granted' => $granted, 'entries' => $entries];
    }
}
