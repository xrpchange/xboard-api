@php
    $mailSection = $mailSection ?? 'html';
    $operatorEscalationIntro = <<<'EOT'
XBoard arbiter — operator escalation

Reason: receipt PDF matched pdf_sha256 on disk but produced no extractable plain text (likely scanned image / bitmap only). The AI arbiter was not invoked for this request.

--- Full deal context (same fields as LLM user prompt, without model instructions) ---


EOT;
@endphp
@if ($mailSection === 'subject')
[XBoard Arbiter] Image-only receipt — lot {{ $lotId }}
@elseif ($mailSection === 'plain')
{!! $operatorEscalationIntro !!}{!! $dealContextPlain !!}

---
Signed action links (lot {!! $lotId !!}; each URL is scoped to this lot and expires):
Reply: {!! $urls['reply'] !!}
Compensate buyer: {!! $urls['compensate_buyer'] !!}
Deny buyer: {!! $urls['deny_buyer'] !!}
@else
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        .btn { display: inline-block; margin: 0.35rem 0.35rem 0.35rem 0; padding: 0.55rem 1rem;
            background: #1a1a2e; color: #fff !important; text-decoration: none; border-radius: 6px;
            font-family: system-ui, sans-serif; font-size: 14px; }
        .btn-secondary { background: #3d3d5c; }
        .btn-warn { background: #5c3d3d; }
        pre { white-space: pre-wrap; word-break: break-word; font-size: 12px; background: #f6f6f6; padding: 1rem; border-radius: 6px; }
        p.note { font-size: 13px; color: #444; }
    </style>
</head>
<body>
    <p class="note">Signed action links below are valid for this lot only (lot <strong>{{ e($lotId) }}</strong>) until they expire.</p>
    <p>
        <a class="btn" href="{{ $urls['reply'] }}">Reply</a>
        <a class="btn btn-secondary" href="{{ $urls['compensate_buyer'] }}">Compensate buyer</a>
        <a class="btn btn-warn" href="{{ $urls['deny_buyer'] }}">Deny buyer</a>
    </p>
    <pre>{{ e($operatorEscalationIntro.$dealContextPlain) }}</pre>
</body>
</html>
@endif
