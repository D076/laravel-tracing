<?php

use D076\Tracing\Models\TracingRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('tracing.enabled', true);
    config()->set('tracing.driver', 'database');
    config()->set('tracing.ignore_paths', []);
});

/**
 * The exception is captured through the handler's respondUsing() hook rather
 * than a reportable/renderable callback, precisely so that the exceptions the
 * framework declines to report (HttpException: 404, 403, 422) are recorded too.
 * That distinction is what these cases exist to hold.
 */
describe('exception capture on incoming requests', function () {
    it('records an unhandled exception with its class, message and location', function () {
        Route::get('/boom', function () {
            throw new RuntimeException('kaboom');
        });

        $this->get('/boom')->assertStatus(500);

        $record = TracingRequest::firstOrFail();

        expect($record->response_status)->toBe(500)
            ->and($record->exception['class'])->toBe(RuntimeException::class)
            ->and($record->exception['message'])->toBe('kaboom')
            ->and($record->exception['file'])->toContain('RecordsExceptionTest.php')
            ->and($record->exception['line'])->toBeInt();
    });

    it('records an HTTP exception the framework does not report', function (string $path, int $status, string $class) {
        Route::get('/forbidden', fn () => abort(403));
        Route::get('/gone', fn () => abort(410, 'gone for good'));

        $this->get($path)->assertStatus($status);

        expect(TracingRequest::firstOrFail()->exception['class'])->toBe($class);
    })->with([
        'aborted 403' => ['/forbidden', 403, Symfony\Component\HttpKernel\Exception\HttpException::class],
        'aborted 410' => ['/gone', 410, Symfony\Component\HttpKernel\Exception\HttpException::class],
        'unmatched route' => ['/no-such-route', 404, Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class],
    ]);

    it('records a validation failure', function () {
        Route::post('/validated', function () {
            throw ValidationException::withMessages(['email' => 'The email field is required.']);
        });

        $this->postJson('/validated', [])->assertStatus(422);

        expect(TracingRequest::firstOrFail()->exception['class'])->toBe(ValidationException::class);
    });

    it('leaves the exception column null on a successful request', function () {
        Route::get('/fine', fn () => response('ok'));

        $this->get('/fine')->assertOk();

        expect(TracingRequest::firstOrFail()->exception)->toBeNull();
    });

    it('does not carry an exception over into the next request on a shared worker', function () {
        Route::get('/boom', function () {
            throw new RuntimeException('kaboom');
        });
        Route::get('/fine', fn () => response('ok'));

        $this->get('/boom')->assertStatus(500);
        $this->get('/fine')->assertOk();

        expect(TracingRequest::where('url', 'like', '%/fine')->firstOrFail()->exception)->toBeNull();
    });

    it('records the request but no exception for a control-flow HttpResponseException', function () {
        // Boundary, asserted rather than left to be rediscovered: Laravel's
        // handler returns the carried response before finalizeRenderedResponse()
        // — and therefore before respondUsing() — so this one exception type
        // never reaches the hook. It is control flow ("return this response"),
        // not a failure, so the request is still recorded with its real status.
        // Worth knowing because the package's own 422 on a malformed date filter
        // is thrown this way and so lands with exception = null.
        Route::get('/redirecting', function () {
            throw new Illuminate\Http\Exceptions\HttpResponseException(redirect('/elsewhere'));
        });

        $this->get('/redirecting')->assertRedirect('/elsewhere');

        $record = TracingRequest::firstOrFail();

        expect($record->response_status)->toBe(302)
            ->and($record->exception)->toBeNull();
    });
});
