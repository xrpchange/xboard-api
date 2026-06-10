<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payout failed — lot {{ e($lotId) }}</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 40rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
        .err { background: #fef2f2; border: 1px solid #fecaca; padding: 1rem; border-radius: 8px; margin: 1rem 0; }
        code { background: #f4f4f4; padding: 0.15rem 0.35rem; word-break: break-word; }
    </style>
</head>
<body>
    <h1>Compensation failed</h1>
    <p>Lot <strong>{{ e($lotId) }}</strong>@if (!empty($transactionId)) · transaction <strong>{{ e($transactionId) }}</strong>@endif.</p>
    <div class="err">
        <p>The escrow payout to the buyer <strong>did not complete</strong>. The dispute was <strong>not</strong> closed.</p>
        <p>Error: <code>{{ e($errorCode) }}</code></p>
        @if (!empty($transactionIdAttempted))
            <p>Sale transaction id: <strong>{{ e($transactionIdAttempted) }}</strong></p>
        @endif
    </div>
    <p>Check Core logs, buyer crypto address on the pending sale row, and reservation state. You may retry from a fresh operator email link after fixing the issue, or complete payout manually in the app.</p>
</body>
</html>
