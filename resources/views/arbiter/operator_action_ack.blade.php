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
    <p>We received your choice for exchange lot <strong>{{ e($lotId) }}</strong>: <strong>{{ e($action) }}</strong>.</p>
    <p>This page only confirms the signed link worked. It does <strong>not</strong> move money or change the deal by itself — continue in your usual admin or partner workflow for this lot.</p>
</body>
</html>
