<?php

/**
 * Operator escalation email. Inbox + signed-link TTL from .env.
 * Signed URLs use the current HTTP request origin when the mail is sent from the API (same as other mail links), otherwise Laravel APP_URL.
 */
return [
    'escalation_email' => (string) env('ARBITER_OPERATOR_ESCALATION_EMAIL', ''),

    'action_link_ttl_days' => max(1, (int) env('ARBITER_OPERATOR_ACTION_LINK_TTL_DAYS', 7)),
];
