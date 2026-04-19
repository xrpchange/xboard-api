<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>XBoard — operator action</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 40rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
        code { background: #f4f4f4; padding: 0.15rem 0.35rem; }
    </style>
</head>
<body>
    <h1>Recorded</h1>
    <p>Operator mail action was received for exchange lot <code>{{ $lotId }}</code> with action <code>{{ $action }}</code>.</p>
    <p>This page opened from a <strong>signed link</strong> scoped to that lot and action; it does not apply to other deals.</p>
    <p>Continue processing in your internal tools if required; this endpoint only logs the click.</p>
</body>
</html>
