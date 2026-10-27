<?php

declare(strict_types=1);
namespace App\Support\NSX;

final class PreviewOrigin
{
    public static function resolve(string $environment, mixed $enabled, mixed $name, mixed $domain, string $configured): ?string
    {
        if ($environment !== 'local' || !in_array($enabled, [true, 'true'], true)) {
            return null;
        }
        if (!is_string($name) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $name) || $domain !== 'app.github.dev') {
            throw new \LogicException('Unrecognized Codespaces host; preview refused');
        }
        $expected = 'https://'.$name.'-8000.'.$domain;
        if ($configured !== $expected) {
            throw new \LogicException('Preview URL mismatch; rerun preview environment setup');
        }
        return $expected;
    }
}
