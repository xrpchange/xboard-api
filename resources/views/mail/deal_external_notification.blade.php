@php
    /** @var string $body */
    /** @var string|null $webUrl */
    /** @var string|null $appUrl */
    $primary = $webUrl ?? $appUrl;
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

                @if ($primary)
                    <tr style="background-color:#f2f2f2;">
                        <td style="padding:12px 14px;border-top:1px solid #e2e2e2;text-align:center;">
                            <a href="{{ $primary }}"
                               style="display:inline-block;padding:8px 16px;border-radius:4px;
                               background-color:#4a4a4a;color:#ffffff;text-decoration:none;
                               font-size:13px;font-weight:500;">
                                Open in XBoard app
                            </a>
                        </td>
                    </tr>
                @endif

                <tr>
                    <td style="padding:10px 14px;border-top:1px solid #e5e5e5;color:#888;font-size:11px;">
                        If the button does not work, copy the link from your browser's address bar after opening it.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>

