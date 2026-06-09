@php
    $mailSection = $mailSection ?? 'html';
    $envLabel = (string) ($digest['deployment_environment_label'] ?? 'Production');
    $isSandbox = strtolower($envLabel) === 'sandbox';
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
        .env-pill { display: inline-block; font-size: 12px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; padding: 5px 10px; border-radius: 999px; margin-bottom: 16px; }
        .env-pill-prod { background: #dcfce7; color: #166534; }
        .env-pill-sandbox { background: #fef3c7; color: #92400e; }
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
        .proof-card { border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 18px; margin: 0 0 14px; background: #fafbfc; }
        .proof-title { font-size: 16px; font-weight: 700; margin: 0 0 4px; color: #0f172a; }
        .proof-meta { font-size: 13px; color: #64748b; margin: 0 0 10px; }
        .proof-tx { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13px; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 10px; word-break: break-all; margin: 0 0 10px; }
        .status-pill { display: inline-block; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 999px; margin-bottom: 12px; }
        .status-ok { background: #dcfce7; color: #166534; }
        .status-fail { background: #fee2e2; color: #991b1b; }
        .status-unknown { background: #f1f5f9; color: #475569; }
        .proof-actions { margin-top: 12px; }
        .btn { display: inline-block; margin: 4px 8px 4px 0; padding: 12px 18px; color: #fff !important; text-decoration: none; border-radius: 8px; font-size: 15px; font-weight: 600; }
        .btn-verify { background: #15803d; }
        .btn-explorer { background: #1d4ed8; }
        .btn-receipt { background: #475569; }
        .btn-reply { background: #1e293b; }
        .btn-secondary { background: #475569; }
        .btn-warn { background: #9f1239; }
        .actions { margin-top: 28px; padding-top: 24px; border-top: 1px solid #e5e7eb; }
        .actions p { margin: 0 0 12px; font-size: 14px; color: #475569; }
        .footer { margin-top: 24px; font-size: 13px; color: #94a3b8; text-align: center; }
        .hint { font-size: 13px; color: #64748b; margin: 0 0 14px; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="env-pill {{ $isSandbox ? 'env-pill-sandbox' : 'env-pill-prod' }}">{{ e($envLabel) }} chain checks</div>
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

            @if (!empty($digest['tx_proofs']))
                <h2>Declared transfers — check here</h2>
                <p class="hint">Click a button below — no copy-paste. <strong>Check transfer</strong> opens a one-page Core verdict for this lot ({{ e($envLabel) }}). Explorer opens the matching testnet or mainnet site.</p>
                @foreach ($digest['tx_proofs'] as $proof)
                    <div class="proof-card">
                        <p class="proof-title">{{ e($proof['label'] ?? 'Transfer') }}</p>
                        <p class="proof-meta">Chat message #{{ e((string) ($proof['message_id'] ?? '')) }} · {{ e($proof['crypto_type'] ?? ($proof['bank_receipt_only'] ? 'bank receipt' : 'crypto')) }}</p>
                        @if (!empty($proof['bank_receipt_only']))
                            <span class="status-pill status-unknown">Bank receipt proof</span>
                            <p class="hint" style="margin-bottom:8px;">No on-chain id — open the receipt PDF(s) below.</p>
                            <div class="proof-actions">
                                @foreach ($proof['receipt_urls'] ?? [] as $receipt)
                                    <a class="btn btn-receipt" href="{{ $receipt['url'] }}">Open {{ e($receipt['label'] ?? 'receipt') }}</a>
                                @endforeach
                            </div>
                        @else
                            @php
                                $verifiedAtSend = $proof['verified_at_send'] ?? null;
                            @endphp
                            @if ($verifiedAtSend === true)
                                <span class="status-pill status-ok">Verified when sent</span>
                            @elseif ($verifiedAtSend === false)
                                <span class="status-pill status-fail">Not verified when sent</span>
                            @else
                                <span class="status-pill status-unknown">No prior Core check on record</span>
                            @endif
                            <div class="proof-tx">{{ e($proof['tx_id'] ?? '') }}</div>
                            <div class="proof-actions">
                                @if (!empty($proof['verify_url']))
                                    <a class="btn btn-verify" href="{{ $proof['verify_url'] }}">Check transfer (Core)</a>
                                @endif
                                @if (!empty($proof['explorer_url']))
                                    <a class="btn btn-explorer" href="{{ $proof['explorer_url'] }}">Block explorer</a>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            @endif

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
                <p><strong>Operator decision</strong> — reply in the deal thread or record your choice (links expire after a set time).</p>
                <a class="btn btn-reply" href="{{ $urls['reply'] }}">Reply in thread</a>
                <a class="btn btn-secondary" href="{{ $urls['compensate_buyer'] }}">Compensate buyer</a>
                <a class="btn btn-warn" href="{{ $urls['deny_buyer'] }}">Deny buyer</a>
                <p class="hint">Anti-flood (UTC day): set the participant deal mailbox counter to the daily cap — no separate ban flag.</p>
                <a class="btn btn-warn" href="{{ $urls['block_buyer_internal_mail'] }}">Block buyer internal mail (24h)</a>
                <a class="btn btn-warn" href="{{ $urls['block_seller_internal_mail'] }}">Block seller internal mail (24h)</a>
            </div>
        </div>
        <p class="footer">Lot {{ e($lotId) }} · {{ e($envLabel) }} · XBoard operator mail</p>
    </div>
</body>
</html>
@endif
