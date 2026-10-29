<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ContentDirectoryService
{
    private const CONTENT = [
        'item' => ['table' => 'items', 'key' => 'item_id', 'level' => true],
        'skill' => ['table' => 'skills', 'key' => 'skill_id', 'level' => true],
        'pet' => ['table' => 'pets', 'key' => 'pet_id', 'level' => false],
    ];

    public function __construct(private readonly GameAssetRegistry $assets)
    {
    }

    public function searchCharacters(string $query, int $limit = 20): array
    {
        $query = trim($query);
        $limit = $this->limit($limit);

        $rows = DB::table('characters as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->when($query !== '', function ($builder) use ($query) {
                $like = '%'.$this->escapeLike($query).'%';
                $builder->where(function ($q) use ($like) {
                    $q->where('c.name', 'like', $like)
                        ->orWhere('u.username', 'like', $like)
                        ->orWhereRaw('CAST(c.id AS CHAR) LIKE ?', [$like]);
                });
            })
            ->orderBy('c.name')
            ->orderBy('u.username')
            ->orderBy('c.id')
            ->limit($limit)
            ->get(['c.id', 'c.name', 'c.level', 'c.rank', 'u.username']);

        return $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'level' => (int) $row->level,
            'rank' => (int) $row->rank,
            'username' => (string) $row->username,
            'label' => sprintf('%s · Lv. %d · @%s · #%d', $row->name, $row->level, $row->username, $row->id),
        ])->all();
    }

    public function character(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $row = DB::table('characters as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.id', $id)
            ->first(['c.id', 'c.name', 'c.level', 'c.rank', 'u.username']);

        if (! $row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'level' => (int) $row->level,
            'rank' => (int) $row->rank,
            'username' => (string) $row->username,
            'label' => sprintf('%s · Lv. %d · @%s · #%d', $row->name, $row->level, $row->username, $row->id),
        ];
    }

    public function searchContent(string $kind, string $query, int $limit = 20): array
    {
        $definition = self::CONTENT[$kind] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException('Unsupported content kind.');
        }

        $query = trim($query);
        $limit = $this->limit($limit);
        $table = $definition['table'];
        $key = $definition['key'];

        $builder = DB::table('nl_catalog as c')
            ->leftJoin($table.' as d', 'd.'.$key, '=', 'c.content_key')
            ->where('c.kind', $kind)
            ->where('c.enabled', true);

        if ($query !== '') {
            $like = '%'.$this->escapeLike($query).'%';
            $builder->where(function ($q) use ($like) {
                $q->where('c.title', 'like', $like)
                    ->orWhere('c.content_key', 'like', $like)
                    ->orWhere('d.name', 'like', $like);
            });
        }

        $columns = [
            'c.content_key',
            'c.title as catalog_title',
            'c.source_revision',
            'c.supply_limit',
            'c.issued',
            'd.name as definition_name',
        ];

        if ($definition['level']) {
            $columns[] = 'd.level';
        }

        $rows = $builder
            ->orderByRaw('COALESCE(d.name, c.title) asc')
            ->orderBy('c.content_key')
            ->limit($limit)
            ->get($columns);

        return $rows->map(function ($row) use ($kind, $definition) {
            $key = (string) $row->content_key;
            $name = trim((string) ($row->definition_name ?: $row->catalog_title ?: $key));
            $asset = $this->assets->find($kind, $key) ?? $this->assets->placeholder($kind, $key);

            return [
                'kind' => $kind,
                'key' => $key,
                'name' => $name,
                'level' => $definition['level'] && isset($row->level) ? (int) $row->level : null,
                'source_revision' => (string) $row->source_revision,
                'supply_limit' => $row->supply_limit === null ? null : (int) $row->supply_limit,
                'issued' => (int) $row->issued,
                'asset' => $asset,
                'label' => $definition['level'] && isset($row->level)
                    ? sprintf('%s · Lv. %d · %s', $name, (int) $row->level, $key)
                    : sprintf('%s · %s', $name, $key),
            ];
        })->all();
    }

    public function content(string $kind, string $key): ?array
    {
        $definition = self::CONTENT[$kind] ?? null;

        if ($definition === null || $key === '') {
            return null;
        }

        $table = $definition['table'];
        $column = $definition['key'];
        $columns = [
            'c.content_key',
            'c.title as catalog_title',
            'c.source_revision',
            'c.supply_limit',
            'c.issued',
            'd.name as definition_name',
        ];

        if ($definition['level']) {
            $columns[] = 'd.level';
        }

        $row = DB::table('nl_catalog as c')
            ->leftJoin($table.' as d', 'd.'.$column, '=', 'c.content_key')
            ->where('c.kind', $kind)
            ->where('c.content_key', $key)
            ->where('c.enabled', true)
            ->first($columns);

        if (! $row) {
            return null;
        }

        $name = trim((string) ($row->definition_name ?: $row->catalog_title ?: $key));

        return [
            'kind' => $kind,
            'key' => $key,
            'name' => $name,
            'level' => $definition['level'] && isset($row->level) ? (int) $row->level : null,
            'source_revision' => (string) $row->source_revision,
            'supply_limit' => $row->supply_limit === null ? null : (int) $row->supply_limit,
            'issued' => (int) $row->issued,
            'asset' => $this->assets->find($kind, $key) ?? $this->assets->placeholder($kind, $key),
        ];
    }

    private function limit(int $limit): int
    {
        return max(1, min(50, $limit));
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
