<?php

/**
 * Operator escalation email (image-only receipt path). Inbox + signed-link TTL from .env.
 * Copy and button labels: single Blade template resources/views/mail/arbiter_operator_escalation.blade.php (English).
 */
return [
    'escalation_email' => (string) env('ARBITER_OPERATOR_ESCALATION_EMAIL', ''),

    'action_link_ttl_days' => max(1, (int) env('ARBITER_OPERATOR_ACTION_LINK_TTL_DAYS', 7)),
];
