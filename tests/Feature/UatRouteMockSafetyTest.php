<?php

namespace Tests\Feature;

use App\Http\Controllers\Mock\HikvisionMockController;
use App\Services\HikvisionIsapiService;
use App\Support\DatabaseSafetyGuard;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

final class UatRouteMockSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Baseline clean environment
        App::detectEnvironment(fn () => 'testing');
        Config::set('database.default', 'pgsql');
        Config::set('database.connections.pgsql.driver', 'pgsql');
        Config::set('database.connections.pgsql.database', 'securegate_test');
    }

    /**
     * Helper to load routes under an isolated Router instance for a specific environment and mock flag.
     * Accepts boolean, string, or mixed value for mockEnabled to test string evaluation.
     */
    private function resolveIsolatedApiRoutesFor(string $env, mixed $mockEnabled): RouteCollection
    {
        App::detectEnvironment(fn () => $env);
        Config::set('services.hikvision.use_mock', $mockEnabled);

        $isolatedRouter = new Router($this->app['events'], $this->app);

        // Bind the isolated router temporarily to the Route facade
        Route::swap($isolatedRouter);

        try {
            $isolatedRouter->middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            return $isolatedRouter->getRoutes();
        } finally {
            // Restore default router to avoid side-effects on test kernel
            Route::swap($this->app['router']);
            App::detectEnvironment(fn () => 'testing');
        }
    }

    /**
     * Requirement 8 & 9: Route mock tidak tersedia pada production,
     * bahkan jika konfigurasi use_mock bernilai true.
     */
    public function test_mock_routes_are_never_registered_in_production(): void
    {
        // 1. Production with mock disabled
        $routesWithoutMock = $this->resolveIsolatedApiRoutesFor('production', false);
        $this->assertFalse(
            $this->hasMockIsapiRoute($routesWithoutMock),
            'Mock routes must not exist in production when mock is disabled.'
        );

        // 2. Production with mock enabled (must still be blocked fail-closed)
        $routesWithMock = $this->resolveIsolatedApiRoutesFor('production', true);
        $this->assertFalse(
            $this->hasMockIsapiRoute($routesWithMock),
            'Mock routes must NEVER be registered in production even if use_mock is true.'
        );
    }

    /**
     * Requirement 8: Route mock di UAT hanya tersedia jika use_mock secara eksplisit aktif.
     */
    public function test_mock_routes_in_uat_require_explicit_mock_flag(): void
    {
        // UAT with use_mock = false -> routes must NOT be registered
        $uatDisabledRoutes = $this->resolveIsolatedApiRoutesFor('uat', false);
        $this->assertFalse(
            $this->hasMockIsapiRoute($uatDisabledRoutes),
            'Mock routes must NOT be registered in UAT when services.hikvision.use_mock is false.'
        );

        // UAT with use_mock = true -> routes ARE registered
        $uatEnabledRoutes = $this->resolveIsolatedApiRoutesFor('uat', true);
        $this->assertTrue(
            $this->hasMockIsapiRoute($uatEnabledRoutes),
            'Mock routes must be registered in UAT when services.hikvision.use_mock is true.'
        );
    }

    /**
     * Security Review 2: FILTER_VALIDATE_BOOLEAN behavior
     * Strings like "false", "off", "no", "0" must evaluate to FALSE and NOT register mock routes.
     * Strings like "true", "on", "yes", "1" must evaluate to TRUE and register mock routes.
     */
    public function test_mock_flag_evaluates_boolean_strings_robustly_via_filter_var(): void
    {
        foreach (['false', 'off', 'no', '0', 0] as $falsyValue) {
            $routes = $this->resolveIsolatedApiRoutesFor('uat', $falsyValue);
            $this->assertFalse(
                $this->hasMockIsapiRoute($routes),
                "Falsy value [{$falsyValue}] must NOT activate mock routes in UAT."
            );
        }

        foreach (['true', 'on', 'yes', '1', 1] as $truthyValue) {
            $routes = $this->resolveIsolatedApiRoutesFor('uat', $truthyValue);
            $this->assertTrue(
                $this->hasMockIsapiRoute($routes),
                "Truthy value [{$truthyValue}] must activate mock routes in UAT."
            );
        }
    }

    /**
     * HikvisionIsapiService::isMockMode() evaluates FILTER_VALIDATE_BOOLEAN properly.
     */
    public function test_is_mock_mode_service_evaluates_filter_validate_boolean(): void
    {
        $service = new HikvisionIsapiService();

        Config::set('services.hikvision.use_mock', 'false');
        $this->assertFalse($service->isMockMode());

        Config::set('services.hikvision.use_mock', 'off');
        $this->assertFalse($service->isMockMode());

        Config::set('services.hikvision.use_mock', 'true');
        $this->assertTrue($service->isMockMode());

        Config::set('services.hikvision.use_mock', true);
        $this->assertTrue($service->isMockMode());
    }

    /**
     * Network Isolation: In UAT environment, outbound HTTP calls to physical devices are strictly forbidden.
     */
    public function test_uat_environment_strictly_prohibits_outbound_http_calls_to_physical_hardware(): void
    {
        App::detectEnvironment(fn () => 'uat');

        $service = new class extends HikvisionIsapiService {
            public function callBuildHttpClient(): void
            {
                $this->buildHttpClient();
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FATAL NETWORK SAFETY VIOLATION: [uat] environment is strictly forbidden from initiating outbound TCP connections');

        $service->callBuildHttpClient();
    }

    /**
     * Requirement 8: Route mock tersedia di testing environment untuk test suite.
     */
    public function test_mock_routes_are_registered_in_testing_environment(): void
    {
        $testingRoutes = $this->resolveIsolatedApiRoutesFor('testing', false);
        $this->assertTrue(
            $this->hasMockIsapiRoute($testingRoutes),
            'Mock routes should be registered in testing environment for automated test suites.'
        );
    }

    /**
     * Requirement 10: Endpoint operasional pintu (/api/v1/doors/*) tetap terlindungi auth:sanctum
     * dan tidak dilewati atau dibocorkan oleh mock routes.
     */
    public function test_door_operational_endpoints_remain_strictly_authenticated(): void
    {
        // Unauthenticated attempt to unlock door must return 401 Unauthorized
        $responseUnlock = $this->postJson('/api/v1/doors/DOOR-01/unlock', []);
        $responseUnlock->assertStatus(401);

        // Unauthenticated attempt to open door via admin endpoint must return 401 Unauthorized
        $responseOpen = $this->postJson('/api/v1/admin/doors/DOOR-01/open', []);
        $responseOpen->assertStatus(401);
    }

    /**
     * Requirement 10: Mock controller actions return simulated contract data
     * without exposing database credentials or physical hardware connections.
     */
    public function test_mock_controller_returns_isolated_simulation_data(): void
    {
        $response = $this->getJson('/api/mock/isapi/System/status');
        $response->assertStatus(200)
            ->assertJson([
                'status' => 'OK',
                'online' => true,
                'doorStatus' => 'closed',
            ]);
    }

    /**
     * Helper to check if any mock/isapi URI exists in a RouteCollection.
     */
    private function hasMockIsapiRoute(RouteCollection $routes): bool
    {
        foreach ($routes->getRoutes() as $route) {
            if (str_contains($route->uri(), 'mock/isapi')) {
                return true;
            }
        }
        return false;
    }
}
