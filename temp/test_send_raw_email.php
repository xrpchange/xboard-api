<?php

/**
 * REMOVED: legacy temp script.
 * Use POST /api/v1/payments/verify-email with JSON
 * { "email_raw": "<base64 raw MIME>", "transaction_id": <id> }.
 */

fwrite(STDERR, "This script was removed: use POST /api/v1/payments/verify-email instead.\n");
exit(2);
