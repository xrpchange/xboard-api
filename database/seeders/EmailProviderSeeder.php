<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmailProviderSeeder extends Seeder
{
    /**
     * Canonical data for `email_providers`. Replace entire table each run.
     *
     * Install / refresh:
     * - Full: `php artisan migrate` then `php artisan db:seed` (this class is in DatabaseSeeder).
     * - This table only: `php artisan db:seed --class=EmailProviderSeeder`.
     *
     * Policy (2025–2026): in-app IMAP uses password, app password, or provider codes — not
     * Google/Microsoft consumer OAuth in our product.
     *
     * - **is_active:** `GET /v1/email-providers` (client picker) returns only these rows.
     * - **domain:** escrow / verification allowlist matches any row with that `domain`, including
     *   `is_active=false` (e.g. gmail.com allowed for token email but hidden from IMAP picker).
     *
     * **sort_order:** ascending; CIS-first, then globally recommended providers.
     */
    public function run(): void
    {
        DB::table('email_providers')->truncate();

        $providers = [
            // ---------- Active: CIS-first, then global recommended ----------
            [
                'name' => 'Yandex',
                'domain' => 'yandex.ru',
                'imap_host' => 'imap.yandex.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.yandex.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 1,
                // Password or app password (2FA). Enable IMAP in mailbox settings; app passwords: Yandex ID → Security.
            ],
            [
                'name' => 'Mail.ru',
                'domain' => 'mail.ru',
                'imap_host' => 'imap.mail.ru',
                'imap_port' => 993,
                'pop3_host' => 'pop.mail.ru',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 2,
                // Password or app password (2FA). Enable IMAP/POP in settings; app passwords in Mail settings.
            ],
            [
                'name' => 'Rambler',
                'domain' => 'rambler.ru',
                'imap_host' => 'imap.rambler.ru',
                'imap_port' => 993,
                'pop3_host' => 'pop.rambler.ru',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'Inbox.ru',
                'domain' => 'inbox.ru',
                'imap_host' => 'imap.inbox.ru',
                'imap_port' => 993,
                'pop3_host' => 'pop.inbox.ru',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 4,
                // Mail.ru family; same IMAP/POP policy as Mail.ru.
            ],
            [
                'name' => 'List.ru',
                'domain' => 'list.ru',
                'imap_host' => 'imap.list.ru',
                'imap_port' => 993,
                'pop3_host' => 'pop.list.ru',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 5,
                // Mail.ru family.
            ],
            [
                'name' => 'iCloud',
                'domain' => 'icloud.com',
                'imap_host' => 'imap.mail.me.com',
                'imap_port' => 993,
                'pop3_host' => null,
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 6,
                // App-specific password (appleid.apple.com). IMAP only — no POP3.
            ],
            [
                'name' => 'Yahoo Mail',
                'domain' => 'yahoo.com',
                'imap_host' => 'imap.mail.yahoo.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.mail.yahoo.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 7,
                // App password: Account Info → Account Security.
            ],
            [
                'name' => 'AT&T/Yahoo',
                'domain' => 'att.net',
                'imap_host' => 'imap.mail.att.net',
                'imap_port' => 993,
                'pop3_host' => 'inbound.att.net',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 8,
                // Yahoo infrastructure; app password like Yahoo.
            ],
            [
                'name' => 'AOL',
                'domain' => 'aol.com',
                'imap_host' => 'imap.aol.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.aol.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 9,
                // App password (Account Security), same family as Yahoo.
            ],
            [
                'name' => 'Zoho Mail',
                'domain' => 'zoho.com',
                'imap_host' => 'imap.zoho.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.zoho.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 10,
                // Password or app-specific password (2FA). Enable IMAP in Mail Accounts settings.
            ],
            [
                'name' => 'Posteo',
                'domain' => 'posteo.de',
                'imap_host' => 'posteo.de',
                'imap_port' => 993,
                'pop3_host' => 'posteo.de',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 11,
            ],
            [
                'name' => 'FastMail',
                'domain' => 'fastmail.com',
                'imap_host' => 'imap.fastmail.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.fastmail.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 12,
            ],
            [
                'name' => 'Mailfence',
                'domain' => 'mailfence.com',
                'imap_host' => 'imap.mailfence.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.mailfence.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 13,
            ],
            [
                'name' => 'Hushmail',
                'domain' => 'hushmail.com',
                'imap_host' => 'imap.hushmail.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.hushmail.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 14,
            ],
            [
                'name' => 'Runbox',
                'domain' => 'runbox.com',
                'imap_host' => 'imap.runbox.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.runbox.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 15,
            ],
            [
                'name' => 'StartMail',
                'domain' => 'startmail.com',
                'imap_host' => 'imap.startmail.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.startmail.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 16,
            ],
            [
                'name' => 'GMX',
                'domain' => 'gmx.com',
                'imap_host' => 'imap.gmx.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.gmx.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 17,
            ],
            [
                'name' => 'Web.de',
                'domain' => 'web.de',
                'imap_host' => 'imap.web.de',
                'imap_port' => 993,
                'pop3_host' => 'pop.web.de',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 18,
            ],
            [
                'name' => 'T-Online',
                'domain' => 't-online.de',
                'imap_host' => 'secureimap.t-online.de',
                'imap_port' => 993,
                'pop3_host' => 'securepop.t-online.de',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 19,
                // Separate "password for email programs" in Telekom account.
            ],
            [
                'name' => 'Orange',
                'domain' => 'orange.fr',
                'imap_host' => 'imap.orange.fr',
                'imap_port' => 993,
                'pop3_host' => 'pop.orange.fr',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 20,
            ],
            [
                'name' => 'SFR',
                'domain' => 'sfr.fr',
                'imap_host' => 'imap.sfr.fr',
                'imap_port' => 993,
                'pop3_host' => 'pop.sfr.fr',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 21,
            ],
            [
                'name' => 'Free.fr',
                'domain' => 'free.fr',
                'imap_host' => 'imap.free.fr',
                'imap_port' => 993,
                'pop3_host' => 'pop.free.fr',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 22,
            ],
            [
                'name' => 'Laposte.net',
                'domain' => 'laposte.net',
                'imap_host' => 'imap.laposte.net',
                'imap_port' => 993,
                'pop3_host' => 'pop.laposte.net',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 23,
            ],
            [
                'name' => 'Verizon/AOL',
                'domain' => 'verizon.net',
                'imap_host' => 'imap.aol.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.aol.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 24,
                // Legacy @verizon.net on AOL infra; app password like AOL/Yahoo.
            ],
            [
                'name' => 'Comcast/Xfinity',
                'domain' => 'comcast.net',
                'imap_host' => 'imap.comcast.net',
                'imap_port' => 993,
                'pop3_host' => 'pop3.comcast.net',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 25,
            ],
            [
                'name' => 'Mail.com',
                'domain' => 'mail.com',
                'imap_host' => 'imap.mail.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.mail.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 26,
            ],
            [
                'name' => 'Kolab Now',
                'domain' => 'kolabnow.com',
                'imap_host' => 'imap.kolabnow.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.kolabnow.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 27,
            ],
            [
                'name' => 'QQ Mail',
                'domain' => 'qq.com',
                'imap_host' => 'imap.qq.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.qq.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 28,
                // Authorization code (授权码); enable IMAP in web settings.
            ],
            [
                'name' => 'Foxmail',
                'domain' => 'foxmail.com',
                'imap_host' => 'imap.exmail.qq.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.exmail.qq.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 29,
                // Enterprise QQ; @foxmail.com addresses. Same auth pattern as QQ where applicable.
            ],
            [
                'name' => '163.com',
                'domain' => '163.com',
                'imap_host' => 'imap.163.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.163.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 30,
            ],
            [
                'name' => '126.com',
                'domain' => '126.com',
                'imap_host' => 'imap.126.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.126.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 31,
            ],
            [
                'name' => 'Yeah.net',
                'domain' => 'yeah.net',
                'imap_host' => 'imap.yeah.net',
                'imap_port' => 993,
                'pop3_host' => 'pop.yeah.net',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 32,
            ],
            [
                'name' => 'vip.163.com',
                'domain' => 'vip.163.com',
                'imap_host' => 'imap.vip.163.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.vip.163.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 33,
            ],
            [
                'name' => 'vip.126.com',
                'domain' => 'vip.126.com',
                'imap_host' => 'imap.vip.126.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.vip.126.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 34,
            ],
            [
                'name' => 'Sina Mail',
                'domain' => 'sina.com',
                'imap_host' => 'imap.sina.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.sina.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 35,
            ],
            [
                'name' => 'vip.sina.com',
                'domain' => 'vip.sina.com',
                'imap_host' => 'imap.vip.sina.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.vip.sina.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 36,
            ],
            [
                'name' => 'Sohu Mail',
                'domain' => 'sohu.com',
                'imap_host' => 'imap.sohu.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.sohu.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 37,
            ],
            [
                'name' => '139.com',
                'domain' => '139.com',
                'imap_host' => 'imap.139.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.139.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 38,
            ],
            [
                'name' => '189.cn',
                'domain' => '189.cn',
                'imap_host' => 'imap.189.cn',
                'imap_port' => 993,
                'pop3_host' => 'pop.189.cn',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 39,
            ],
            [
                'name' => '21cn.com',
                'domain' => '21cn.com',
                'imap_host' => 'imap.21cn.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.21cn.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 40,
            ],
            [
                'name' => 'Tom.com',
                'domain' => 'tom.com',
                'imap_host' => 'imap.tom.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.tom.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 41,
            ],
            [
                'name' => '188.com',
                'domain' => '188.com',
                'imap_host' => 'imap.188.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.188.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 42,
            ],
            [
                'name' => 'vip.188.com',
                'domain' => 'vip.188.com',
                'imap_host' => 'imap.vip.188.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.vip.188.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 43,
            ],
            [
                'name' => '263.net',
                'domain' => '263.net',
                'imap_host' => 'imap.263.net',
                'imap_port' => 993,
                'pop3_host' => 'pop.263.net',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 44,
            ],
            [
                'name' => 'Rediffmail',
                'domain' => 'rediffmail.com',
                'imap_host' => 'imap.rediffmail.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.rediffmail.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 45,
            ],

            // ---------- Inactive in client: OAuth-only / Basic Auth retired; domain kept for escrow email allowlist ----------
            [
                'name' => 'Gmail',
                'domain' => 'gmail.com',
                'imap_host' => 'imap.gmail.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.gmail.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => false,
                'sort_order' => 991,
                // Consumer Gmail: OAuth-only for third-party clients (~from Mar 2025). Not offered in app IMAP dialog.
            ],
            [
                'name' => 'Outlook',
                'domain' => 'outlook.com',
                'imap_host' => 'outlook.office365.com',
                'imap_port' => 993,
                'pop3_host' => 'outlook.office365.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => false,
                'sort_order' => 992,
                // Personal Microsoft: Basic Auth disabled; OAuth-only. Not offered in app IMAP dialog.
            ],
            [
                'name' => 'Hotmail',
                'domain' => 'hotmail.com',
                'imap_host' => 'outlook.office365.com',
                'imap_port' => 993,
                'pop3_host' => 'outlook.office365.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => false,
                'sort_order' => 993,
                // Same infra as Outlook; OAuth-only for third-party clients.
            ],
            [
                'name' => 'Live',
                'domain' => 'live.com',
                'imap_host' => 'outlook.office365.com',
                'imap_port' => 993,
                'pop3_host' => 'outlook.office365.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => false,
                'sort_order' => 994,
                // Legacy @live.com on Microsoft consumer mail; OAuth-only like Outlook/Hotmail.
            ],
        ];

        foreach ($providers as $provider) {
            if (empty($provider['domain']) && !empty($provider['imap_host'])) {
                $provider['domain'] = $this->domainFromImapHost($provider['imap_host']);
            }
            DB::table('email_providers')->insert(array_merge($provider, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        if ($this->command !== null) {
            $this->command->info('Email providers seeded successfully!');
            $this->command->info('Total providers: ' . count($providers));
            $this->command->info('Active (client list): ' . collect($providers)->where('is_active', true)->count());
        }
    }

    /**
     * Derive primary domain from IMAP host when domain is omitted (e.g. imap.example.com -> example.com).
     */
    private function domainFromImapHost(string $imapHost): string
    {
        $parts = explode('.', strtolower($imapHost));
        if (count($parts) >= 2) {
            return implode('.', array_slice($parts, -2));
        }

        return $imapHost;
    }
}
