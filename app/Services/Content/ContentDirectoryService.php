<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ContentDirectoryService
{
    private const CONTENT = [
        'item' => ['table' => 'items', 'key' => 'item_id', 'level' => true, 'category' => true],
        'skill' => ['table' => 'skills', 'key' => 'skill_id', 'level' => true, 'category' => false],
        'pet' => ['table' => 'pets', 'key' => 'pet_id', 'level' => false, 'category' => false],
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
        $definition = $this->definition($kind);
        $query = trim($query);
        $limit = $this->limit($limit);

        $builder = $this->contentQuery($kind, $definition);

        if ($query !== '') {
            $like = '%'.$this->escapeLike($query).'%';
            $builder->where(function ($q) use ($like) {
                $q->where('c.title', 'like', $like)
                    ->orWhere('c.content_key', 'like', $like)
                    ->orWhere('d.name', 'like', $like);
            });
        }

        $rows = $builder
            ->orderByRaw('COALESCE(d.name, c.title) asc')
            ->orderBy('c.content_key')
            ->limit($limit)
            ->get($this->contentColumns($definition));

        return $rows
            ->map(fn ($row) => $this->contentRow($kind, $definition, $row))
            ->all();
    }

    public function content(string $kind, string $key): ?array
    {
        if ($key === '') {
            return null;
        }

        $definition = $this->definition($kind);

        $row = $this->contentQuery($kind, $definition)
            ->where('c.content_key', $key)
            ->first($this->contentColumns($definition));

        return $row ? $this->contentRow($kind, $definition, $row) : null;
    }

    private function definition(string $kind): array
    {
        return self::CONTENT[$kind]
            ?? throw new InvalidArgumentException('Unsupported content kind.');
    }

    private function contentQuery(string $kind, array $definition)
    {
        return DB::table('nl_catalog as c')
            ->leftJoin($definition['table'].' as d', 'd.'.$definition['key'], '=', 'c.content_key')
            ->where('c.kind', $kind)
            ->where('c.enabled', true);
    }

    private function contentColumns(array $definition): array
    {
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

        if ($definition['category']) {
            $columns[] = 'd.category as definition_category';
        }

        return $columns;
    }

    private function contentRow(string $kind, array $definition, object $row): array
    {
        $key = (string) $row->content_key;
        $name = trim((string) ($row->definition_name ?: $row->catalog_title ?: $key));
        $category = $definition['category'] && isset($row->definition_category)
            ? trim((string) $row->definition_category)
            : null;
        $assetKind = $this->assetKind($kind, $category);
        $asset = $this->assets->find($assetKind, $key)
            ?? $this->assets->placeholder($assetKind, $key);
        $level = $definition['level'] && isset($row->level) ? (int) $row->level : null;

        return [
            'kind' => $kind,
            'category' => $category,
            'key' => $key,
            'name' => $name,
            'level' => $level,
            'source_revision' => (string) $row->source_revision,
            'supply_limit' => $row->supply_limit === null ? null : (int) $row->supply_limit,
            'issued' => (int) $row->issued,
            'asset_kind' => $assetKind,
            'asset' => $asset,
            'label' => $level !== null
                ? sprintf('%s · Lv. %d · %s', $name, $level, $key)
                : sprintf('%s · %s', $name, $key),
        ];
    }

    private function assetKind(string $kind, ?string $category): string
    {
        if ($kind !== 'item') {
            return $kind;
        }

        return match (strtolower((string) $category)) {
            'weapon', 'wpn' => 'weapon',
            'clothing', 'set', 'bodyset', 'body_set' => 'clothing',
            'back', 'backitem', 'back_item' => 'back',
            'accessory', 'acsy' => 'accessory',
            'hair' => 'hair',
            default => 'item',
        };
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
