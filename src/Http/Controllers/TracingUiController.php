<?php

namespace D076\Tracing\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class TracingUiController extends Controller
{
    public function index(): Response
    {
        return response()->view('tracing::index');
    }

    public function asset(string $file): BinaryFileResponse
    {
        $distDir = realpath(__DIR__ . '/../../../resources/dist');
        $distPath = realpath($distDir . '/' . $file);

        abort_unless(
            $distDir !== false && $distPath !== false && str_starts_with($distPath, $distDir),
            404,
        );

        $mimeType = match (pathinfo($file, PATHINFO_EXTENSION)) {
            'js' => 'application/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            default => 'application/octet-stream',
        };

        return response()->file($distPath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
