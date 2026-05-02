{{-- Outer layout borrowed from mail/deal_external_notification (warning-style header). Body text is supplied by client (localized) except legacy fallback English in Mailable. --}}
<!doctype html>
<html lang="und">
<head>
    <meta charset="utf-8">
    <title>{{ e($subjectForTitle ?? 'XBoard') }}</title>
</head>
<body style="margin:0;padding:0;background-color:#eeeeee;">
<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center"
       style="margin:16px auto;width:100%;max-width:560px;font-family:system-ui,Segoe UI,sans-serif;">
    <tr>
        <td style="padding:0;text-align:center;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0"
                   style="width:100%;border-collapse:collapse;
                   background-color:#fafafa;border:1px solid #dcdcdc;border-radius:6px;
                   font-size:14px;line-height:1.55;color:#222;overflow:hidden;">
                <tr style="background-color:#fff8e1;">
                    <td style="padding:10px 14px;border-bottom:1px solid #e8dcb8;color:#5c4d00;font-weight:600;text-align:center;">
                        {{ e($headerTitle) }}
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 20px 10px;text-align:left;">
                        <div style="white-space:pre-wrap;margin:0;color:#333;">{!! nl2br(e($intro)) !!}</div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:4px 20px 14px;text-align:center;">
                        <div style="display:inline-block;padding:14px 22px;background:#f5f5f5;border:1px solid #e0e0e0;border-radius:8px;font-size:26px;font-weight:700;letter-spacing:0.08em;font-family:ui-monospace,Consolas,monospace;color:#111;">
                            {{ e($code) }}
                        </div>
                    </td>
                </tr>
                @if (($afterCode ?? '') !== '')
                    <tr>
                        <td style="padding:0 20px 14px;text-align:left;">
                            <div style="white-space:pre-wrap;margin:0;color:#444;">{!! nl2br(e($afterCode)) !!}</div>
                        </td>
                    </tr>
                @endif
                @if (($footer ?? '') !== '')
                    <tr style="background-color:#f7f7f7;">
                        <td style="padding:12px 16px;border-top:1px solid #e8e8e8;color:#757575;font-size:12px;">
                            <div style="white-space:pre-wrap;margin:0;">{!! nl2br(e($footer)) !!}</div>
                        </td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>
</body>
</html>
