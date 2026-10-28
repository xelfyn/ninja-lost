<?php

use App\Services\Content\GameAssetRegistry;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\File;

final class GameAssetRegistryTest extends TestCase
{
    private string $root;
    private string $publicRoot;
    private string $manifest;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/nl-asset-registry-'.bin2hex(random_bytes(8));
        $this->publicRoot = $this->root.'/public';
        $this->manifest = $this->root.'/manifest.json';

        File::makeDirectory($this->publicRoot, 0700, true);

        config([
            'ninja_assets.manifest' => $this->manifest,
            'ninja_assets.public_root' => $this->publicRoot,
            'ninja_assets.public_prefix' => '/game-assets',
            'ninja_assets.web_extensions' => ['png', 'webp', 'jpg', 'jpeg'],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function writeManifest(array $assets): void
    {
        File::put($this->manifest, json_encode([
            'schema_version' => 1,
            'assets' => $assets,
        ], JSON_THROW_ON_ERROR));
    }

    private function registry(): GameAssetRegistry
    {
        return new GameAssetRegistry();
    }

    public function test_reviewed_web_derivative_is_resolved_inside_public_root(): void
    {
        File::makeDirectory($this->publicRoot.'/skills', 0700, true);
        File::put($this->publicRoot.'/skills/skill_1.png', 'reviewed-png');

        $this->writeManifest([
            'skill' => [
                'skill_1' => [
                    'source_id' => 'skill_1',
                    'source_type' => 'ninja-saga-swf',
                    'source_ref' => 'swf/skills/skill_01.swf',
                    'web_path' => 'skills/skill_1.png',
                    'visual_status' => 'verified',
                ],
            ],
        ]);

        $asset = $this->registry()->find('skill', 'skill_1');

        $this->assertSame('verified', $asset['visual_status']);
        $this->assertSame('/game-assets/skills/skill_1.png', $asset['url']);
        $this->assertSame('swf/skills/skill_01.swf', $asset['source_ref']);
    }

    public function test_missing_derivative_fails_closed_without_generated_substitute(): void
    {
        $this->writeManifest([
            'skill' => [
                'skill_2' => [
                    'source_id' => 'skill_2',
                    'web_path' => 'skills/skill_2.png',
                    'visual_status' => 'verified',
                ],
            ],
        ]);

        $asset = $this->registry()->find('skill', 'skill_2');

        $this->assertSame('missing', $asset['visual_status']);
        $this->assertNull($asset['url']);
    }

    public function test_swf_is_never_exposed_as_web_visual(): void
    {
        File::put($this->publicRoot.'/skill_3.swf', 'FWS-test');

        $this->writeManifest([
            'skill' => [
                'skill_3' => [
                    'source_id' => 'skill_3',
                    'web_path' => 'skill_3.swf',
                ],
            ],
        ]);

        $asset = $this->registry()->find('skill', 'skill_3');

        $this->assertSame('unsupported', $asset['visual_status']);
        $this->assertNull($asset['url']);
    }

    public function test_traversal_and_invalid_identifiers_are_refused(): void
    {
        $this->writeManifest([
            'skill' => [
                'skill_4' => [
                    'source_id' => 'skill_4',
                    'web_path' => '../outside.png',
                ],
            ],
        ]);

        $asset = $this->registry()->find('skill', 'skill_4');

        $this->assertSame('missing', $asset['visual_status']);
        $this->assertNull($asset['url']);
        $this->assertNull($this->registry()->find('../skill', 'skill_4'));
        $this->assertNull($this->registry()->find('skill', '../skill_4'));
    }

    public function test_symlink_escape_is_not_served(): void
    {
        $outside = $this->root.'/outside.png';
        File::put($outside, 'outside');
        $link = $this->publicRoot.'/linked.png';

        if (! @symlink($outside, $link)) {
            $this->markTestSkipped('symlink unavailable');
        }

        $this->writeManifest([
            'skill' => [
                'skill_5' => [
                    'source_id' => 'skill_5',
                    'web_path' => 'linked.png',
                ],
            ],
        ]);

        $asset = $this->registry()->find('skill', 'skill_5');

        $this->assertSame('missing', $asset['visual_status']);
        $this->assertNull($asset['url']);
    }

    public function test_malformed_manifest_behaves_as_empty_registry(): void
    {
        File::put($this->manifest, '{not-json');

        $this->assertNull($this->registry()->find('skill', 'skill_1'));
        $this->assertSame([
            'kind' => 'skill',
            'source_id' => 'skill_1',
            'visual_status' => 'missing',
            'url' => null,
        ], $this->registry()->placeholder('skill', 'skill_1'));
    }
}
