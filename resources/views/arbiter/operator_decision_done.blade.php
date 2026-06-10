<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Decision recorded — lot {{ e($lotId) }}</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 40rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
        .ok { background: #f0fdf4; border: 1px solid #bbf7d0; padding: 1rem; border-radius: 8px; margin: 1rem 0; }
        .muted { color: #64748b; font-size: 0.95rem; }
        blockquote { margin: 1rem 0; padding-left: 1rem; border-left: 3px solid #cbd5e1; }
    </style>
</head>
<body>
    <h1>Decision recorded</h1>
    <p>Lot <strong>{{ e($lotId) }}</strong>@if (!empty($transactionId)) · transaction <strong>{{ e($transactionId) }}</strong>@endif.</p>

    <div class="ok">
        @if (!empty($alreadyCompensated))
            <p>This lot was <strong>already compensated</strong> from seller escrow. No duplicate payout was submitted.</p>
        @elseif (!empty($cryptoTxId))
            <p>Escrow payout submitted. Crypto tx: <strong>{{ e($cryptoTxId) }}</strong>.</p>
        @endif
        @if ($alreadyClosed)
            <p>No active dispute was open; your decision was still posted to deal chat for audit.</p>
        @else
            <p><strong>{{ (int) $disputesClosed }}</strong> active dispute(s) closed with verdict <strong>{{ e($verdict) }}</strong>.</p>
        @endif
    </div>

    <p class="muted">Posted to deal chat (participants will see it after IMAP wake / refresh):</p>
    <blockquote>{{ e($body) }}</blockquote>
</body>
</html>
