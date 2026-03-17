<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Email attachments storage root (optional)
    |--------------------------------------------------------------------------
    |
    | If set (e.g. via PAYMENT_VERIFICATION_ATTACHMENTS_ROOT), receipt PDF
    | paths are resolved as {root}/{relative_path} when deleting files (e.g.
    | on Transaction delete or email-attachments-tmp:prune). Otherwise
    | Storage::disk('local') is used (storage/app/private by default).
    |
    */

    'attachments_root' => env('PAYMENT_VERIFICATION_ATTACHMENTS_ROOT', null),

];
