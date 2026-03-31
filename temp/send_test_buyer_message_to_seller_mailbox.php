<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Mail\HighDeliveryPriority;
use Illuminate\Support\Facades\Mail;

$to = 'usdt_tron_tdjputgwmqx6mcfd5ax9yerzkyca2hpltm@xdexboard.com';
$subject = 'Deal message — Lot #8';
$body = $argv[1] ?? 'Cursor test buyer message';
$tagJson = '{"t":"buyer_message","lot_id":"8"}';

echo "Sending test buyer_message mail...\n";
echo "To: {$to}\n";
echo "Subject: {$subject}\n";
echo "Body: {$body}\n";
echo "Tag: {$tagJson}\n\n";

Mail::raw($body, function ($message) use ($to, $subject, $tagJson) {
    $message->to($to);
    $message->subject($subject);
    $message->from(config('mail.from.address', 'noreply@xdexboard.com'), config('mail.from.name', 'XBoard'));
    $message->getHeaders()->addTextHeader('X-XBoard-Deal-Tag', $tagJson);
    HighDeliveryPriority::apply($message);
});

echo "Sent.\n";
