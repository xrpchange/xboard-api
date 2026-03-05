<?php

namespace App\Services;

use App\Models\LotMailbox;
use Illuminate\Support\Facades\Log;

/**
 * Create or get mailbox for lot chat (IMAP).
 * Format: {currency}_{normalized_address}@xdexboard.com
 * XMR: use only primary from wallet_address JSON.
 * Others (USDT/Tron, XRP, etc.): extract wallet_address from JSON if present, else use plain address.
 */
class LotMailboxService
{
    public function __construct(
        private readonly string $domain,
        private readonly ?string $maildirBase = null
    ) {
    }

    /**
     * Create or get mailbox. Returns config for client.
     *
     * @param string $walletAddress JSON or plain address from escrow token
     * @param string $tokenHash from escrow token (used as IMAP password)
     * @param string $currency e.g. xrp, xmr, usdt_stellar
     * @return array{email: string, imap_host: string, imap_port: int, imap_ssl: bool, password: string} | null
     */
    public function ensureMailbox(string $walletAddress, string $tokenHash, string $currency): ?array
    {
        Log::info('[lot-mailbox] ensureMailbox start', [
            'currency' => $currency,
            'wallet_preview' => substr($walletAddress, 0, 80) . (strlen($walletAddress) > 80 ? '...' : ''),
        ]);

        $normalizedAddress = $this->normalizeAddress($walletAddress, $currency);
        if ($normalizedAddress === null) {
            Log::warning('[lot-mailbox] ensureMailbox aborted: failed to normalize address (no DB record, no Maildir)', [
                'currency' => $currency,
                'wallet_preview' => substr($walletAddress, 0, 100),
            ]);
            return null;
        }

        $localPart = $this->buildLocalPart($currency, $normalizedAddress);

        $mailbox = LotMailbox::where('local_part', $localPart)->first();
        $isNew = false;

        if (!$mailbox) {
            $mailbox = LotMailbox::create([
                'local_part' => $localPart,
                'domain' => $this->domain,
                'password_hash' => password_hash($tokenHash, PASSWORD_BCRYPT),
                'currency' => $currency,
                'is_test' => !app()->environment('production'),
            ]);
            $isNew = true;
            Log::info('[lot-mailbox] Created new mailbox (DB record)', [
                'local_part' => $localPart,
                'domain' => $this->domain,
                'mailbox_id' => $mailbox->id,
            ]);
            $this->createMaildirIfNeeded($localPart);
        } else {
            Log::info('[lot-mailbox] Found existing mailbox (DB)', ['local_part' => $localPart, 'mailbox_id' => $mailbox->id]);
        }

        $imapHost = config('services.lot_mailbox.imap_host', 'mail.' . $this->domain);
        $smtpHost = config('services.lot_mailbox.smtp_host', $imapHost);

        $result = [
            'email' => $mailbox->email,
            'imap_host' => $imapHost,
            'imap_port' => (int) config('services.lot_mailbox.imap_port', 993),
            'imap_ssl' => (bool) config('services.lot_mailbox.imap_ssl', true),
            'smtp_host' => $smtpHost,
            'smtp_port' => (int) config('services.lot_mailbox.smtp_port', 587),
            'smtp_ssl' => (bool) config('services.lot_mailbox.smtp_ssl', true),
            'password' => $tokenHash,
        ];
        Log::info('[lot-mailbox] ensureMailbox done', [
            'email' => $result['email'],
            'is_new' => $isNew,
        ]);
        return $result;
    }

    /**
     * Очистка писем из Maildir после успешного завершения сделки.
     * Вызывать ТОЛЬКО после финализации сделки. Удаляет содержимое cur/, new/, tmp/.
     * Запись в lot_mailboxes не трогается (ящик остаётся, но пустой).
     *
     * @param string $walletAddress JSON или plain address из эскроу-токена лота
     * @param string $currency e.g. xrp, xmr, usdt_stellar
     * @return bool true если письма удалены или папка не существовала
     */
    public function cleanupMailbox(string $walletAddress, string $currency): bool
    {
        $normalizedAddress = $this->normalizeAddress($walletAddress, $currency);
        if ($normalizedAddress === null) {
            Log::debug('[lot-mailbox] cleanupMailbox: failed to normalize', ['currency' => $currency]);
            return false;
        }
        $localPart = $this->buildLocalPart($currency, $normalizedAddress);

        $base = $this->maildirBase ?? config('services.lot_mailbox.maildir_base');
        if (empty($base)) {
            Log::debug('[lot-mailbox] cleanupMailbox: maildir_base not configured');
            return false;
        }

        $path = rtrim($base, '/') . '/' . $this->domain . '/' . $localPart;
        if (!is_dir($path)) {
            Log::debug('[lot-mailbox] cleanupMailbox: path does not exist', ['path' => $path]);
            return true;
        }

        $deleted = 0;
        foreach (['cur', 'new', 'tmp'] as $sub) {
            $subPath = $path . '/' . $sub;
            if (is_dir($subPath)) {
                foreach (glob($subPath . '/*') ?: [] as $file) {
                    if (is_file($file) && @unlink($file)) {
                        $deleted++;
                    }
                }
            }
        }

        Log::info('[lot-mailbox] cleanupMailbox: deleted emails', [
            'local_part' => $localPart,
            'files_deleted' => $deleted,
        ]);
        return true;
    }

    /**
     * Get seller mailbox email for a lot (by wallet_address and currency).
     * Used when sending a message to the seller — e.g. "start deal" notification.
     *
     * @return string|null Full email (local_part@domain) or null if mailbox not found
     */
    public function getMailboxEmailForAddress(string $walletAddress, string $currency): ?string
    {
        $normalizedAddress = $this->normalizeAddress($walletAddress, $currency);
        $localPart = $normalizedAddress !== null ? $this->buildLocalPart($currency, $normalizedAddress) : null;
        $mailbox = $localPart !== null ? LotMailbox::where('local_part', $localPart)->where('domain', $this->domain)->first() : null;

        // Fallback: mailbox may have been created with a different currency prefix (e.g. usdt_tron vs usdt).
        // Find any mailbox whose local_part ends with _normalizedAddress; prefer one matching $currency.
        if ($mailbox === null && $normalizedAddress !== null && $normalizedAddress !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $normalizedAddress);
            $candidates = LotMailbox::where('domain', $this->domain)
                ->where('local_part', 'like', '%\_' . $escaped)
                ->get();
            if ($candidates->count() === 1) {
                $mailbox = $candidates->first();
            } elseif ($candidates->count() > 1) {
                $currencyPrefix = preg_replace('/[^a-z0-9_]/', '', strtolower($currency)) ?: 'crypto';
                $preferred = $candidates->first(fn ($m) => str_starts_with(strtolower($m->local_part), $currencyPrefix . '_'));
                $mailbox = $preferred ?? $candidates->first();
            }
        }

        $email = $mailbox ? $mailbox->email : null;

        Log::info('[lot-mailbox] getMailboxEmailForAddress', [
            'wallet_address_len' => strlen($walletAddress),
            'wallet_address_preview' => substr($walletAddress, 0, 80) . (strlen($walletAddress) > 80 ? '...' : ''),
            'currency' => $currency,
            'normalized_address' => $normalizedAddress !== null ? substr($normalizedAddress, 0, 40) . (strlen($normalizedAddress) > 40 ? '...' : '') : null,
            'local_part' => $localPart,
            'found_email' => $email,
        ]);

        return $email;
    }

    /**
     * Get normalized address for a wallet (same logic as mailbox lookup).
     * Used to compare requester token with seller mailbox when currency prefix may differ.
     */
    public function getNormalizedAddress(string $walletAddress, string $currency): ?string
    {
        return $this->normalizeAddress($walletAddress, $currency);
    }

    /**
     * Get Maildir filesystem path for a mailbox by its email address.
     *
     * @return string|null path (e.g. /var/mail/vhosts/domain/local_part) or null if not found
     */
    public function getMaildirPathByEmail(string $email): ?string
    {
        $email = trim($email);
        if ($email === '' || !str_contains($email, '@')) {
            return null;
        }
        $parts = explode('@', $email, 2);
        $localPart = $parts[0];
        $domainPart = $parts[1] ?? $this->domain;
        $mailbox = LotMailbox::where('local_part', $localPart)->where('domain', $domainPart)->first();
        if (!$mailbox) {
            return null;
        }
        $base = $this->maildirBase ?? config('services.lot_mailbox.maildir_base');
        if (empty($base)) {
            return null;
        }
        return rtrim($base, '/') . '/' . $domainPart . '/' . $localPart;
    }

    /**
     * List deal messages from a mailbox Maildir (for chat thread). Scans cur/ and new/, filters by lot id in subject/body.
     *
     * @param string $maildirPath full path to mailbox Maildir
     * @param string $lotId lot id (e.g. "123")
     * @param bool $requesterIsSeller true if the requester is the seller (lot owner)
     * @return array<int, array{date: string, body: string, from_me: bool}> sorted by date ascending
     */
    public function getDealMessages(string $maildirPath, string $lotId, bool $requesterIsSeller): array
    {
        $candidates = [];
        foreach (['new', 'cur'] as $folder) {
            $dir = $maildirPath . '/' . $folder;
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*') ?: [] as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $content = @file_get_contents($file);
                if ($content === false) {
                    continue;
                }
                $hasLotId = stripos($content, $lotId) !== false;
                if (!$hasLotId) {
                    continue;
                }
                $subjectLine = null;
                if (preg_match('/^Subject:\s*(.+?)(?=\r?\n(?![\s\t])|\z)/ms', $content, $m)) {
                    $subjectLine = preg_replace('/\r?\n[\s\t]+/', ' ', trim($m[1]));
                    $subjectLine = $this->decodeMimeHeader($subjectLine);
                }
                if ($subjectLine !== null && stripos($subjectLine, $lotId) === false) {
                    continue;
                }
                $body = $this->extractBodyFromMaildirMessage($content);
                $fromSeller = $this->isMessageFromSeller($body, $lotId);
                $from_me = $fromSeller === $requesterIsSeller;
                $messageBody = $this->extractMessageTextFromBody($body, $lotId, $fromSeller);
                $date = date('c', filemtime($file));
                $candidates[] = [
                    'date' => $date,
                    'body' => $messageBody,
                    'from_me' => $from_me,
                ];
            }
        }
        usort($candidates, fn ($a, $b) => strcmp($a['date'], $b['date']));
        return array_values($candidates);
    }

    private function extractBodyFromMaildirMessage(string $raw): string
    {
        $parts = preg_split('/\r?\n\r?\n/', $raw, 2);
        return $parts[1] ?? '';
    }

    private function isMessageFromSeller(string $body, string $lotId): bool
    {
        if (stripos($body, 'Buyer (sent):') === 0) {
            return false;
        }
        $prefix = 'Message from seller (Lot #' . $lotId . '):';
        return stripos($body, $prefix) === 0 || str_contains($body, 'Message from seller (Lot #');
    }

    /**
     * Extract the actual message text from email body (after "Buyer's message:" or "Message from seller (Lot #X):" or "Buyer (sent):").
     */
    private function extractMessageTextFromBody(string $body, string $lotId, bool $fromSeller): string
    {
        if (stripos($body, 'Buyer (sent):') === 0) {
            return trim(substr($body, strlen('Buyer (sent):')));
        }
        if ($fromSeller) {
            $prefix = 'Message from seller (Lot #' . $lotId . '):';
            $pos = stripos($body, $prefix);
            if ($pos !== false) {
                return trim(substr($body, $pos + strlen($prefix)));
            }
        }
        foreach (["Buyer's message:", "Сообщение покупателя:", "Message de l'acheteur :"] as $needle) {
            $pos = stripos($body, $needle);
            if ($pos !== false) {
                return trim(substr($body, $pos + strlen($needle)));
            }
        }
        return $body;
    }

    /**
     * Write a copy of a buyer-sent message into the buyer's Maildir so they see it in their deal thread.
     */
    public function writeBuyerSentCopyToMailbox(string $buyerMailboxEmail, string $lotId, string $messageText): bool
    {
        $path = $this->getMaildirPathByEmail($buyerMailboxEmail);
        if ($path === null || !is_dir($path)) {
            return false;
        }
        $newDir = $path . '/new';
        if (!is_dir($newDir)) {
            return false;
        }
        $subject = 'Deal message — Lot #' . $lotId;
        $body = "Buyer (sent):\n\n" . $messageText;
        $raw = "Subject: " . $subject . "\n\n" . $body;
        $filename = $newDir . '/' . (string) (time() . '.' . gethostname() . '.' . getmypid() . '.eml');
        return (bool) @file_put_contents($filename, $raw);
    }

    /**
     * Get deal message delivery/read status by scanning seller's Maildir (IMAP backend).
     * Looks for the most recent message whose Subject contains "Lot #{$lotId}".
     * delivered = message found in INBOX (cur/ or new/); read = message in cur/ with \Seen flag (filename contains ",S").
     *
     * @return array{delivered: bool, read: bool}|null null if mailbox/path not available
     */
    public function getDealMessageStatus(string $walletAddress, string $currency, string $lotId): ?array
    {
        $normalizedAddress = $this->normalizeAddress($walletAddress, $currency);
        if ($normalizedAddress === null) {
            return null;
        }
        $localPart = $this->buildLocalPart($currency, $normalizedAddress);
        $base = $this->maildirBase ?? config('services.lot_mailbox.maildir_base');
        if (empty($base)) {
            Log::info('[lot-mailbox] getDealMessageStatus: maildir_base not configured');
            return null;
        }
        $path = rtrim($base, '/') . '/' . $this->domain . '/' . $localPart;
        if (!is_dir($path)) {
            return ['delivered' => false, 'read' => false];
        }
        // Subject contains "Lot #{$lotId}" (en) or "Лот #{$lotId}" (ru); match by lot id (decode MIME Subject if needed)
        $candidates = [];
        foreach (['new', 'cur'] as $folder) {
            $dir = $path . '/' . $folder;
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*') ?: [] as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $content = @file_get_contents($file, false, null, 0, 8192);
                if ($content === false) {
                    continue;
                }
                $subjectLine = null;
                // Subject can be folded (continued on next line with space/tab); extract full header
                if (preg_match('/^Subject:\s*(.+?)(?=\r?\n(?![\s\t])|\z)/ms', $content, $m)) {
                    $subjectLine = preg_replace('/\r?\n[\s\t]+/', ' ', trim($m[1]));
                    $subjectLine = $this->decodeMimeHeader($subjectLine);
                }
                $hasLotId = stripos($content, $lotId) !== false
                    || ($subjectLine !== null && stripos($subjectLine, $lotId) !== false);
                if (!$hasLotId) {
                    continue;
                }
                $basename = basename($file);
                // Maildir/Dovecot: cur/ + filename has :2,FLAGS with S (Seen); e.g. :2,S or :2,RS
                $flagsPart = preg_match('/:2,([^\s]+)$/', $basename, $flagsMatch) ? $flagsMatch[1] : '';
                $seen = $folder === 'cur' && (
                    str_contains(strtoupper($flagsPart), 'S')
                    || str_contains($basename, ':2,S')
                );
                $candidates[] = [
                    'file' => $file,
                    'mtime' => filemtime($file),
                    'in_cur' => $folder === 'cur',
                    'seen' => $seen,
                ];
            }
        }
        if (empty($candidates)) {
            Log::info('[lot-mailbox] getDealMessageStatus: no matching message', ['lot_id' => $lotId, 'path' => $path]);
            return ['delivered' => false, 'read' => false];
        }
        usort($candidates, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);
        $latest = $candidates[0];
        $read = $latest['in_cur'] && $latest['seen'];
        Log::info('[lot-mailbox] getDealMessageStatus', [
            'lot_id' => $lotId,
            'path' => $path,
            'file' => basename($latest['file']),
            'in_cur' => $latest['in_cur'],
            'seen' => $latest['seen'],
            'read_result' => $read,
        ]);
        return [
            'delivered' => true,
            'read' => $read,
        ];
    }

    /**
     * Try to delete mailbox when escrow token is removed. Non-blocking — failures are logged only.
     */
    public function deleteByToken(string $walletAddress, string $tokenHash, string $currency): bool
    {
        $normalizedAddress = $this->normalizeAddress($walletAddress, $currency);
        if ($normalizedAddress === null) {
            Log::debug('[lot-mailbox] deleteByToken: failed to normalize', ['currency' => $currency]);
            return false;
        }
        $localPart = $this->buildLocalPart($currency, $normalizedAddress);
        $deleted = LotMailbox::where('local_part', $localPart)->where('domain', $this->domain)->delete();
        if ($deleted > 0) {
            Log::info('[lot-mailbox] Deleted mailbox', ['local_part' => $localPart]);
        }
        return $deleted > 0;
    }

    private function normalizeAddress(string $walletAddress, string $currency): ?string
    {
        $decoded = json_decode($walletAddress, true);
        if (is_array($decoded)) {
            $currencyLower = strtolower($currency);
            // XMR: use primary (subaddress) from JSON
            if ($currencyLower === 'xmr') {
                if (!empty($decoded['primary'])) {
                    return $this->sanitizeLocalPart($decoded['primary']);
                }
            }
            // Others (USDT/Tron, XRP, etc.): extract wallet_address or address from JSON (never include version)
            $address = $decoded['wallet_address'] ?? $decoded['address'] ?? null;
            if (is_string($address) && $address !== '') {
                $address = $this->extractAddressFromNestedJson($address);
                if ($address !== '') {
                    return $this->sanitizeLocalPart($address);
                }
            }
        }
        // Fallback: treat as plain address (e.g. single string)
        return $this->sanitizeLocalPart($walletAddress);
    }

    /**
     * Decode MIME encoded-word in headers (e.g. =?UTF-8?B?RGVhbCBtZXNzYWdl...?=).
     * Used so we can find lotId in Subject when it is encoded.
     */
    private function decodeMimeHeader(string $value): string
    {
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        return $decoded !== false ? $decoded : $value;
    }

    /**
     * If wallet_address/address is nested JSON (e.g. contains version), extract actual address only.
     * Never include version or other metadata in the mailbox name.
     */
    private function extractAddressFromNestedJson(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return $value;
        }
        $nested = json_decode($value, true);
        if (!is_array($nested)) {
            return $value;
        }
        $addr = $nested['wallet_address'] ?? $nested['address'] ?? $nested['primary'] ?? null;
        return is_string($addr) ? $addr : $value;
    }

    private function sanitizeLocalPart(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]/', '', $s) ?? $s;
        return substr($s, 0, 200);
    }

    private function buildLocalPart(string $currency, string $normalizedAddress): string
    {
        $currencySafe = preg_replace('/[^a-z0-9_]/', '', strtolower($currency)) ?: 'crypto';
        return $currencySafe . '_' . $normalizedAddress;
    }

    private function createMaildirIfNeeded(string $localPart): void
    {
        $base = $this->maildirBase ?? config('services.lot_mailbox.maildir_base');
        if (empty($base)) {
            Log::info('[lot-mailbox] Maildir NOT created: maildir_base not configured (only DB record exists; dir may be created by mail server or later)', [
                'local_part' => $localPart,
                'config_key' => 'services.lot_mailbox.maildir_base',
            ]);
            return;
        }
        $path = rtrim($base, '/') . '/' . $this->domain . '/' . $localPart;
        $cur = $path . '/cur';
        $new = $path . '/new';
        $tmp = $path . '/tmp';
        Log::info('[lot-mailbox] Attempting to create Maildir', ['path' => $path]);
        if (is_dir($path)) {
            $this->ensureTrashMaildir($path);
            Log::info('[lot-mailbox] Maildir already exists', ['path' => $path]);
            return;
        }
        if (@mkdir($path, 0750, true)) {
            @mkdir($cur, 0750, true);
            @mkdir($new, 0750, true);
            @mkdir($tmp, 0750, true);
            $this->ensureTrashMaildir($path);
            Log::info('[lot-mailbox] Created Maildir on disk', ['path' => $path]);
        } else {
            Log::warning('[lot-mailbox] Failed to create Maildir (check path and permissions)', [
                'path' => $path,
                'base' => $base,
            ]);
        }
    }

    /**
     * Ensure .Trash Maildir exists so IMAP clients (Canary, Outlook) can assign \Trash for delete/move.
     */
    private function ensureTrashMaildir(string $maildirPath): void
    {
        $trashPath = $maildirPath . '/.Trash';
        if (is_dir($trashPath)) {
            return;
        }
        @mkdir($trashPath, 0750, true);
        @mkdir($trashPath . '/cur', 0750, true);
        @mkdir($trashPath . '/new', 0750, true);
        @mkdir($trashPath . '/tmp', 0750, true);
    }
}
