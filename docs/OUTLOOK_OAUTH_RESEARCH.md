# Outlook/Microsoft OAuth Research

## Overview

This document contains research findings about Microsoft/Outlook OAuth requirements and verification process to help decide whether to implement Outlook OAuth integration.

## Key Questions

1. **Does Microsoft require app verification for Mail.Read or IMAP access?**
2. **What is the verification process like?**
3. **Are there moderation/approval requirements similar to Google?**
4. **Can we use Outlook OAuth without verification?**

## Research Findings

### Microsoft Identity Platform (Azure AD)

Microsoft uses **Azure AD** (now called "Microsoft Entra ID") for OAuth authentication. The platform has different permission types:

1. **Delegated Permissions** - User consent (user grants permission to app)
2. **Application Permissions** - Admin consent (requires tenant admin approval)

### Mail.Read Permission and IMAP Access

- **Type:** Delegated permission
- **Scope for IMAP:** `https://outlook.office.com/IMAP.AccessAsUser.All` (for IMAP access)
- **Scope for Graph API:** `Mail.Read` or `Mail.ReadWrite`
- **Access:** Read user's email messages via IMAP or Graph API

### ⚠️ CRITICAL: Admin Consent Requirement (November 2025 Policy Change)

**IMPORTANT UPDATE:** As of November 2025, Microsoft changed the default policy for third-party applications requesting access to Exchange data (including email) and Teams.

**Key Requirements:**
1. **Admin Consent Required:** For permissions including `Mail.Read`, `Mail.ReadWrite`, and `IMAP.AccessAsUser.All`, **admin consent is now REQUIRED**.
2. **User Consent NOT Sufficient:** Users cannot grant consent independently - only tenant administrators can approve access.
3. **Applies to:** All delegated permissions for email access, regardless of whether the app is verified or not.

### Publisher Verification (Optional but Recommended)

**Publisher Verification** is a **voluntary process** (not mandatory) that confirms the authenticity of the developer organization:

1. **Purpose:** Adds a "verified publisher" badge to the consent prompt, increasing user trust
2. **Requirements:**
   - Microsoft AI Cloud Partner Program (CPP) account with verified status
   - Link Partner ID with app registration in Entra ID
   - Domain verification (app domain must match verified domain in CPP)
   - User must have Application Administrator role in Entra ID and Partner Admin in Partner Center

3. **Process:**
   - **No deep security review** like Google
   - **No video demonstration required**
   - **No code audit**
   - Takes minutes if all requirements are met (not days/weeks like Google)

4. **Benefits:**
   - Removes "unverified publisher" warning
   - Increases user trust
   - May reduce admin consent rejections

5. **Without Verification:**
   - App will show "unverified publisher" warning
   - May reduce user adoption
   - Admins may block consent for unverified apps

### Comparison with Google OAuth

| Aspect | Google (Gmail) | Microsoft (Outlook) |
|--------|----------------|---------------------|
| **Mail.Read Permission** | `gmail.readonly` - **SENSITIVE** scope | `IMAP.AccessAsUser.All` or `Mail.Read` - **STANDARD** permission |
| **Publisher Verification Required** | ✅ **YES** - Mandatory for production | ❌ **NO** - Optional (but recommended) |
| **User Consent** | Limited to test users without verification | ❌ **NO** - Admin consent required (since Nov 2025) |
| **Admin Consent** | Required for sensitive scopes | ✅ **YES** - Required for email access (since Nov 2025) |
| **Verification Process** | Complex (Privacy Policy, Terms, Video demo, Security review) | Simple (Partner Program verification, no code review) |
| **Time to Verify** | Weeks/months | Minutes (if requirements met) |
| **Moderation** | Strict review process | No moderation, but post-factum monitoring for abuse |

### IMAP Access via OAuth

Microsoft supports **IMAP access via OAuth 2.0** for Outlook.com and Office 365:

- **Protocol:** OAuth 2.0 with IMAP (SASL XOAUTH2)
- **Scope:** `https://outlook.office.com/IMAP.AccessAsUser.All` (for IMAP access)
- **Alternative:** `Mail.Read` (for Microsoft Graph API)
- **Access Type:** Delegated (requires admin consent since Nov 2025)
- **Verification:** Publisher verification not required, but admin consent is mandatory

### Potential Issues

1. **Geopolitical Restrictions:**
   - Microsoft has restricted access to cloud services in certain regions (e.g., Russia in 2024)
   - May affect availability depending on user location

2. **Content Moderation:**
   - Microsoft has strict content moderation policies
   - May affect email content processing
   - Not directly related to OAuth verification

3. **Account Restrictions:**
   - Microsoft may suspend accounts for policy violations
   - Could affect OAuth token validity

## Recommendations

### Option 1: Implement with Admin Consent Flow

**Pros:**
- No Microsoft publisher verification required (optional)
- Simpler verification process than Google (if you choose to verify)
- Works for both personal and work/school accounts

**Cons:**
- **Admin consent required** - Users cannot grant consent themselves
- **Major blocker:** Personal Microsoft accounts (Outlook.com) may not have an admin
- **Enterprise accounts:** Requires IT admin approval, which may be difficult to obtain
- **User friction:** Users need to contact their admin to approve the app
- **Lower adoption:** Many users won't be able to use the feature

### Option 2: Skip Outlook, Focus on Yandex

**Pros:**
- Yandex works with user consent (no admin required)
- Already implemented and working
- No verification requirements
- Faster to market

**Cons:**
- Limited to Yandex users
- Missing potential Microsoft/Outlook user base

### Option 3: Implement with Publisher Verification

**Pros:**
- Removes "unverified publisher" warning
- May increase admin approval rates
- Better user trust

**Cons:**
- Still requires admin consent (main blocker)
- Additional setup time (but much faster than Google)
- Doesn't solve the admin consent problem for personal accounts

## Conclusion

**Key Findings:**

1. **Publisher Verification:** Microsoft does **NOT require mandatory publisher verification** for email access (unlike Google). Verification is optional but recommended.

2. **Admin Consent Requirement:** **CRITICAL** - Since November 2025, Microsoft requires **admin consent** for email access permissions (`Mail.Read`, `IMAP.AccessAsUser.All`). **User consent is NOT sufficient.**

3. **Main Blocker:** The admin consent requirement is a **major obstacle** for:
   - **Personal Microsoft accounts (Outlook.com):** Users typically don't have an admin
   - **Work/School accounts:** Requires IT administrator approval, which may be difficult to obtain
   - **User experience:** Users must contact their admin to approve the app, creating friction

4. **Comparison with Google:**
   - **Google:** Requires verification, but user consent works (after verification)
   - **Microsoft:** No verification required, but admin consent is mandatory (even for personal accounts)

**Recommendation:** 

**❌ DO NOT implement Outlook OAuth at this time** due to the admin consent requirement. This creates a significant barrier for users, especially those with personal Microsoft accounts who don't have an admin to approve the app.

**Alternative:** Focus on Yandex OAuth (already working) and consider other email providers that support user consent without admin approval.

**If you must implement Outlook:**
1. Implement admin consent flow
2. Provide clear instructions for users on how to get admin approval
3. Consider publisher verification to increase trust (optional, but recommended)
4. Accept that many users won't be able to use the feature

## Next Steps

1. **Test OAuth flow** with personal Microsoft account
2. **Check if user consent works** without verification
3. **Monitor Microsoft documentation** for policy changes
4. **Consider implementing** if user consent works reliably

## References

- Microsoft Identity Platform: https://learn.microsoft.com/en-us/azure/active-directory/develop/
- Microsoft Graph API Permissions: https://learn.microsoft.com/en-us/graph/permissions-reference
- Mail.Read Permission: https://learn.microsoft.com/en-us/graph/permissions-reference#mail-permissions

---

**Last Updated:** December 2024
**Status:** Research completed - **NOT RECOMMENDED** due to admin consent requirement
**Decision:** Skip Outlook OAuth implementation - focus on Yandex and other providers

