<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>XBoard — transfer check</title>
    <style>
        body { margin: 0; padding: 0; background: #f0f2f5; font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; font-size: 16px; line-height: 1.5; color: #1a1a1a; }
        .wrap { max-width: 520px; margin: 0 auto; padding: 24px 16px 40px; }
        .card { background: #fff; border-radius: 12px; padding: 24px 22px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .env-pill { display: inline-block; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; padding: 5px 10px; border-radius: 999px; margin-bottom: 14px; }
        .env-prod { background: #dcfce7; color: #166534; }
        .env-sandbox { background: #fef3c7; color: #92400e; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        .sub { color: #64748b; font-size: 14px; margin: 0 0 20px; }
        .verdict { border-radius: 10px; padding: 18px 16px; margin: 16px 0; font-size: 18px; font-weight: 700; text-align: center; }
        .verdict-ok { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .verdict-fail { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .detail { background: #f8fafc; border-radius: 8px; padding: 14px; margin: 12px 0; font-size: 14px; }
        .detail dt { font-weight: 600; color: #475569; margin-top: 8px; }
        .detail dt:first-child { margin-top: 0; }
        .detail dd { margin: 2px 0 0; word-break: break-all; font-family: ui-monospace, monospace; font-size: 13px; }
        .btn { display: inline-block; margin: 8px 8px 0 0; padding: 12px 18px; border-radius: 8px; font-size: 15px; font-weight: 600; text-decoration: none; color: #fff !important; }
        .btn-explorer { background: #1d4ed8; }
        .actions { margin-top: 20px; padding-top: 16px; border-top: 1px solid #e5e7eb; }
        .muted { font-size: 13px; color: #64748b; margin-top: 20px; }
        details { margin-top: 12px; font-size: 13px; color: #475569; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="env-pill {{ ($environmentLabel ?? '') === 'Sandbox' ? 'env-sandbox' : 'env-prod' }}">{{ e($environmentLabel ?? 'Production') }}</div>
            <h1>Transfer check</h1>
            <p class="sub">Lot <strong>{{ e($lotId) }}</strong> · message <strong>#{{ e($messageId) }}</strong></p>

            @if (is_array($catalogProof))
                <dl class="detail">
                    <dt>Declared transfer</dt>
                    <dd>{{ e($catalogProof['label'] ?? 'Transfer') }}</dd>
                    @if (!empty($catalogProof['tx_id']))
                        <dt>Transaction id</dt>
                        <dd>{{ e($catalogProof['tx_id']) }}</dd>
                    @endif
                    @if (!empty($catalogProof['crypto_type']))
                        <dt>Crypto type (Core)</dt>
                        <dd>{{ e($catalogProof['crypto_type']) }}</dd>
                    @endif
                </dl>
            @endif

            @if ($verified)
                <div class="verdict verdict-ok">Verified on-chain (Core)</div>
            @else
                <div class="verdict verdict-fail">Not verified on-chain</div>
                @if (is_array($error))
                    <p style="font-size:14px;color:#475569;">{{ e($error['message'] ?? 'Verification failed.') }}</p>
                @endif
            @endif

            @if (is_array($storedCheck) && $storedCheck !== [])
                <p style="font-size:14px;color:#475569;margin:12px 0 0;">
                    When the user sent this message:
                    <strong>{{ ($storedCheck['verified'] ?? false) ? 'verified' : 'not verified' }}</strong>
                    @if (!empty($storedCheck['checked_at_utc']))
                        ({{ e((string) $storedCheck['checked_at_utc']) }})
                    @endif
                </p>
            @endif

            <div class="actions">
                @if ($explorerUrl)
                    <a class="btn btn-explorer" href="{{ $explorerUrl }}" rel="noopener noreferrer" target="_blank">Open in block explorer</a>
                @endif
            </div>

            @if ($verification !== [])
                <details>
                    <summary>Technical details (Core JSON)</summary>
                    <pre style="white-space:pre-wrap;word-break:break-word;font-size:12px;background:#f1f5f9;padding:10px;border-radius:6px;">{{ e(json_encode($verification, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) }}</pre>
                </details>
            @endif

            <p class="muted">Arbitration view only — this page does not move funds or change the deal.</p>
        </div>
    </div>
</body>
</html>
