<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class TokenCreationRateLimitService
{
    public function __construct() {}

    /**
     * Check if email domain is allowed (present in email_providers).
     */
    public function isEmailAllowed(string $email): bool
    {
        $email = trim(strtolower($email));
        if (!str_contains($email, '@')) {
            return false;
        }
        $domain = substr($email, strrpos($email, '@') + 1);
        if ($domain === '') {
            return false;
        }

        return DB::table('email_providers')
            ->where('is_active', true)
            ->whereNotNull('domain')
            ->where('domain', $domain)
            ->exists();
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
