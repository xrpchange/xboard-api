<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>XBoard</title>
    <script>
        (function () {
            var u = @json($appDeepLink);
            try { window.location.replace(u); } catch (e) {}
        })();
    </script>
</head>
<body style="font-family: system-ui, Segoe UI, sans-serif; padding: 1rem; line-height: 1.45;">
<p><a href="{{ $appDeepLink }}">Open in XBoard app</a></p>
<p style="font-size: 0.875rem; color: #444;">If the app does not open, copy the address below or return to the email and use the HTTPS link.</p>
<p style="word-break: break-all; font-size: 0.8rem; color: #333;">{{ $webUrl }}</p>
</body>
</html>
