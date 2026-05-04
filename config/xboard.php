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

];
