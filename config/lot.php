<?php

return [
    /**
     * Maximum exchange_lots rows per seller escrow token (wallet_hash) in this API database.
     * Enforced on POST /exchange-lots (lot create). Set to 0 to disable the cap.
     */
    'max_lots_per_escrow_token' => (int) env('LOT_MAX_LOTS_PER_ESCROW_TOKEN', 10),

    /**
     * Default page size for GET /exchange-lots (public search). Client may pass per_page up to search_page_size_max.
     */
    'search_page_size' => max(1, (int) env('LOT_SEARCH_PAGE_SIZE', 50)),

    /** Hard cap for per_page query param on search. */
    'search_page_size_max' => max(1, (int) env('LOT_SEARCH_PAGE_SIZE_MAX', 100)),
];
