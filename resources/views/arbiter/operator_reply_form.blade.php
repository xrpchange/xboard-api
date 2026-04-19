<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reply in deal chat — lot {{ e($lotId) }}</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 40rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
        label { display: block; font-weight: 600; margin-bottom: 0.5rem; }
        textarea { width: 100%; min-height: 10rem; padding: 0.75rem; font-size: 1rem; border: 1px solid #ccc; border-radius: 8px; box-sizing: border-box; }
        .error { color: #b91c1c; font-size: 0.9rem; margin-top: 0.35rem; }
        button { margin-top: 1rem; padding: 0.65rem 1.25rem; font-size: 1rem; border-radius: 8px; border: 0; background: #1e293b; color: #fff; cursor: pointer; }
        button:hover { background: #334155; }
        p.note { color: #64748b; font-size: 0.9rem; }
    </style>
</head>
<body>
    <h1>Reply in deal chat</h1>
    <p>Lot <strong>{{ e($lotId) }}</strong>. Your message will appear for the buyer and seller in the in-app deal chat.</p>
    <p class="note">This link expires; do not share it outside your team.</p>

    @if ($errors->any())
        <div class="error" role="alert">
            @foreach ($errors->all() as $err)
                <div>{{ $err }}</div>
            @endforeach
        </div>
    @endif

    <form method="post" action="{{ $formAction }}">
        @csrf
        <label for="body">Message</label>
        <textarea id="body" name="body" required maxlength="{{ max(1, (int) config('deal.max_message_length', 1000)) }}">{{ old('body') }}</textarea>
        <button type="submit">Send to chat</button>
    </form>
</body>
</html>
