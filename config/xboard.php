<?php

return [

    /*
    |--------------------------------------------------------------------------
    | IP lookup URL template (partner-facing)
    |--------------------------------------------------------------------------
    |
    | Returned in GET /api/v1/nodes response meta as "ip_lookup_url_template".
    | The Android client substitutes {ip} with a percent-encoded IPv4/IPv6 address.
    | Use any regional or partner-specific public lookup tool that accepts the IP in URL.
    |
    | Examples:
    |   https://ipinfo.io/{ip}
    |   https://example.com/tools/ip?q={ip}
    |
    */

    'ip_lookup_url_template' => env('IP_LOOKUP_URL_TEMPLATE', 'https://ipinfo.io/{ip}'),

    /*
    |--------------------------------------------------------------------------
    | Block explorer URL templates (operator arbitration mail)
    |--------------------------------------------------------------------------
    |
    | Keys = Core crypto_type (e.g. xmr, btc, usdt_tron). Placeholder {tx} = transaction id/hash.
    | HTTPS only. Missing key → no explorer button for that proof.
    |
    */

    'tx_explorer_url_templates' => [
        'production' => [
            'btc' => 'https://blockstream.info/tx/{tx}',
            'xmr' => 'https://xmrchain.net/tx/{tx}',
            'xrp' => 'https://xrpscan.com/tx/{tx}',
            'usdt_tron' => 'https://tronscan.org/#/transaction/{tx}',
            'usdt_stellar' => 'https://stellarchain.io/tx/{tx}',
            'usdc_polygon' => 'https://polygonscan.com/tx/{tx}',
            'eth' => 'https://etherscan.io/tx/{tx}',
        ],
        'sandbox' => [
            'btc' => 'https://blockstream.info/testnet/tx/{tx}',
            'xmr' => 'https://stagenet.xmrchain.net/tx/{tx}',
            'xrp' => 'https://testnet.xrpl.org/transactions/{tx}',
            'usdt_tron' => 'https://nile.tronscan.org/#/transaction/{tx}',
            'usdt_stellar' => 'https://testnet.stellarchain.io/tx/{tx}',
            'usdc_polygon' => 'https://amoy.polygonscan.com/tx/{tx}',
            'eth' => 'https://sepolia.etherscan.io/tx/{tx}',
        ],
    ],

];
