<!DOCTYPE html>
<html lang="en" data-theme="{{ $theme }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tracing</title>
    <script>
        // Runs before the stylesheet and before any markup: a visitor's stored
        // choice has to be on <html> ahead of the first paint, otherwise every
        // load flashes the config default over the theme they picked.
        (function () {
            try {
                var stored = window.localStorage.getItem(@json($themeStorageKey));
                if (@json($themes).indexOf(stored) !== -1) {
                    document.documentElement.setAttribute('data-theme', stored);
                }
            } catch (e) {
                // Storage can be unavailable (private mode, blocked cookies);
                // the server-rendered attribute already holds a usable theme.
            }
        })();
    </script>
    <link rel="stylesheet" href="{{ $styleUrl }}">
</head>
<body>
    <div id="app"></div>
    <script>
        window.__tracing = {
            apiBase: @json(rtrim(url(config('tracing.ui.path', 'tracing')), '/') . '/api'),
            basePath: @json('/' . trim(config('tracing.ui.path', 'tracing'), '/')),
        };
    </script>
    <script type="module" src="{{ $scriptUrl }}"></script>
</body>
</html>
