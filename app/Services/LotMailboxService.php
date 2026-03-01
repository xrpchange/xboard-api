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
            Log::info('[lot-mailbox] Maildir already exists', ['path' => $path]);
            return;
        }
        if (@mkdir($path, 0750, true)) {
            @mkdir($cur, 0750, true);
            @mkdir($new, 0750, true);
            @mkdir($tmp, 0750, true);
            Log::info('[lot-mailbox] Created Maildir on disk', ['path' => $path]);
        } else {
            Log::warning('[lot-mailbox] Failed to create Maildir (check path and permissions)', [
                'path' => $path,
                'base' => $base,
            ]);
        }
    }
}
