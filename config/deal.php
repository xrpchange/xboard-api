<?php

return [
    // Maximum number of chat messages stored per deal (per exchange_lot_id).
    'max_messages_per_deal' => (int) env('DEAL_CHAT_MAX_MESSAGES', 1000),

    // Maximum length of a single chat message body (in characters).
    'max_message_length' => (int) env('DEAL_CHAT_MAX_MESSAGE_LENGTH', 1000),
];

