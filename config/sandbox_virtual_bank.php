<?php

/**
 * Synthetic RU-only sandbox receipt bank: not stored in `banks` table.
 * Client uses virtual_bank_id in lot create/update; DB keeps virtual_sandbox_bank_code + bank_id null.
 */
return [
    /** Matches JSON id merged into /banks/country/RU responses (not a DB row). */
    'virtual_bank_id' => (int) env('SANDBOX_VIRTUAL_BANK_ID', 900000001),

    'code' => 'sandbox_ru_receipt',

    /** Fixed English label for API JSON only (client localizes UI). */
    'name_en' => 'XBoard Sandbox Receipt Bank (RU)',

    'swift_code' => 'XBRDRUMMXXX',

    /** Public website host (project-style subdomain). */
    'website_host' => env('SANDBOX_VIRTUAL_BANK_WEBSITE', 'sandbox-receipt.xdexboard.com'),

    'country_code' => 'RU',

    /**
     * MIME header set on synthetic receipt emails so verify-sender can recognize them in sandbox
     * without whitelisting all traffic from MAIL_FROM_DOMAIN.
     */
    'receipt_mime_header_name' => 'X-XBoard-Sandbox-Test-Bank-Receipt',
    'receipt_mime_header_value' => '1',
];
