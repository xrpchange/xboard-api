<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Internal mail blocked</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 40rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
    </style>
</head>
<body>
    <h1>Blocked</h1>
    <p>The <strong>{{ e($participant) }}</strong> deal mailbox for lot <strong>{{ e($lotId) }}</strong> is at the daily internal mail cap ({{ (int) $dailyLimit }} sends, UTC day).</p>
    <p>Mailbox: <code>{{ e($mailboxEmail) }}</code></p>
</body>
</html>
