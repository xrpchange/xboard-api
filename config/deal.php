<?php

return [
    // Maximum number of chat messages stored per deal (per exchange_lot_id).
    'max_messages_per_deal' => (int) env('DEAL_CHAT_MAX_MESSAGES', 1000),

    // Maximum length of a single chat message body (in characters).
    'max_message_length' => (int) env('DEAL_CHAT_MAX_MESSAGE_LENGTH', 1000),

    /**
     * Cap AI arbiter **invocations** per lot: each POST /arbiter-message reserves one slot atomically
     * *before* calling the LLM so failures cannot be replayed to drain budget. Operator mail replies are unlimited.
     * Set to 0 to disable (not recommended in production).
     */
    'max_ai_arbiter_invocations_per_lot' => (int) env('DEAL_MAX_AI_ARBITER_INVOCATIONS_PER_LOT', (int) env('DEAL_MAX_AI_ARBITER_MESSAGES_PER_LOT', 30)),
];

