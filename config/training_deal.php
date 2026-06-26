<?php

return [
    /*
    | Sandbox-only automated seller for lots with exchange_lots.is_training = true.
    | Set TRAINING_DEAL_ENABLED=true and provide treasury seller tokens on sandbox API hosts.
    */
    'enabled' => filter_var(env('TRAINING_DEAL_ENABLED', false), FILTER_VALIDATE_BOOL),

    /** Full seller escrow token JSON (public + encrypted) for Core reservations and payouts. */
    'seller_escrow_token' => env('TRAINING_SELLER_ESCROW_TOKEN'),

    /** Seller escrow public JSON only — stored on the lot as wallet_address when seeding. */
    'seller_escrow_public_json' => env('TRAINING_SELLER_ESCROW_PUBLIC_JSON'),

    'seller_notification_email' => env('TRAINING_SELLER_NOTIFICATION_EMAIL', 'training-seller@xdexboard.com'),

    /** Optional explicit node id; when empty, first active node is used. */
    'lot_node_id' => env('TRAINING_LOT_NODE_ID'),

    'lot_sell_amount' => (float) env('TRAINING_LOT_SELL_AMOUNT', 5),
    'lot_buy_amounts' => array_values(array_filter(array_map('floatval', explode(',', (string) env('TRAINING_LOT_BUY_AMOUNTS', '5'))))),
    'lot_note' => env('TRAINING_LOT_NOTE', 'Training deal — automated seller (USDT Stellar testnet).'),
];
