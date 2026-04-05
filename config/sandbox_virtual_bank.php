<?php

/**
 * Synthetic sandbox receipt bank: not stored in `banks` table.
 * Client uses virtual_bank_id in lot create/update; DB keeps virtual_sandbox_bank_code + bank_id null.
 */
return [
    /** Matches JSON id merged into /banks responses (not a DB row). */
    'virtual_bank_id' => (int) env('SANDBOX_VIRTUAL_BANK_ID', 900000001),

    'code' => 'sandbox_ru_receipt',

    /** Fixed English label for API JSON only (client localizes UI). */
    'name_en' => 'XBoard Sandbox Receipt Bank',

    'swift_code' => 'XBRDRUMMXXX',

    /** Public website host (project-style subdomain). */
    'website_host' => env('SANDBOX_VIRTUAL_BANK_WEBSITE', 'sandbox-receipt.xdexboard.com'),

    /** Must match `countries.code` for the receipt-bank country (e.g. RUS). */
    'country_code' => strtoupper(trim((string) env('SANDBOX_VIRTUAL_BANK_COUNTRY_CODE', 'RUS'))),

    /** ISO 4217 fiat codes the synthetic bank supports in API/UI (`supported_fiat_codes` in JSON). */
    'supported_fiat_codes' => ['USD', 'EUR'],

    /**
     * Additional API hostnames (lowercase) where the virtual bank is merged into bank lists and
     * accepted for lot flows — besides hosts matching ^(sandbox.|dev.) (see SandboxVirtualBankService).
     * Comma-separated env, e.g. LAN IP for local devices: "192.168.1.10,my-api.test".
     * Never point this at a production public API unless that deployment is sandbox-only.
     */
    'extra_prepend_hosts' => array_values(array_filter(array_map(
        static fn (string $h): string => strtolower(trim($h)),
        explode(',', (string) env('SANDBOX_VIRTUAL_BANK_EXTRA_PREPEND_HOSTS', ''))
    ))),

    /**
     * MIME header set on synthetic receipt emails so verify-sender can recognize them in sandbox
     * without whitelisting all traffic from MAIL_FROM_DOMAIN.
     */
    'receipt_mime_header_name' => 'X-XBoard-Sandbox-Test-Bank-Receipt',
    'receipt_mime_header_value' => '1',
];
