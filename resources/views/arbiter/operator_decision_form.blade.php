<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ e($title) }} — lot {{ e($lotId) }}</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 40rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
        .warn { background: #fffbeb; border: 1px solid #fde68a; padding: 1rem; border-radius: 8px; margin: 1rem 0; }
        .safe { background: #f0fdf4; border: 1px solid #bbf7d0; padding: 1rem; border-radius: 8px; margin: 1rem 0; }
        .dest { background: #eff6ff; border: 1px solid #bfdbfe; padding: 1rem; border-radius: 8px; margin: 1rem 0; word-break: break-all; font-family: ui-monospace, monospace; font-size: 0.9rem; }
        button { background: #1d4ed8; color: #fff; border: 0; padding: 0.65rem 1rem; border-radius: 8px; font-weight: 600; cursor: pointer; }
        button.warn { background: #9f1239; }
        button:disabled { background: #94a3b8; cursor: not-allowed; }
    </style>
</head>
<body>
    <h1>{{ e($title) }}</h1>
    <p>Lot <strong>{{ e($lotId) }}</strong>@if (!empty($transactionId)) · transaction <strong>{{ e($transactionId) }}</strong>@endif.</p>
    <div class="safe">{{ e($summary) }}</div>
    @if (empty($hasActiveDispute))
        <div class="warn">There is <strong>no active dispute</strong> (opened/in_review) for this lot.</div>
    @endif
    @if ($action === 'compensate_buyer')
        @if (!empty($compensationDestination))
            <div class="dest">
                <strong>Buyer-declared compensation wallet</strong><br>
                {{ e(strtoupper($compensationDestination['crypto'] ?? '')) }}: {{ e($compensationDestination['address'] ?? '') }}
            </div>
        @else
            <div class="warn">The buyer has <strong>not declared</strong> a compensation wallet in deal chat (<code>buyer_compensation_destination</code> tag). Payout is blocked until the buyer posts one from the app.</div>
        @endif
        <div class="warn">This executes a <strong>real Core payout</strong> only to the address above. If Core rejects the transfer, the dispute stays open.</div>
    @else
        <div class="warn">This closes the dispute favor seller and posts the decision to deal chat. It does <strong>not</strong> send crypto.</div>
    @endif
    <form method="post" action="{{ $formAction }}">
        @csrf
        <button type="submit"
            @if($action === 'deny_buyer') class="warn" @endif
            @if($action === 'compensate_buyer')
                @if(empty($compensationDestination)) disabled @endif
                onclick="if(!this.disabled){this.disabled=true;this.form.submit();}"
            @endif
        >Confirm: {{ e($confirmLabel) }}</button>
    </form>
</body>
</html>
