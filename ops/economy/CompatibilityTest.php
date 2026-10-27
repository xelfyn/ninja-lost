<?php

use App\Filament\Pages\StaffGrants;
use App\Support\Economy\EvaluationSurface;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Livewire\Livewire as LivewireTest;
use NinjaLost\QA\Fixture;
require_once __DIR__.'/../rewards/Fixture.php';

final class CompatibilityTest extends TestCase
{
    use DatabaseTransactions;
    public function createApplication()
    {
        $app=require dirname(__DIR__,2).'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); return $app;
    }
    protected function setUp():void
    {
        parent::setUp(); Fixture::guard(); Filament::setCurrentPanel(Filament::getPanel('admin'));
    }
    public function test_signed_legacy_snapshot_is_rejected_on_actual_hydration():void
    {
        $this->actingAs(Fixture::actor(99));
        // Test-only alias simulates a formerly registered component; no alias is published.
        LivewireTest::component('qa-legacy-world-chat',App\Filament\Pages\WorldChat::class);
        $component=app('livewire')->new('qa-legacy-world-chat');
        $handler=app(Livewire\Mechanisms\HandleComponents\HandleComponents::class);
        $context=new Livewire\Mechanisms\HandleComponents\ComponentContext($component);
        $context->addMemo('release',Livewire\Features\SupportReleaseTokens\ReleaseToken::generate($component));
        $snapshot=$handler->snapshot($component,$context);
        $this->assertNotEmpty($snapshot['checksum']);
        // Verify the preceding framework checks independently. The expected 403 must be ours.
        Livewire\Mechanisms\HandleComponents\Checksum::verify($snapshot);
        Livewire\Features\SupportReleaseTokens\ReleaseToken::verify($snapshot);
        try {
            $handler->update($snapshot,[],[['method'=>'$refresh','params'=>[]]]);
            $this->fail('A valid old identity must not escape quarantine during hydration');
        } catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            $this->assertSame(403,$e->getStatusCode());
            $this->assertStringContainsString('dikarantina',$e->getMessage());
        }
    }
    public function test_locked_staff_identity_cannot_be_changed_by_client():void
    {
        $this->actingAs(Fixture::actor(99)); $page=LivewireTest::test(StaffGrants::class);
        $this->expectException(Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        $page->set('requestKey',(string)Illuminate\Support\Str::uuid());
    }
    public function test_explicit_resource_surface_and_no_legacy_controller_route():void
    {
        $this->assertEqualsCanonicalizing(EvaluationSurface::RESOURCES,Filament::getPanel('admin')->getResources());
        foreach (app('router')->getRoutes() as $route) {
            $this->assertFalse(str_starts_with($route->getActionName(),'App\\Http\\Controllers\\'),$route->uri().' exposed a legacy controller');
        }
    }
}
