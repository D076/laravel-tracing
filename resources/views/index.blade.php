<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tracing</title>
    <link rel="stylesheet" href="{{ route('tracing.asset', ['file' => 'app.css']) }}">
</head>
<body>
    <div id="app"></div>
    <script>
        window.__tracing = {
            apiBase: @json(rtrim(url(config('tracing.ui.path', 'tracing')), '/') . '/api'),
            basePath: @json('/' . trim(config('tracing.ui.path', 'tracing'), '/')),
        };
    </script>
    <script type="module" src="{{ route('tracing.asset', ['file' => 'app.js']) }}"></script>
</body>
</html>
