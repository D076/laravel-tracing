<?php

namespace D076\Tracing\Providers;

use D076\Tracing\Context\TracingContext;
use D076\Tracing\Context\TraceId;
use D076\Tracing\Http\Middleware\TracingAuthMiddleware;
use D076\Tracing\Middleware\TracingMiddleware;
use D076\Tracing\Middleware\OutgoingTracingMiddleware;
use D076\Tracing\Middleware\TraceIdMiddleware;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class TracingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/tracing.php', 'tracing');

        $this->app->singleton(TraceId::class);
        $this->app->singleton(TracingContext::class);

        // respondUsing вызывается из finalizeRenderedResponse() для ВСЕХ исключений,
        // включая HttpException (404, 403, 429), которые reportable() пропускает
        // из-за shouldntReport(). Это единственный хук, гарантирующий захват любого
        // исключения вне зависимости от dontReport и порядка renderable-callbacks.
        $this->app->afterResolving(
            Handler::class,
            function (Handler $handler): void {
                $app = $this->app;

                $handler->respondUsing(function (
                    Response $response,
                    Throwable $e,
                    Request $request,
                ) use ($app): Response {
                    if (config('tracing.enabled', true)) {
                        $app->make(TracingContext::class)->exception = $e;
                    }

                    return $response;
                });
            }
        );
    }

    public function boot(): void
    {
        // prependMiddleware добавляет в начало стека, поэтому вызываем в обратном порядке:
        // сначала Tracing (окажется вторым), затем TraceId (окажется первым).
        /** @var HttpKernel $kernel */
        $kernel = $this->app->make(Kernel::class);
        $kernel->prependMiddleware(TracingMiddleware::class)
            ->prependMiddleware(TraceIdMiddleware::class);

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->publishes([
            __DIR__ . '/../../config/tracing.php' => config_path('tracing.php'),
        ], 'tracing-config');

        // Динамически добавляем UI-путь в ignore_paths на случай,
        // если пользователь сменил TRACING_UI_PATH
        $uiPath = config('tracing.ui.path', 'tracing');
        config(['tracing.ignore_paths' => array_unique(array_merge(
            config('tracing.ignore_paths', []),
            [$uiPath, $uiPath . '/*'],
        ))]);

        if (config('tracing.outgoing.enabled', true)) {
            Http::globalMiddleware($this->app->make(OutgoingTracingMiddleware::class));
        }

        if (config('tracing.ui.enabled', true)) {
            $this->bootUi();
        }
    }

    private function bootUi(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'tracing');

        // Определяем gate только если он ещё не задан (позволяет переопределить в AppServiceProvider)
        if (!Gate::has('viewTracing')) {
            Gate::define('viewTracing', fn($user = null) => $this->app->isLocal());
        }

        $this->app->make(Router::class)
            ->aliasMiddleware('tracing.auth', TracingAuthMiddleware::class);

        Route::prefix(config('tracing.ui.path', 'tracing'))
            ->middleware(config('tracing.ui.middleware', ['web']))
            ->name('tracing.')
            ->group(__DIR__ . '/../Http/routes.php');
    }
}
