<?php

declare(strict_types=1);

namespace App\Evaluation;

use App\Content\SkillBlueprint;
use Symfony\Component\Process\Process;

/** Bounded synchronous evaluation adapter, not a long-lived production battle worker. */
final class TrainingRuntime
{
    public function fingerprint(): string
    {
        $files = ['combat/training/runner.cjs', 'combat/training/TrainingEngine.cjs', 'combat/evaluation/TurnSafeBattle.cjs',
            'combat/data/PublishedSkillCatalog.cjs', 'combat/data/SkillCatalog.cjs', 'combat/data/EquipmentStats.cjs',
            'combat/data/equipment-stats-v1.json', 'ops/combat/upstream-lock.json'];
        $hashes = [];
        foreach ($files as $file) { $hash = hash_file('sha256', base_path($file)); abort_unless($hash, 503); $hashes[$file] = $hash; }
        return hash('sha256', SkillBlueprint::json($hashes));
    }

    public function replay(string $id, string $seed, array $bundle, array $commands): array
    {
        $input = SkillBlueprint::json(['protocol' => 'nl-training-v1', 'id' => $id, 'seed' => $seed, 'bundle' => $bundle, 'actions' => $commands]);
        abort_if(strlen($input) > 1048576, 422, 'Snapshot latihan terlalu besar.');
        $source = config('training.source');
        abort_unless(is_string($source) && is_dir($source), 503, 'Source combat terverifikasi belum tersedia.');
        // Do not pass application keys, database credentials or CI tokens to the engine process.
        $env = array_fill_keys(array_keys(array_merge($_SERVER, $_ENV, getenv() ?: [])), false);
        foreach (['PATH', 'SystemRoot', 'SYSTEMROOT', 'WINDIR', 'TEMP', 'TMP'] as $key) if (getenv($key) !== false) $env[$key] = getenv($key);
        $env['NL_COMBAT_SOURCE'] = $source;
        $process = new Process(['node', base_path('combat/training/runner.cjs')], base_path(), $env, $input, 5);
        $bytes = 0;
        $process->run(function ($type, $output) use (&$bytes) {
            $bytes += strlen($output);
            if ($bytes > 524288) throw new \RuntimeException('Training engine output exceeded bound.');
        });
        abort_unless($process->isSuccessful(), 503, 'Engine menolak replay; state tidak disimpan.');
        $result = json_decode($process->getOutput(), true, 48, JSON_THROW_ON_ERROR);
        abort_unless(is_array($result) && ($result['state']['protocol'] ?? null) === 'nl-training-v1'
            && ($result['state']['rewards_issued'] ?? null) === false && array_key_exists('previous', $result), 503);
        return $result;
    }
}
