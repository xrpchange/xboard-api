<?php

$trainingSecret = static function (string $envKey, string $storageFile): ?string {
    $fromEnv = env($envKey);
    if (is_string($fromEnv) && trim($fromEnv) !== '') {
        return trim($fromEnv);
    }
    $path = storage_path('app/'.$storageFile);
    if (is_readable($path)) {
        $contents = file_get_contents($path);

        return is_string($contents) && trim($contents) !== '' ? trim($contents) : null;
    }

    return null;
};

$tokenJson = $trainingSecret('TRAINING_SELLER_ESCROW_TOKEN', 'training_seller_token.json');
$publicJson = $trainingSecret('TRAINING_SELLER_ESCROW_PUBLIC_JSON', 'training_seller_public.json');
if (($publicJson === null || $publicJson === '') && is_string($tokenJson) && $tokenJson !== '') {
    $publicJson = \App\Support\TrainingDealEscrowTokenFormat::publicPartJson($tokenJson);
}

return [
    /*
    | Sandbox-only automated seller for lots with exchange_lots.is_training = true.
    | Secrets: env vars OR storage/app/training_seller_token.json (full export; gitignored).
    */
    'enabled' => filter_var(env('TRAINING_DEAL_ENABLED', false), FILTER_VALIDATE_BOOL),

    /** Full seller escrow token JSON (export or {public_part, encrypted_part}) for Core. */
    'seller_escrow_token' => $tokenJson,

    /** Seller escrow public JSON — stored on the lot as wallet_address when seeding. */
    'seller_escrow_public_json' => $publicJson,

    'seller_notification_email' => env('TRAINING_SELLER_NOTIFICATION_EMAIL', 'training-seller@xdexboard.com'),

    'lot_node_id' => env('TRAINING_LOT_NODE_ID'),

    'lot_sell_amount' => (float) env('TRAINING_LOT_SELL_AMOUNT', 5),
    'lot_buy_amounts' => array_values(array_filter(array_map('floatval', explode(',', (string) env('TRAINING_LOT_BUY_AMOUNTS', '5'))))),
    'lot_note_fiat_crypto' => env('TRAINING_LOT_NOTE_FIAT_CRYPTO', 'Training: USD → USDT (Stellar)'),
    'lot_note_crypto_fiat' => env('TRAINING_LOT_NOTE_CRYPTO_FIAT', 'Training: USDT (Stellar) → USD'),
    'virtual_bank_payment_details' => env(
        'TRAINING_VIRTUAL_BANK_PAYMENT_DETAILS',
        'Sandbox virtual bank (training). Confirm payment using the practice receipt in your deal mailbox.'
    ),
];
