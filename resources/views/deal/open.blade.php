<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>XBoard</title>
    @if (!empty($appIntentUrl))
    <script>
        (function () {
            var intentUrl = @json($appIntentUrl);
            try { window.location.replace(intentUrl); } catch (e) {}
        })();
    </script>
    @endif
</head>
<body style="font-family: system-ui, Segoe UI, sans-serif; padding: 1.25rem; line-height: 1.45; max-width: 32rem; margin: 0 auto;">
<h1 style="font-size: 1.15rem; margin: 0 0 1rem 0;">Open deal in XBoard</h1>
@if (!empty($appIntentUrl))
    <p style="margin: 0 0 1rem 0;">
        <a href="{{ $appIntentUrl }}"
           style="display:inline-block;padding:12px 18px;border-radius:6px;background:#2d6a4f;color:#fff;text-decoration:none;font-weight:600;font-size:1rem;">
            Open in XBoard app
        </a>
    </p>
@endif
@if (!empty($appDeepLink))
    <p style="margin: 0 0 1rem 0; font-size: 0.9rem;">
        <a href="{{ $appDeepLink }}">Open via app link</a>
    </p>
@endif
<p style="font-size: 0.875rem; color: #444; margin: 0;">If nothing happens, install or update the XBoard app, then tap the green button again.</p>
</body>
</html>
