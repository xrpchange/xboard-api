@php
    /** @var string $body */
    /** @var string|null $webUrl API HTTPS landing (/open-deal → app) */
    /** @var string|null $clientWebUrl Web client origin + /open-deal */
    /** @var string|null $appUrl xboard:// deep link */
    $hasLinks = $clientWebUrl || $webUrl || $appUrl;
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
                        {{-- No whitespace inside pre-wrap block: indentation in Blade would become visible indent. --}}
                        <div style="white-space:pre-wrap;margin:0;padding:0;text-indent:0;">{!! nl2br(e($body), false) !!}</div>
                    </td>
                </tr>

                @if ($hasLinks)
                    <tr style="background-color:#f2f2f2;">
                        <td style="padding:12px 14px;border-top:1px solid #e2e2e2;text-align:left;">
                            <div style="font-weight:500;margin-bottom:10px;text-align:center;">Open this deal</div>

                            @if ($clientWebUrl)
                                <div style="margin:0 0 12px 0;padding:10px;background:#fff;border:1px solid #ddd;border-radius:4px;">
                                    <a href="{{ $clientWebUrl }}"
                                       style="display:inline-block;margin-bottom:6px;padding:8px 14px;border-radius:4px;
                                       background-color:#2d6a4f;color:#ffffff;text-decoration:none;font-size:13px;font-weight:500;">
                                        Open in web browser
                                    </a>
                                    <div style="font-size:11px;color:#666;word-break:break-all;">{{ $clientWebUrl }}</div>
                                </div>
                            @endif

                            @if ($webUrl)
                                <div style="margin:0 0 12px 0;padding:10px;background:#fff;border:1px solid #ddd;border-radius:4px;">
                                    <a href="{{ $webUrl }}"
                                       style="display:inline-block;margin-bottom:6px;padding:8px 14px;border-radius:4px;
                                       background-color:#4a4a4a;color:#ffffff;text-decoration:none;font-size:13px;font-weight:500;">
                                        Open in XBoard app (HTTPS)
                                    </a>
                                    <div style="font-size:11px;color:#666;word-break:break-all;">{{ $webUrl }}</div>
                                </div>
                            @endif

                            @if ($appUrl)
                                <div style="margin:0;padding:10px;background:#fff;border:1px solid #ddd;border-radius:4px;">
                                    <div style="font-size:13px;font-weight:500;margin-bottom:6px;">App link (copy if needed)</div>
                                    <div style="font-size:11px;color:#444;word-break:break-all;font-family:monospace;">{{ $appUrl }}</div>
                                </div>
                            @endif
                        </td>
                    </tr>
                @endif

                <tr>
                    <td style="padding:10px 14px;border-top:1px solid #e5e5e5;color:#888;font-size:11px;">
                        Three ways to open: web client, HTTPS app landing, or the app link above. If a button does not work, copy the URL from this section or from the plain-text part of the email.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
