<?php

use D076\Tracing\Providers\TracingServiceProvider;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Orchestra\Testbench\Concerns\CreatesApplication;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

/**
 * Measures per-request overhead added by d076/laravel-tracing.
 *
 * Run: docker compose run --rm test ./vendor/bin/phpbench run --report=aggregate
 */
#[Iterations(5), Warmup(2)]
class TracingOverheadBench
{
    use CreatesApplication;

    private HttpKernel $kernel;

    private array $configOverrides = [];

    // ── testbench hooks ────────────────────────────────────────────────────

    protected function getPackageProviders($app): array
    {
        return [TracingServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        $app['config']->set('queue.default', 'sync');

        foreach ($this->configOverrides as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    // ── shared boot ────────────────────────────────────────────────────────

    private function boot(array $config = []): void
    {
        $this->configOverrides = $config;

        \Illuminate\Support\Facades\Facade::clearResolvedInstances();

        $app = $this->createApplication();

        $app->make(\Illuminate\Contracts\Console\Kernel::class)->call('migrate');

        $app->make(\Illuminate\Routing\Router::class)
            ->get('/bench', fn () => response()->json(['status' => 'ok']));

        $this->kernel = $app->make(HttpKernel::class);
    }

    // ── per-subject setup ──────────────────────────────────────────────────

    public function setUpBaseline(): void
    {
        $this->boot(['tracing.enabled' => false]);
    }

    public function setUpSync(): void
    {
        $this->boot([
            'tracing.enabled'             => true,
            'tracing.driver'              => 'database',
            'tracing.store_response_body' => false,
        ]);
    }

    public function setUpQueue(): void
    {
        $this->boot([
            'tracing.enabled'             => true,
            'tracing.driver'              => 'queue',
            'tracing.store_response_body' => false,
        ]);
    }

    public function setUpSyncWithBody(): void
    {
        $this->boot([
            'tracing.enabled'             => true,
            'tracing.driver'              => 'database',
            'tracing.store_response_body' => true,
        ]);
    }

    // ── subjects ───────────────────────────────────────────────────────────

    #[Revs(50), BeforeMethods('setUpBaseline')]
    public function benchBaseline(): void
    {
        $this->handle();
    }

    #[Revs(50), BeforeMethods('setUpSync')]
    public function benchSyncDatabase(): void
    {
        $this->handle();
    }

    #[Revs(50), BeforeMethods('setUpQueue')]
    public function benchQueue(): void
    {
        $this->handle();
    }

    #[Revs(50), BeforeMethods('setUpSyncWithBody')]
    public function benchSyncWithResponseBody(): void
    {
        $this->handle();
    }

    // ── shared request cycle ───────────────────────────────────────────────

    private function handle(): void
    {
        $request  = Request::create('/bench', 'GET');
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);
    }
}
