<?php

namespace D076\Tracing\Providers;

use D076\Tracing\Context\TracingContext;
use D076\Tracing\Context\TraceId;
use D076\Tracing\Context\Tags;
use D076\Tracing\Http\Middleware\TracingAuthMiddleware;
use D076\Tracing\Listeners\RecordOutgoingRequest;
use D076\Tracing\Middleware\TracingMiddleware;
use D076\Tracing\Middleware\OutgoingTracingMiddleware;
use D076\Tracing\Middleware\TraceIdMiddleware;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class TracingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeTracingConfig();

        $this->app->singleton(TraceId::class);
        $this->app->singleton(Tags::class);
        $this->app->singleton(TracingContext::class);

        // Singleton обязателен: слушатель хранит время старта запроса между
        // событиями RequestSending → ResponseReceived/ConnectionFailed.
        $this->app->singleton(RecordOutgoingRequest::class);

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
                    if (config('tracing.enabled')) {
                        $app->make(TracingContext::class)->exception = $e;
                    }

                    return $response;
                });
            }
        );
    }

    public function boot(): void
    {
        // Install-time регистрации доступны ВСЕГДА, независимо от мастер-выключателя:
        // иначе TRACING_ENABLED=false молча убрал бы таблицы из `migrate`, а повторное
        // включение требовало бы вручную вспоминать про миграции.
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->publishes([
            __DIR__ . '/../../config/tracing.php' => config_path('tracing.php'),
        ], 'tracing-config');

        // Мастер-выключатель. TRACING_ENABLED=false полностью глушит рантайм пакета:
        // ни middleware/Context (trace_id перестаёт течь в логи приложения), ни записи
        // исходящих, ни UI-роутов. Всё, что ниже, — рантайм-поведение.
        if (!config('tracing.enabled')) {
            return;
        }

        // prependMiddleware добавляет в начало стека, поэтому вызываем в обратном порядке:
        // сначала Tracing (окажется вторым), затем TraceId (окажется первым).
        /** @var HttpKernel $kernel */
        $kernel = $this->app->make(Kernel::class);
        $kernel->prependMiddleware(TracingMiddleware::class)
            ->prependMiddleware(TraceIdMiddleware::class);

        // Динамически добавляем UI-путь в ignore_paths на случай,
        // если пользователь сменил TRACING_UI_PATH
        $uiPath = config('tracing.ui.path');
        config(['tracing.ignore_paths' => array_unique(array_merge(
            config('tracing.ignore_paths'),
            [$uiPath, $uiPath . '/*'],
        ))]);

        if (config('tracing.outgoing.enabled')) {
            // Middleware — только инъекция X-Trace-Id (мутация запроса).
            Http::globalMiddleware($this->app->make(OutgoingTracingMiddleware::class));

            // Запись — на событиях клиента: гарантированно ловят 4xx/5xx и обрыв коннекта.
            Event::listen(RequestSending::class, [RecordOutgoingRequest::class, 'handleRequestSending']);
            Event::listen(ResponseReceived::class, [RecordOutgoingRequest::class, 'handleResponseReceived']);
            Event::listen(ConnectionFailed::class, [RecordOutgoingRequest::class, 'handleConnectionFailed']);
        }

        if (config('tracing.ui.enabled')) {
            $this->bootUi();
        }
    }

    private function bootUi(): void
    {
        $this->registerRateLimiter();

        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'tracing');

        // Определяем gate только если он ещё не задан (позволяет переопределить в AppServiceProvider)
        if (!Gate::has('viewTracing')) {
            Gate::define('viewTracing', fn($user = null) => $this->app->isLocal());
        }

        $this->app->make(Router::class)
            ->aliasMiddleware('tracing.auth', TracingAuthMiddleware::class);

        Route::prefix(config('tracing.ui.path'))
            ->middleware(config('tracing.ui.middleware'))
            ->name('tracing.')
            ->group(__DIR__ . '/../Http/routes.php');
    }

    private function registerRateLimiter(): void
    {
        // Не перезаписываем limiter, если приложение определило свой 'tracing-api'.
        if (RateLimiter::limiter('tracing-api') !== null) {
            return;
        }

        RateLimiter::for('tracing-api', function (Request $request): Limit {
            if (!config('tracing.rate_limit.enabled')) {
                return Limit::none();
            }

            $user = $request->user();
            // Ключ учитывает полиморфный тип: Admin#1 и Customer#1 — разные бакеты.
            $key = $user !== null
                ? $user->getMorphClass() . ':' . $user->getKey()
                : (string) $request->ip();

            return Limit::perMinutes(
                (int) config('tracing.rate_limit.decay_minutes'),
                (int) config('tracing.rate_limit.max_attempts'),
            )->by($key);
        });
    }

    /**
     * Replacement for ServiceProvider::mergeConfigFrom(). That method does a
     * flat array_merge(), so a nested array key in a *published* config —
     * anything under outgoing/ui/rate_limit/tags — replaces the whole
     * sub-array wholesale instead of merging key-by-key. A config published
     * with `vendor:publish --tag=tracing-config` on an older package version
     * would then read a key added since as null, and some of those nulls
     * fail hard rather than degrade: `Route::middleware(null)`,
     * `Limit::perMinutes(0, 0)` (a permanent 429), `foreach (null as ...)`.
     *
     * replaceConfigRecursivelyFrom() (array_replace_recursive) is not the fix
     * either: it merges list values element-by-element by numeric index, so
     * a user publishing a *shorter* ignore_paths/only_paths than the
     * package's own could never shrink it — the package's trailing elements
     * would survive at their original indexes.
     *
     * So: top-level keys are filled in when the user's published config lacks
     * them, exactly like mergeConfigFrom(). One level of *associative*
     * sub-array (outgoing, ui, rate_limit, tags) is filled in key-by-key for
     * the same reason a top-level key is. A *list* value (array_is_list())
     * is always taken from the published config as a whole, never merged
     * element-by-element. The user's own value always wins.
     */
    private function mergeTracingConfig(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');

        /** @var array<string, mixed> $defaults */
        $defaults = require __DIR__ . '/../../config/tracing.php';
        /** @var array<string, mixed> $published */
        $published = $config->get('tracing', []);

        $merged = $defaults;

        foreach ($published as $key => $value) {
            $merged[$key] = is_array($value)
                && !array_is_list($value)
                && isset($merged[$key])
                && is_array($merged[$key])
                && !array_is_list($merged[$key])
                ? array_merge($merged[$key], $value)
                : $value;
        }

        $config->set('tracing', $merged);
    }
}
