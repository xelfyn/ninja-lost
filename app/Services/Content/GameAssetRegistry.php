<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\File;

/**
 * Read-only registry for original/imported game visuals.
 *
 * This service is deliberately fail-closed:
 * - no AI/generated substitute is returned for missing content;
 * - no gameplay mechanics are inferred from visual files;
 * - only files inside the configured public asset root are exposed;
 * - source SWFs remain import inputs, not directly served web assets.
 */
final class GameAssetRegistry
{
    private ?array $manifest = null;

    public function find(string $kind, string $sourceId): ?array
    {
        if (! preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $kind)) {
            return null;
        }

        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}\z/D', $sourceId)) {
            return null;
        }

        $manifest = $this->manifest();
        $record = $manifest['assets'][$kind][$sourceId] ?? null;

        if (! is_array($record)) {
            return null;
        }

        $webPath = $record['web_path'] ?? null;

        if (! is_string($webPath) || ! $this->safeRelativePath($webPath)) {
            return $this->withoutUrl($record, 'missing');
        }

        $extension = strtolower(pathinfo($webPath, PATHINFO_EXTENSION));
        $allowed = config('ninja_assets.web_extensions', []);

        if (! in_array($extension, $allowed, true)) {
            return $this->withoutUrl($record, 'unsupported');
        }

        $root = realpath((string) config('ninja_assets.public_root'));

        if ($root === false) {
            return $this->withoutUrl($record, 'missing');
        }

        $requested = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $webPath);

        // Never serve aliases. Besides traversal concerns, a .png symlink can
        // otherwise point at SWF bytes while passing an extension-only check.
        if (is_link($requested)) {
            return $this->withoutUrl($record, 'unsupported');
        }

        $candidate = realpath($requested);

        if ($candidate === false || ! str_starts_with($candidate, $root.DIRECTORY_SEPARATOR) || ! is_file($candidate)) {
            return $this->withoutUrl($record, 'missing');
        }

        if (! $this->matchesWebSignature($candidate, $extension)) {
            return $this->withoutUrl($record, 'unsupported');
        }

        $prefix = rtrim((string) config('ninja_assets.public_prefix', '/game-assets'), '/');

        return array_merge($record, [
            'visual_status' => $record['visual_status'] ?? 'available',
            'url' => $prefix.'/'.str_replace('%2F', '/', rawurlencode($webPath)),
        ]);
    }

    public function placeholder(string $kind, string $sourceId): array
    {
        return [
            'kind' => $kind,
            'source_id' => $sourceId,
            'visual_status' => 'missing',
            'url' => null,
        ];
    }

    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $path = (string) config('ninja_assets.manifest');

        if ($path === '' || ! File::isFile($path)) {
            return $this->manifest = ['schema_version' => 1, 'assets' => []];
        }

        try {
            $decoded = json_decode(File::get($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $this->manifest = ['schema_version' => 1, 'assets' => []];
        }

        if (! is_array($decoded)
            || ($decoded['schema_version'] ?? null) !== 1
            || ! is_array($decoded['assets'] ?? null)) {
            return $this->manifest = ['schema_version' => 1, 'assets' => []];
        }

        return $this->manifest = $decoded;
    }

    private function safeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            return false;
        }

        if (str_starts_with($path, '/') || str_contains($path, '../') || str_contains($path, '/..')) {
            return false;
        }

        return preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.\/-]{0,255}\z/D', $path) === 1;
    }

    private function matchesWebSignature(string $path, string $extension): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            $header = fread($handle, 12);
        } finally {
            fclose($handle);
        }

        if (! is_string($header)) {
            return false;
        }

        return match ($extension) {
            'png' => str_starts_with($header, "\x89PNG\r\n\x1a\n"),
            'jpg', 'jpeg' => str_starts_with($header, "\xFF\xD8\xFF"),
            'webp' => strlen($header) >= 12
                && substr($header, 0, 4) === 'RIFF'
                && substr($header, 8, 4) === 'WEBP',
            default => false,
        };
    }

    private function withoutUrl(array $record, string $status): array
    {
        $record['visual_status'] = $status;
        $record['url'] = null;

        return $record;
    }
}
