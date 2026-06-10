<?php

return [
    /**
     * Maximum exchange_lots rows per seller escrow token (wallet_hash) in this API database.
     * Enforced on POST /exchange-lots (lot create). Set to 0 to disable the cap.
     */
    'max_lots_per_escrow_token' => (int) env('LOT_MAX_LOTS_PER_ESCROW_TOKEN', 10),
];
