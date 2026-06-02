@php
    /** @var string $body */
    /** @var string|null $webUrl API HTTPS landing (/open-deal) — green button (Gmail blocks xboard:// in HTML) */
    /** @var string|null $clientWebUrl Web client origin + /open-deal */
    /** @var string|null $appUrl xboard:// copy fallback */
    /** @var string|null $appIntentUrl intent:// for landing page only */
    $greenHref = $webUrl ?? $clientWebUrl;
    $hasLinks = $greenHref || $appUrl;
@endphp

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>XBoard deal notification</title>
</head>
<body style="margin:0;padding:0;background-color:#eeeeee;">
<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center"
       style="margin:16px auto;width:100%;max-width:560px;font-family:system-ui,Segoe UI,sans-serif;">
    <tr>
        <td style="padding:0;text-align:center;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0"
                   style="width:100%;border-collapse:collapse;
                   background-color:#f7f7f7;border:1px solid #dcdcdc;border-radius:6px;
                   font-size:14px;line-height:1.5;color:#222;overflow:hidden;">
                <tr style="background-color:#f0f0f0;">
                    <td style="padding:10px 14px;border-bottom:1px solid #dcdcdc;color:#444;font-weight:500;text-align:center;">
                        XBoard deal notification
                    </td>
                </tr>
                <tr>
                    <td style="padding:14px 20px 12px 20px;text-align:left;">
                        <div style="white-space:pre-wrap;margin:0;padding:0;text-indent:0;">{!! nl2br(e($body), false) !!}</div>
                    </td>
                </tr>

                @if ($hasLinks)
                    <tr style="background-color:#f2f2f2;">
                        <td style="padding:12px 14px;border-top:1px solid #e2e2e2;text-align:left;">
                            <div style="font-weight:500;margin-bottom:10px;text-align:center;">Open this deal</div>

                            @if ($greenHref)
                                <div style="margin:0 0 12px 0;padding:10px;background:#fff;border:1px solid #ddd;border-radius:4px;">
                                    <a href="{{ $greenHref }}"
                                       target="_blank" rel="noopener noreferrer"
                                       style="display:inline-block;margin-bottom:6px;padding:8px 14px;border-radius:4px;
                                       background-color:#2d6a4f;color:#ffffff;text-decoration:none;font-size:13px;font-weight:500;">
                                        Open in XBoard app
                                    </a>
                                    <div style="font-size:11px;color:#666;word-break:break-all;">{{ $greenHref }}</div>
                                </div>
                            @endif

                            @if ($clientWebUrl && $clientWebUrl !== $greenHref)
                                <div style="margin:0 0 12px 0;padding:10px;background:#fff;border:1px solid #ddd;border-radius:4px;">
                                    <a href="{{ $clientWebUrl }}"
                                       target="_blank" rel="noopener noreferrer"
                                       style="display:inline-block;margin-bottom:6px;padding:8px 14px;border-radius:4px;
                                       background-color:#4a4a4a;color:#ffffff;text-decoration:none;font-size:13px;font-weight:500;">
                                        Open in web browser
                                    </a>
                                    <div style="font-size:11px;color:#666;word-break:break-all;">{{ $clientWebUrl }}</div>
                                </div>
                            @endif

                            @if ($appUrl && $appUrl !== $greenHref)
                                <div style="margin:0;padding:10px;background:#fff;border:1px solid #ddd;border-radius:4px;">
                                    <div style="font-size:12px;color:#555;margin-bottom:4px;">App link (if the green button does not open the app):</div>
                                    <div style="font-size:11px;color:#666;word-break:break-all;">{{ $appUrl }}</div>
                                </div>
                            @endif
                        </td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>
</body>
</html>
