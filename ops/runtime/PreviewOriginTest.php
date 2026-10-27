<?php
use App\Support\NSX\PreviewOrigin;
use PHPUnit\Framework\TestCase;
final class PreviewOriginTest extends TestCase
{
    public function test_regular_ci_is_unchanged(): void { $this->assertNull(PreviewOrigin::resolve('testing', false, null, 'app.github.dev', 'http://localhost')); }
    public function test_production_is_not_rewritten(): void { $this->assertNull(PreviewOrigin::resolve('production', true, 'ninja-123', 'app.github.dev', 'https://example.invalid')); }
    public function test_valid_private_preview_origin(): void { $this->assertSame('https://ninja-123-8000.app.github.dev', PreviewOrigin::resolve('local', true, 'ninja-123', 'app.github.dev', 'https://ninja-123-8000.app.github.dev')); }
    public function test_foreign_domain_is_rejected(): void { $this->expectException(LogicException::class); PreviewOrigin::resolve('local', true, 'ninja-123', 'evil.invalid', 'https://evil.invalid'); }
    public function test_mismatch_is_rejected(): void { $this->expectException(LogicException::class); PreviewOrigin::resolve('local', true, 'ninja-123', 'app.github.dev', 'http://localhost'); }
    public function test_injected_name_is_rejected(): void { $this->expectException(LogicException::class); PreviewOrigin::resolve('local', true, 'ninja/123', 'app.github.dev', 'https://example.invalid'); }
}
