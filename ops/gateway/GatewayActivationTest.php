<?php

use App\Evaluation\GatewayAccess;
use Illuminate\Foundation\Testing\TestCase;

/** Positive activation proof prevents all-404 negative tests from masking a disabled route set. */
final class GatewayActivationTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }
    public function test_literal_environment_opt_in_registers_exact_diagnostic_routes(): void
    {
        $this->assertSame('true', getenv('NL_EVALUATION_GATEWAY'));
        $this->assertTrue(config('evaluation_gateway.enabled'));
        $this->assertTrue(GatewayAccess::enabled());
        $routes = collect(app('router')->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'evaluation/v1'));
        $this->assertCount(12, $routes);
        $this->assertTrue($routes->contains(fn ($route) => $route->uri() === 'evaluation/v1/bootstrap'));
        foreach ($routes as $route) { $this->assertContains('web', $route->gatherMiddleware()); }
    }
}
