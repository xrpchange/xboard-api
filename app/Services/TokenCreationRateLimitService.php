<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TokenCreationRateLimitService
{
    private const LOG_TAG = 'token-rate-limit';

    public function __construct() {}

    /**
     * Domain of the mail sender (from config). Emails on this domain are allowed
     * for token creation without being in email_providers (server/own domain).
     */
    private function getSenderDomain(): string
    {
        $address = config('mail.from.address', '');
        if ($address === '' || !str_contains($address, '@')) {
            return '';
        }
        return strtolower(trim(substr($address, strrpos($address, '@') + 1)));
    }

    /**
     * Check if email domain is allowed: either in email_providers (user providers)
     * or equals the mail sender domain (server domain, identified from config).
     */
    public function isEmailAllowed(string $email): bool
    {
        $email = trim(strtolower($email));
        if (!str_contains($email, '@')) {
            Log::info(self::LOG_TAG . ' isEmailAllowed: no @ in email', ['email_length' => strlen($email)]);
            return false;
        }
        $domain = substr($email, strrpos($email, '@') + 1);
        if ($domain === '') {
            Log::info(self::LOG_TAG . ' isEmailAllowed: empty domain', ['email' => $email]);
            return false;
        }

        $senderDomain = $this->getSenderDomain();
        $mailFromAddress = config('mail.from.address', '');
        Log::info(self::LOG_TAG . ' isEmailAllowed check', [
            'email_domain' => $domain,
            'mail_from_address' => $mailFromAddress,
            'sender_domain' => $senderDomain,
        ]);

        if ($senderDomain !== '' && $domain === $senderDomain) {
            Log::info(self::LOG_TAG . ' isEmailAllowed: allowed (sender domain match)');
            return true;
        }

        // Any seeded row with this domain counts (including is_active=false), so e.g. gmail.com
        // stays allowed for escrow email while Gmail is hidden from GET /v1/email-providers.
        $inProviders = DB::table('email_providers')
            ->whereNotNull('domain')
            ->where('domain', $domain)
            ->exists();

        Log::info(self::LOG_TAG . ' isEmailAllowed result', [
            'email_domain' => $domain,
            'in_email_providers' => $inProviders,
            'allowed' => $inProviders,
        ]);

        return $inProviders;
    }

    /**
     * Get current month count for this email in the given environment.
     */
    public function getEmailCount(string $email, string $environment): int
    {
        $email = trim(strtolower($email));
        $period = date('Y-m');
        $row = DB::table('token_creation_quota')
            ->where('email', $email)
            ->where('period_month', $period)
            ->where('environment', $environment)
            ->first();

        return $row ? (int) $row->count : 0;
    }

    /**
     * Get email limit for environment (production 10, sandbox 30).
     */
    public function getEmailLimit(string $environment): int
    {
        $env = $environment === 'production' ? 'production' : 'sandbox';
        return (int) config("rate_limit.token_creation.email_per_month_{$env}", $env === 'production' ? 10 : 30);
    }

    /**
     * Get today's count for this IP.
     */
    public function getIpCount(string $ip): int
    {
        $date = date('Y-m-d');
        $row = DB::table('token_creation_ip_daily')
            ->where('ip_address', $ip)
            ->where('date', $date)
            ->first();

        return $row ? (int) $row->count : 0;
    }

    /**
     * Get IP limit (per day).
     */
    public function getIpLimit(): int
    {
        return (int) config('rate_limit.token_creation.ip_per_day', 20);
    }

    /**
     * Increment email quota after successful creation.
     */
    public function incrementEmail(string $email, string $environment): void
    {
        $email = trim(strtolower($email));
        $period = date('Y-m');
        $exists = DB::table('token_creation_quota')
            ->where('email', $email)
            ->where('period_month', $period)
            ->where('environment', $environment)
            ->exists();

        if ($exists) {
            DB::table('token_creation_quota')
                ->where('email', $email)
                ->where('period_month', $period)
                ->where('environment', $environment)
                ->increment('count', 1, ['updated_at' => now()]);
        } else {
            DB::table('token_creation_quota')->insert([
                'email' => $email,
                'period_month' => $period,
                'environment' => $environment,
                'count' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Check and consume one "verification code request" for this IP (per hour).
     * Returns true if under limit and counter was incremented, false if limit exceeded.
     */
    public function checkAndIncrementVerificationCodeRequestByIp(string $ip): bool
    {
        $limit = (int) config('rate_limit.verification_code_request.ip_per_hour', 10);
        $key = 'verification_code_requests_ip:' . $ip . ':' . date('YmdH');
        $count = (int) Cache::get($key, 0);
        if ($count >= $limit) {
            return false;
        }
        Cache::put($key, $count + 1, now()->addHours(2));
        return true;
    }

    /**
     * Check and consume one "verification code request" for this email (per hour).
     * Returns true if under limit and counter was incremented, false if limit exceeded.
     */
    public function checkAndIncrementVerificationCodeRequestByEmail(string $email): bool
    {
        $limit = (int) config('rate_limit.verification_code_request.email_per_hour', 5);
        $email = trim(strtolower($email));
        $key = 'verification_code_requests_email:' . $email . ':' . date('YmdH');
        $count = (int) Cache::get($key, 0);
        if ($count >= $limit) {
            return false;
        }
        Cache::put($key, $count + 1, now()->addHours(2));
        return true;
    }

    /**
     * Increment IP daily count after successful creation.
     */
    public function incrementIp(string $ip): void
    {
        $date = date('Y-m-d');
        $exists = DB::table('token_creation_ip_daily')
            ->where('ip_address', $ip)
            ->where('date', $date)
            ->exists();

        if ($exists) {
            DB::table('token_creation_ip_daily')
                ->where('ip_address', $ip)
                ->where('date', $date)
                ->increment('count', 1, ['updated_at' => now()]);
        } else {
            DB::table('token_creation_ip_daily')->insert([
                'ip_address' => $ip,
                'date' => $date,
                'count' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
