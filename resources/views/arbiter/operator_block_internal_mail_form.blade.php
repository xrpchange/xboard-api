<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Block internal mail — lot {{ e($lotId) }}</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 40rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
        .warn { background: #fef2f2; border: 1px solid #fecaca; padding: 1rem; border-radius: 8px; margin: 1rem 0; }
        button { background: #9f1239; color: #fff; border: 0; padding: 0.65rem 1rem; border-radius: 8px; font-weight: 600; cursor: pointer; }
    </style>
</head>
<body>
    <h1>Block {{ e($participant) }} internal mail (24h UTC)</h1>
    <p>Lot <strong>{{ e($lotId) }}</strong>. This sets the {{ e($participant) }} deal mailbox daily send counter to the configured limit for <strong>today (UTC)</strong>. They cannot send more internal deal mail until the UTC day rolls over.</p>
    <div class="warn">No separate ban flag — only the daily counter row is updated.</div>
    <form method="post" action="{{ $formAction }}">
        @csrf
        <button type="submit">Confirm block for rest of UTC day</button>
    </form>
</body>
</html>
