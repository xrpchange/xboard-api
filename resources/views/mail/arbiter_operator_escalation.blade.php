@php
    $mailSection = $mailSection ?? 'html';
@endphp
@if ($mailSection === 'html')
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dispute review</title>
    <style>
        body { margin: 0; padding: 0; background: #f0f2f5; font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; font-size: 16px; line-height: 1.5; color: #1a1a1a; }
        .wrap { max-width: 640px; margin: 0 auto; padding: 24px 16px 40px; }
        .card { background: #fff; border-radius: 12px; padding: 28px 24px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        h1 { font-size: 22px; font-weight: 700; margin: 0 0 12px; line-height: 1.3; }
        .lead { font-size: 16px; color: #333; margin: 0 0 20px; }
        .reason-pill { display: inline-block; background: #eef2ff; color: #3730a3; font-size: 14px; font-weight: 600; padding: 6px 12px; border-radius: 999px; margin-bottom: 20px; }
        .ref { font-size: 12px; color: #64748b; margin-bottom: 24px; }
        h2 { font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #64748b; margin: 28px 0 10px; border-top: 1px solid #e5e7eb; padding-top: 24px; }
        h2:first-of-type { border-top: 0; padding-top: 0; margin-top: 0; }
        table.facts { width: 100%; border-collapse: collapse; font-size: 15px; }
        table.facts th { text-align: left; vertical-align: top; width: 38%; padding: 10px 12px 10px 0; color: #475569; font-weight: 600; border-bottom: 1px solid #f1f5f9; }
        table.facts td { padding: 10px 0; border-bottom: 1px solid #f1f5f9; word-break: break-word; }
        .section { margin-top: 8px; }
        .section-body { background: #f8fafc; border-radius: 8px; padding: 16px; font-size: 15px; color: #334155; white-space: pre-wrap; word-break: break-word; }
        ol.chat-turns { margin: 0; padding-left: 1.25rem; font-size: 15px; color: #334155; }
        ol.chat-turns li { margin: 0 0 1rem; padding-left: 0.25rem; }
        ol.chat-turns li:last-child { margin-bottom: 0; }
        .turn-meta { margin-bottom: 6px; }
        .turn-kind { margin-left: 8px; font-size: 13px; font-weight: 600; color: #64748b; text-transform: none; letter-spacing: 0; }
        .turn-body { margin: 0; padding: 10px 12px; background: #f8fafc; border-radius: 8px; white-space: pre-wrap; word-break: break-word; line-height: 1.45; }
        .actions { margin-top: 28px; padding-top: 24px; border-top: 1px solid #e5e7eb; }
        .actions p { margin: 0 0 12px; font-size: 14px; color: #475569; }
        .btn { display: inline-block; margin: 6px 8px 6px 0; padding: 12px 18px; background: #1e293b; color: #fff !important; text-decoration: none; border-radius: 8px; font-size: 15px; font-weight: 600; }
        .btn-secondary { background: #475569; }
        .btn-warn { background: #9f1239; }
        .footer { margin-top: 24px; font-size: 13px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>{{ e($digest['headline'] ?? 'Dispute review') }}</h1>
            <p class="lead">{{ e($digest['lead'] ?? '') }}</p>
            @if (!empty($digest['reason_label']))
                <div class="reason-pill">{{ e($digest['reason_label']) }}</div>
            @endif
            @if (!empty($digest['policy_reason_code']))
                <p class="ref">Internal reference (for support): <strong>{{ e($digest['policy_reason_code']) }}</strong></p>
            @endif

            <h2>Key facts</h2>
            <table class="facts" role="presentation">
                @foreach ($digest['facts'] ?? [] as $row)
                    <tr>
                        <th scope="row">{{ e($row['label'] ?? '') }}</th>
                        <td>{{ e($row['value'] ?? '') }}</td>
                    </tr>
                @endforeach
            </table>

            @if (!empty($digest['chat_turns']))
                <h2>Recent conversation</h2>
                <ol class="chat-turns">
                    @foreach ($digest['chat_turns'] as $turn)
                        <li>
                            <div class="turn-meta">
                                <strong>{{ e($turn['speaker'] ?? '') }}</strong>
                                @if (!empty($turn['kind']))
                                    <span class="turn-kind">{{ e($turn['kind']) }}</span>
                                @endif
                            </div>
                            @if (($turn['body'] ?? '') !== '')
                                <div class="turn-body">{{ e($turn['body']) }}</div>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif

            @foreach ($digest['sections'] ?? [] as $sec)
                <div class="section">
                    <h2>{{ e($sec['title'] ?? '') }}</h2>
                    <div class="section-body">{{ e($sec['content'] ?? '') }}</div>
                </div>
            @endforeach

            <div class="actions">
                <p><strong>Next step</strong> — use one of the secure links below (they only work for this lot and expire after a set time).</p>
                <a class="btn" href="{{ $urls['reply'] }}">Reply in thread</a>
                <a class="btn btn-secondary" href="{{ $urls['compensate_buyer'] }}">Compensate buyer</a>
                <a class="btn btn-warn" href="{{ $urls['deny_buyer'] }}">Deny buyer</a>
            </div>
        </div>
        <p class="footer">Lot {{ e($lotId) }} · XBoard operator mail</p>
    </div>
</body>
</html>
@endif
