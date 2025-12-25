# Email Providers OAuth Support Research

## Overview

This document lists email providers that support OAuth 2.0 for IMAP access, with focus on providers available in Russia and internationally.

## Russian Email Providers

### 1. Yandex (Яндекс.Почта) ✅ **IMPLEMENTED**

- **Status:** ✅ Already implemented and working
- **OAuth Support:** Yes, OAuth 2.0 with XOAUTH2
- **IMAP Access:** Yes, via `imap.yandex.com:993`
- **Scopes Required:** `mail:imap_ro` (read-only), `mail:imap_full` (full access)
- **User Consent:** ✅ Yes, user can grant consent directly
- **Verification Required:** ❌ No
- **Business Accounts:** Available (Yandex 360)
- **Personal Accounts:** Available
- **Documentation:** https://yandex.ru/dev/id/doc/en/
- **Notes:** 
  - Works for both personal and business accounts
  - No verification or moderation required
  - Easy to implement
  - **Best option for Russian users**

### 2. Mail.ru ✅ **SUPPORTED** (with caveats)

- **Status:** ⚠️ Not yet implemented, OAuth technically supported
- **OAuth Support:** Yes, OAuth 2.0 with XOAUTH2 (scope: `mail.imap`)
- **IMAP Access:** Yes, via `imap.mail.ru:993`
- **Scopes Required:** `mail.imap` (for IMAP access)
- **User Consent:** ✅ Yes, user consent works
- **Verification Required:** ❌ **No formal verification required**
- **Business Accounts:** Available (Mail.ru for Business)
- **Personal Accounts:** Available
- **Documentation:** https://o2.mail.ru/app/ (developer panel)
- **Registration:** Register app at https://o2.mail.ru/app/
- **Important Notes:**
  - ⚠️ **OAuth may not work for all third-party apps** - some may require app passwords instead
  - Second most popular email provider in Russia
  - Supports OAuth 2.0, but implementation may vary
  - **Recommendation:** Implement with app password as fallback
  - **Good option for Russian users, but test thoroughly**

### 3. Rambler.ru ⚠️ **POSSIBLE**

- **Status:** ⚠️ OAuth supported, but documentation not publicly available
- **OAuth Support:** Yes, OAuth 2.0 (according to articles)
- **IMAP Access:** Yes, via `imap.rambler.ru:993`
- **Scopes Required:** `imap.access` or similar (documentation at id.rambler.ru/oauth-20)
- **User Consent:** ✅ Yes (likely, needs verification)
- **Verification Required:** ❌ Unknown (likely not required)
- **Business Accounts:** Unknown
- **Personal Accounts:** ✅ Available
- **Documentation:** Not publicly available, may need to contact support
- **Notes:**
  - Part of Sber ecosystem
  - OAuth2 support mentioned in articles
  - **Recommendation:** Contact Rambler support for developer documentation
  - **Lower priority** due to lack of documentation

## International Email Providers

### 4. Gmail (Google) ❌ **NOT RECOMMENDED**

- **Status:** ❌ Removed from project
- **OAuth Support:** Yes, OAuth 2.0
- **IMAP Access:** Yes, via `imap.gmail.com:993`
- **Scopes Required:** `https://www.googleapis.com/auth/gmail.readonly`
- **User Consent:** ✅ Yes (after verification)
- **Verification Required:** ✅ **YES - Mandatory** (complex process)
- **Verification Process:** 
  - Privacy Policy required
  - Terms of Service required
  - Video demonstration required
  - Security review
  - Can take weeks/months
- **Notes:**
  - **Decision:** Removed from project due to verification requirements
  - Complex moderation process
  - Not suitable for quick implementation

### 5. Outlook/Microsoft 365 ❌ **NOT RECOMMENDED**

- **Status:** ❌ Not recommended (see OUTLOOK_OAUTH_RESEARCH.md)
- **OAuth Support:** Yes, OAuth 2.0
- **IMAP Access:** Yes, via `outlook.office365.com:993`
- **Scopes Required:** `https://outlook.office.com/IMAP.AccessAsUser.All`
- **User Consent:** ❌ **NO** - Admin consent required (since Nov 2025)
- **Verification Required:** ❌ No (optional publisher verification)
- **Admin Consent:** ✅ **REQUIRED** - Major blocker
- **Notes:**
  - **Decision:** Not recommended due to admin consent requirement
  - Personal accounts (Outlook.com) don't have admins
  - Enterprise accounts require IT admin approval
  - Creates significant user friction

### 6. ProtonMail ⚠️ **LIMITED**

- **Status:** ⚠️ Limited OAuth support
- **OAuth Support:** Partial (Bridge application required)
- **IMAP Access:** Via ProtonMail Bridge (local proxy)
- **User Consent:** ✅ Yes
- **Verification Required:** ❌ No
- **Notes:**
  - End-to-end encrypted email
  - Requires ProtonMail Bridge application
  - Not suitable for server-side IMAP access
  - More complex implementation

### 7. Tutanota ⚠️ **NO IMAP**

- **Status:** ❌ No IMAP support
- **OAuth Support:** ❌ No
- **IMAP Access:** ❌ Not available
- **Notes:**
  - End-to-end encrypted email
  - No IMAP protocol support
  - Not suitable for our use case

### 8. Zoho Mail ⚠️ **REQUIRES REVIEW**

- **Status:** ⚠️ Requires app review (simpler than Google)
- **OAuth Support:** Yes, OAuth 2.0 with XOAUTH2
- **IMAP Access:** Yes, via `imap.zoho.com:993`
- **Scopes Required:** `ZohoMail.accounts.read`, `ZohoMail.messages.read` or `IMAP.AccessAsUser`
- **User Consent:** ✅ Yes, user consent sufficient
- **Verification Required:** ⚠️ **YES** - App review required (but simpler than Google)
- **Review Process:** 
  - Privacy Policy required
  - Terms of Service required
  - Less strict than Google (no video demo required)
- **Business Accounts:** ✅ Available
- **Personal Accounts:** ✅ Available (free tier)
- **Documentation:** https://www.zoho.com/developer/help/oauth-2.0/
- **Registration:** https://developer.zoho.com/
- **Notes:**
  - Popular business email provider
  - Available in Russia (not blocked)
  - Data stored in EU/US/India (choose region)
  - **Recommendation:** Consider for international users, but requires review

### 9. FastMail ⚠️ **REQUIRES REVIEW** (Paid)

- **Status:** ⚠️ Requires app registration (simple review)
- **OAuth Support:** Yes, OAuth 2.0 for JMAP and IMAP
- **IMAP Access:** Yes, via IMAP with XOAUTH2 (also supports JMAP)
- **Scopes Required:** IMAP access scopes
- **User Consent:** ✅ Yes, user consent sufficient
- **Verification Required:** ⚠️ **YES** - App registration required (simple review)
- **Business Accounts:** ✅ Available (paid)
- **Personal Accounts:** ✅ Available (paid)
- **Documentation:** https://developer.fastmail.com/
- **Registration:** Fastmail Developer Portal
- **Notes:**
  - Paid email service (not free)
  - Available in Russia (not blocked)
  - Supports both JMAP (recommended) and IMAP
  - **Recommendation:** Consider for paid users, requires registration

## Summary Table

| Provider | OAuth Support | IMAP Access | User Consent | Verification Required | Status |
|----------|--------------|-------------|--------------|----------------------|--------|
| **Yandex** | ✅ Yes | ✅ Yes | ✅ Yes | ❌ No | ✅ **IMPLEMENTED** |
| **Mail.ru** | ✅ Yes* | ✅ Yes | ✅ Yes | ❌ No | ⚠️ **TO IMPLEMENT** (with app password fallback) |
| **Rambler** | ✅ Yes | ✅ Yes | ✅ Yes | ❓ Unknown | ⚠️ **POSSIBLE** (no public docs) |
| **Gmail** | ✅ Yes | ✅ Yes | ✅ Yes* | ✅ **YES** (complex) | ❌ **REMOVED** |
| **Outlook** | ✅ Yes | ✅ Yes | ❌ **NO** (admin required) | ❌ No | ❌ **NOT RECOMMENDED** |
| **ProtonMail** | ⚠️ Partial | ⚠️ Via Bridge | ✅ Yes | ❌ No | ⚠️ **LIMITED** |
| **Tutanota** | ❌ No | ❌ No | N/A | N/A | ❌ **NOT SUITABLE** |
| **Zoho** | ✅ Yes | ✅ Yes | ✅ Yes | ⚠️ **YES** (simple review) | ⚠️ **REQUIRES REVIEW** |
| **FastMail** | ✅ Yes | ✅ Yes | ✅ Yes | ⚠️ **YES** (simple) | ⚠️ **REQUIRES REVIEW** (paid) |
| **Yahoo** | ✅ Yes | ✅ Yes | ✅ Yes | ❌ No | ⚠️ **POSSIBLE** |

*Gmail: User consent works only after mandatory verification  
*Mail.ru: OAuth may not work for all apps, app password may be required

*Gmail: User consent works only after mandatory verification

## Recommendations for Russian Market

### Priority 1: Yandex ✅
- **Status:** Already implemented and working
- **Action:** Continue using, no changes needed
- **Coverage:** Covers majority of Russian email users

### Priority 2: Mail.ru ⚠️
- **Status:** Should be implemented (with caution)
- **Reasons:**
  - Second most popular email provider in Russia
  - Supports OAuth 2.0
  - No verification required
  - **Important:** May require app password fallback for some cases
- **Action:** 
  1. Register app at https://o2.mail.ru/app/
  2. Implement OAuth flow (similar to Yandex)
  3. **Implement app password as fallback** for cases where OAuth doesn't work
  4. Test thoroughly with different Mail.ru accounts
- **Coverage:** Additional significant portion of Russian users

### Priority 3: Rambler ⚠️
- **Status:** Low priority (documentation not available)
- **Reasons:**
  - OAuth supported but documentation not publicly available
  - Smaller user base than Yandex/Mail.ru
- **Action:** Contact Rambler support for developer documentation if needed

### Priority 4: International Providers
- **Status:** For international users only
- **Options:**
  - **Zoho:** Requires review (simpler than Google), good for business users
  - **FastMail:** Requires registration, paid service
  - **Yahoo:** No verification, but less popular now
- **Action:** Consider for international expansion, but not priority for Russian market

## Implementation Priority (Final Decision)

1. ✅ **Yandex** - Already done and working (Russian users)
2. ⚠️ **Mail.ru** - Next to implement (Russian users, with app password fallback)
3. ⚠️ **Yahoo** - To implement (International users, no verification)
4. ❌ **Gmail** - Not implementing (verification required)
5. ❌ **Outlook** - Not implementing (admin consent required)
6. ❌ **Other providers** - Not implementing (not needed)

## Next Steps (Implementation Plan)

### 1. Mail.ru Implementation (Russian Users)
- Register app at https://o2.mail.ru/app/
- Scope: `mail.imap`
- Implement OAuth flow (similar to Yandex)
- **Critical:** Implement app password fallback for cases where OAuth doesn't work
- Add to OAuthController
- Add to SettingsScreen
- Test thoroughly with different Mail.ru accounts

### 2. Yahoo Implementation (International Users)
- Register app at https://developer.yahoo.com/
- Scopes: `mail-r` (read mail), `offline_access`
- Implement OAuth flow (similar to Yandex/Mail.ru)
- IMAP server: `imap.mail.yahoo.com:993`
- Add to OAuthController
- Add to SettingsScreen
- Test with Yahoo accounts

### 3. Testing
- Test Mail.ru OAuth with various account types
- Test Mail.ru app password fallback mechanism
- Test Yahoo OAuth flow
- Monitor rate limits
- Ensure DKIM signatures are preserved

## References

- Yandex OAuth: https://yandex.ru/dev/id/doc/en/
- Mail.ru OAuth: https://o2.mail.ru/app/ (developer panel)
- Rambler OAuth: id.rambler.ru/oauth-20 (documentation not publicly available)
- Zoho OAuth: https://www.zoho.com/developer/help/oauth-2.0/
- FastMail Developer: https://developer.fastmail.com/
- Yahoo Developer: https://developer.yahoo.com/
- Microsoft Identity Platform: https://learn.microsoft.com/en-us/azure/active-directory/develop/
- Google OAuth: https://developers.google.com/identity/protocols/oauth2

## Key Findings from Research

### Russian Providers (Best Options)
1. **Yandex** ✅ - Perfect, already implemented
2. **Mail.ru** ⚠️ - Good, but may need app password fallback
3. **Rambler** ⚠️ - Possible, but no public documentation

### International Providers
1. **Zoho** ⚠️ - Requires review (simpler than Google)
2. **FastMail** ⚠️ - Requires registration, paid service
3. **Yahoo** ⚠️ - No verification, but less popular

### Not Recommended
1. **Gmail** ❌ - Complex verification required
2. **Outlook** ❌ - Admin consent required (since Nov 2025)

## Decision: Final Implementation Plan

**Selected Providers:**
1. **Yandex** ✅ - Already implemented (for Russian users)
2. **Mail.ru** ⚠️ - To implement (for Russian users, with app password fallback)
3. **Yahoo** ⚠️ - To implement (for international users, no verification required)

**Why This Solution:**
- **Mail.ru** covers second largest user base in Russia (after Yandex)
- **Yahoo** is available internationally, no verification required, simple implementation
- **User security:** Users should create separate email for exchange/banking operations, not give access to personal email
- **Sufficient coverage:** These providers cover the target use case without unnecessary complexity

**Not Implementing:**
- Gmail (verification required)
- Outlook (admin consent required)
- Zoho/FastMail (require review/registration)
- Rambler (no public documentation)

This solution balances coverage, simplicity, and user security.

---

**Last Updated:** December 2024
**Status:** Decision made - Implementing Mail.ru (Russian) and Yahoo (International)

**Final Decision:**
- ✅ Yandex - Already implemented
- ⚠️ Mail.ru - To implement (with app password fallback)
- ⚠️ Yahoo - To implement (no verification)
- ❌ Other providers - Not implementing (not needed)

