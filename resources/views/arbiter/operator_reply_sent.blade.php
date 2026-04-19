<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Message sent — lot {{ e($lotId) }}</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 40rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
    </style>
</head>
<body>
    <h1>Message sent</h1>
    <p>Your reply was added to the deal chat for lot <strong>{{ e($lotId) }}</strong>. Participants will see it when they open or refresh the deal.</p>
    <p>You can close this window.</p>
</body>
</html>
