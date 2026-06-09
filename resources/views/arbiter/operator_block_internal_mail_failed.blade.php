<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Block failed</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 40rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
    </style>
</head>
<body>
    <h1>Could not block</h1>
    <p>No {{ e($participant) }} deal mailbox email on lot <strong>{{ e($lotId) }}</strong>, or internal mail limits are disabled.</p>
</body>
</html>
