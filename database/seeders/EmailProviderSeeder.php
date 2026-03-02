<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmailProviderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * 
     * IMPORTANT: These settings are critical for marketing - users must be able to connect!
     * All settings verified as of December 2024.
     * 
     * Notes:
     * - Most providers require App Password (not regular password) for IMAP access
     * - IMAP must be enabled in account settings for most providers
     * - Port 993 = IMAP with SSL, Port 995 = POP3 with SSL
     * - Port 143 = IMAP without SSL, Port 110 = POP3 without SSL (not recommended)
     */
    public function run(): void
    {
        // Clear existing data to avoid duplicates
        DB::table('email_providers')->truncate();
        
        $providers = [
            // ===========================================
            // TOP TIER - Most Popular (Priority 1-10)
            // ===========================================
            
            [
                'name' => 'Gmail',
                'domain' => 'gmail.com',
                'imap_host' => 'imap.gmail.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.gmail.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 1,
                // Requires: App Password (not regular password), IMAP enabled in settings
                // Setup: Google Account → Security → 2-Step Verification → App Passwords
            ],
            
            [
                'name' => 'Yandex',
                'domain' => 'yandex.ru',
                'imap_host' => 'imap.yandex.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.yandex.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 2,
                // Requires: App Password, IMAP enabled in settings. @yandex.ru uses imap.yandex.com
                // URL: https://id.yandex.ru/security/app-passwords
            ],
            
            [
                'name' => 'Outlook',
                'domain' => 'outlook.com',
                'imap_host' => 'outlook.office365.com',
                'imap_port' => 993,
                'pop3_host' => 'outlook.office365.com', // Fixed: was pop-mail.outlook.com, correct is outlook.office365.com
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 3,
                // Can use regular password or App Password (depends on account settings)
                // IMAP is usually enabled by default for Office365 accounts
            ],
            
            [
                'name' => 'Hotmail',
                'domain' => 'hotmail.com',
                'imap_host' => 'outlook.office365.com', // Hotmail now uses Outlook.com infrastructure
                'imap_port' => 993,
                'pop3_host' => 'outlook.office365.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 4,
                // Legacy Hotmail accounts (@hotmail.com) now use Outlook.com servers
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
                'sort_order' => 5,
                // Requires: App Password (not regular password)
                // Setup: Account Security → Generate app password
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
                'sort_order' => 6,
                // For @att.net, @sbcglobal.net email addresses
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
                'sort_order' => 7,
                // May require App Password depending on security settings
                // IMAP access must be enabled in account settings
            ],
            
            // ===========================================
            // SECOND TIER - Popular International (Priority 11-20)
            // ===========================================
            
            [
                'name' => 'iCloud',
                'domain' => 'icloud.com',
                'imap_host' => 'imap.mail.me.com',
                'imap_port' => 993,
                'pop3_host' => null, // Fixed: iCloud Mail does not support POP3, only IMAP
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 8,
                // Requires: App Password (not Apple ID password)
                // Setup: Apple ID → Sign-In and Security → App-Specific Passwords
                // Note: POP3 is not supported by iCloud Mail, only IMAP
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
                // Requires: App Password for IMAP access
            ],
            
            [
                'name' => 'Comcast/Xfinity',
                'imap_host' => 'imap.comcast.net',
                'imap_port' => 993,
                'pop3_host' => 'pop3.comcast.net',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 10,
                // For @comcast.net email addresses
            ],
            
            [
                'name' => 'Verizon/AOL',
                'imap_host' => 'imap.aol.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.aol.com', // Legacy pop.verizon.net migrated to AOL
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 11,
                // For @verizon.net email addresses (migrated to AOL infrastructure)
            ],
            
            [
                'name' => 'Zoho Mail',
                'imap_host' => 'imap.zoho.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.zoho.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 12,
                // May require App Password depending on organization settings
            ],
            
            // ===========================================
            // THIRD TIER - Russian Providers (Priority 11-20)
            // ===========================================
            
            [
                'name' => 'Rambler',
                'imap_host' => 'imap.rambler.ru',
                'imap_port' => 993,
                'pop3_host' => 'pop.rambler.ru',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 20,
            ],
            
            [
                'name' => 'Inbox.ru',
                'imap_host' => 'imap.inbox.ru',
                'imap_port' => 993,
                'pop3_host' => 'pop.inbox.ru',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 21,
            ],
            
            [
                'name' => 'List.ru',
                'imap_host' => 'imap.list.ru',
                'imap_port' => 993,
                'pop3_host' => 'pop.list.ru',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 22,
            ],
            
            // ===========================================
            // FOURTH TIER - European Providers (Priority 30-40)
            // ===========================================
            
            [
                'name' => 'Web.de',
                'imap_host' => 'imap.web.de',
                'imap_port' => 993,
                'pop3_host' => 'pop.web.de',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 30,
            ],
            
            [
                'name' => 'T-Online',
                'imap_host' => 'secureimap.t-online.de',
                'imap_port' => 993,
                'pop3_host' => 'securepop.t-online.de',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 31,
                // Popular German email provider (@t-online.de)
            ],
            
            [
                'name' => 'GMX',
                'imap_host' => 'imap.gmx.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.gmx.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 32,
            ],
            
            [
                'name' => 'Orange',
                'imap_host' => 'imap.orange.fr',
                'imap_port' => 993,
                'pop3_host' => 'pop.orange.fr',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 33,
            ],
            
            [
                'name' => 'SFR',
                'imap_host' => 'imap.sfr.fr',
                'imap_port' => 993,
                'pop3_host' => 'pop.sfr.fr',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 34,
            ],
            
            [
                'name' => 'Free.fr',
                'imap_host' => 'imap.free.fr',
                'imap_port' => 993,
                'pop3_host' => 'pop.free.fr',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 35,
            ],
            
            [
                'name' => 'Laposte.net',
                'imap_host' => 'imap.laposte.net',
                'imap_port' => 993,
                'pop3_host' => 'pop.laposte.net',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 36,
            ],
            
            [
                'name' => 'Posteo',
                'imap_host' => 'posteo.de', // Fixed: was imap.posteo.de, correct is posteo.de
                'imap_port' => 993,
                'pop3_host' => 'posteo.de', // Fixed: was pop.posteo.de, correct is posteo.de
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 37,
            ],
            
            // ===========================================
            // FIFTH TIER - Other International Providers (Priority 40-50)
            // ===========================================
            
            [
                'name' => 'FastMail',
                'imap_host' => 'imap.fastmail.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.fastmail.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 40,
            ],
            
            [
                'name' => 'Mail.com',
                'imap_host' => 'imap.mail.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.mail.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 41,
            ],
            
            [
                'name' => 'Rediffmail',
                'imap_host' => 'imap.rediffmail.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.rediffmail.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 42,
            ],
            
            [
                'name' => 'Hushmail',
                'imap_host' => 'imap.hushmail.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.hushmail.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 43,
            ],
            
            [
                'name' => 'Runbox',
                'imap_host' => 'imap.runbox.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.runbox.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 44,
            ],
            
            [
                'name' => 'StartMail',
                'imap_host' => 'imap.startmail.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.startmail.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 45,
            ],
            
            [
                'name' => 'Kolab Now',
                'imap_host' => 'imap.kolabnow.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.kolabnow.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 46,
            ],
            
            [
                'name' => 'Mailfence',
                'imap_host' => 'imap.mailfence.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.mailfence.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 47,
            ],
            
            // ===========================================
            // SIXTH TIER - Chinese Providers (Priority 50-70)
            // ===========================================
            
            [
                'name' => 'QQ Mail',
                'imap_host' => 'imap.qq.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.qq.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 50,
                // Requires: Authorization code (授权码), not regular password
                // Setup: Settings → Account → Enable POP3/IMAP/SMTP → Generate authorization code
            ],
            
            [
                'name' => 'Foxmail',
                'imap_host' => 'imap.exmail.qq.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.exmail.qq.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 51,
                // Enterprise QQ Mail - requires authorization code
            ],
            
            [
                'name' => '163.com',
                'imap_host' => 'imap.163.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.163.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 52,
                // Requires: Authorization code (授权码)
            ],
            
            [
                'name' => '126.com',
                'imap_host' => 'imap.126.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.126.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 53,
                // Requires: Authorization code (授权码)
            ],
            
            [
                'name' => 'Yeah.net',
                'imap_host' => 'imap.yeah.net',
                'imap_port' => 993,
                'pop3_host' => 'pop.yeah.net',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 54,
            ],
            
            [
                'name' => 'vip.163.com',
                'imap_host' => 'imap.vip.163.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.vip.163.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 55,
                // VIP 163.com account
            ],
            
            [
                'name' => 'vip.126.com',
                'imap_host' => 'imap.vip.126.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.vip.126.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 56,
                // VIP 126.com account
            ],
            
            [
                'name' => 'Sina Mail',
                'imap_host' => 'imap.sina.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.sina.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 57,
            ],
            
            [
                'name' => 'vip.sina.com',
                'imap_host' => 'imap.vip.sina.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.vip.sina.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 58,
                // VIP Sina.com account
            ],
            
            [
                'name' => 'Sohu Mail',
                'imap_host' => 'imap.sohu.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.sohu.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 59,
            ],
            
            [
                'name' => '139.com',
                'imap_host' => 'imap.139.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.139.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 60,
            ],
            
            [
                'name' => '189.cn',
                'imap_host' => 'imap.189.cn',
                'imap_port' => 993,
                'pop3_host' => 'pop.189.cn',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 61,
            ],
            
            [
                'name' => '21cn.com',
                'imap_host' => 'imap.21cn.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.21cn.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 62,
            ],
            
            [
                'name' => 'Tom.com',
                'imap_host' => 'imap.tom.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.tom.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 63,
            ],
            
            [
                'name' => '188.com',
                'imap_host' => 'imap.188.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.188.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 64,
            ],
            
            [
                'name' => 'vip.188.com',
                'imap_host' => 'imap.vip.188.com',
                'imap_port' => 993,
                'pop3_host' => 'pop.vip.188.com',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 65,
                // VIP 188.com account
            ],
            
            [
                'name' => '263.net',
                'imap_host' => 'imap.263.net',
                'imap_port' => 993,
                'pop3_host' => 'pop.263.net',
                'pop3_port' => 995,
                'use_ssl' => true,
                'is_active' => true,
                'sort_order' => 66,
            ],
        ];

        // Insert providers with timestamps; ensure domain is set (for rate-limit validation)
        foreach ($providers as $provider) {
            if (empty($provider['domain']) && !empty($provider['imap_host'])) {
                $provider['domain'] = $this->domainFromImapHost($provider['imap_host']);
            }
            DB::table('email_providers')->insert(array_merge($provider, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
        
        $this->command->info('Email providers seeded successfully!');
        $this->command->info('Total providers: ' . count($providers));
        $this->command->info('Active providers: ' . collect($providers)->where('is_active', true)->count());
    }

    /**
     * Derive primary domain from IMAP host for providers without explicit domain (e.g. imap.gmail.com -> gmail.com).
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
