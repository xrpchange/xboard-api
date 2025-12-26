# Gmail OAuth Setup Guide

<div style="background-color: #ff0000; color: #ffffff; padding: 20px; border-radius: 8px; margin: 20px 0; font-weight: bold; font-size: 18px;">

⚠️ **IMPORTANT: Gmail OAuth Support Removed from Project**

**Decision:** We decided not to work with this company due to their app moderation and verification requirements.

Gmail OAuth functionality has been completely removed from xboard-client. This document is kept for historical reference only.

</div>

## Overview

~~This guide explains how to register the XDexBoard application with Google OAuth to enable Gmail access for payment verification.~~

**NOTE:** This guide is kept for historical reference only. Gmail OAuth support has been removed from the project.

## Required Information for Google OAuth Registration

### Android Package Name
```
com.xdexboard.xboard
```

### SHA-1 Fingerprint

**IMPORTANT:** You need to get the SHA-1 fingerprint from your actual keystore. Use the command below to get it.

**To get SHA-1 fingerprint from debug keystore:**

**Windows:**
```bash
keytool -keystore %USERPROFILE%\.android\debug.keystore -list -v
```

When prompted for password, enter: `android` (default password for debug keystore)

**Linux/Mac:**
```bash
keytool -keystore ~/.android/debug.keystore -list -v
```

When prompted for password, enter: `android` (default password for debug keystore)

**What the command does:**
- `keytool` - Java utility for managing keystores and certificates
- `-keystore` - path to your keystore file
- `-list` - list certificates in the keystore
- `-v` - verbose output (shows detailed information including fingerprints)

**In the output, look for the line that says "SHA1:"** and copy the value (format: `XX:XX:XX:...` with colons).

**Example output:**
```
Certificate fingerprints:
     SHA1: 66:00:A9:9E:3A:E4:6E:0F:E1:7F:07:59:89:6A:76:7D:81:CE:E5:44
     SHA256: EC:29:27:F2:AD:55:99:F3:DC:06:B2:08:...
```

**Copy the SHA1 value** (the one with colons, like `66:00:A9:9E:...`)

**For production builds:** Use the same command but with your release keystore:
```bash
keytool -keystore path/to/your/release.keystore -list -v
```

When prompted, enter your release keystore password.

**Note:** Google Cloud Console requires SHA-1 fingerprint in the format with colons (e.g., `66:00:A9:9E:3A:E4:...`). Make sure there are no extra spaces or characters.

### Web Redirect URI
```
https://api.xdexboard.com/api/oauth/gmail/callback
```

### Android Deep Link Redirect URI
```
xboard://gmail-oauth
```

## Google Cloud Console Registration Steps

### Step 1: Create a Project

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. In the top bar, click on the project dropdown (shows current project or "Select a project")
3. In the popup window, click **"New Project"** (or "Создать проект")
4. Enter project name: **XDexBoard**
5. Optionally select organization and location
6. Click **"Create"** (or "Создать")
7. Wait for project creation, then select the project from the dropdown

### Step 2: Enable Gmail API

1. Make sure your project is selected (check the project dropdown in the top bar)
2. In the left menu, select **"APIs & Services"** → **"Library"** (or "API и сервисы" → "Библиотека")
3. In the search box, type **"Gmail API"**
4. In the search results, click on **"Gmail API"**
5. Click the **"Enable"** button (or "Включить")
6. Wait for the API to be enabled

### Step 3: Configure OAuth Consent Screen

1. In the left menu, select **"APIs & Services"** → **"OAuth consent screen"** (or "API и сервисы" → "Экран согласия OAuth")
2. You'll see a page asking to select user type. Choose:
   - **Internal** (Внутренний) - if app will be used only within your organization (requires Google Workspace)
   - **External** (Внешний) - if app will be available to any Google users
3. Click **"Create"** (or "Создать")

**IMPORTANT - Testing vs Production:**
- **Testing mode (режим тестирования):** App is available only to test users you add. **NO verification required!** This is what you want for development.
- **Production mode (производство):** App is available to all Google users. **Requires Google verification** if you use sensitive/restricted scopes or have more than 10 domains.

**For development/testing, keep the app in Testing mode** - you don't need to publish it or go through verification. Just add test users in Step 3 (Test users tab).
4. You'll see a form with multiple tabs. Fill in **"App information"** tab:
   - **App name** (Название приложения): Enter **XDexBoard**
   - **User support email** (Почта службы поддержки пользователей): Select or enter your email address from the dropdown
   - **App logo** (Логотип приложения): Optional - you can upload an image
   - **Application home page** (Домашняя страница приложения): Optional
   - **Application privacy policy link** (Ссылка на политику конфиденциальности): Optional
   - **Application terms of service link** (Ссылка на условия использования): Optional
   - **Authorized domains** (Домены приложения): Optional - enter domains like `xdexboard.com`
   - **Developer contact information** (Контактная информация разработчика): Enter your email address
5. Click **"Save and continue"** (or "Сохранить и продолжить")
6. You'll be on the **"Scopes"** tab (Области). Click **"Add or remove scopes"** (or "Добавить или удалить области")
7. In the popup window, you'll see a list of scopes. In the search/filter box, search for:
   - Type `gmail.readonly` and check **"https://www.googleapis.com/auth/gmail.readonly"** - Read-only access to Gmail
   - Type `userinfo.email` and check **"https://www.googleapis.com/auth/userinfo.email"** - See your primary Google Account email address
8. Click **"Update"** (or "Обновить")
9. Click **"Save and continue"** (or "Сохранить и продолжить")
10. You'll be on the **"Test users"** tab (Тестовые пользователи). If your app is in Testing mode:
    - Click **"Add users"** (or "Добавить пользователей")
    - Enter email addresses of users who will test the app
    - Click **"Add"** (or "Добавить")
11. Click **"Save and continue"** (or "Сохранить и продолжить")
12. You'll be on the **"Summary"** tab (Сводка). Review the information and click **"Back to dashboard"** (or "Вернуться к информационной панели")

### Step 4: Create OAuth 2.0 Client ID for Web Application (Server-Side)

**This is for server-side token exchange (API backend)**

1. In the left menu, select **"APIs & Services"** → **"Credentials"** (or "API и сервисы" → "Учетные данные")
2. At the top of the page, click **"Create credentials"** (or "Создать учетные данные")
3. In the dropdown menu, select **"OAuth client ID"** (or "Идентификатор клиента OAuth")
4. If you haven't configured OAuth consent screen yet, you'll see a warning. Go back to Step 3 and complete it first
5. You'll see a form. In **"Application type"** (Тип приложения) dropdown, select **"Web application"** (Веб-приложение)
6. Fill in the form:
   - **Name** (Имя): Enter **XDexBoard Web (Server)** (or any name you want)
   - **Authorized redirect URIs** (Авторизованные URI перенаправления): This is a list/table where you can add URIs
     - Click **"Add URI"** (or "Добавить URI") button
     - In the text field that appears, enter: `https://api.xdexboard.com/api/oauth/gmail/callback`
     - Click **"Add URI"** button again
     - In the new text field, enter: `xboard://gmail-oauth` (for Android deep link - IMPORTANT!)
7. Click **"Create"** (or "Создать")
8. **A popup/dialog window will appear** showing your credentials:
   - **Your Client ID** - a long string starting with something like `123456789-abc...` - **COPY THIS** (this is `GMAIL_OAUTH_CLIENT_ID` for `.env`)
   - **Your Client Secret** - a long string - **COPY THIS IMMEDIATELY** (this is `GMAIL_OAUTH_CLIENT_SECRET` for `.env`)
   - **CRITICAL:** Client secret is shown only once! Save it now or you'll have to create new credentials
9. Click **"OK"** (or "ОК") to close the dialog
10. You'll see the credentials listed in the Credentials page. You can click on the name to edit, but you won't be able to see the Client Secret again

### Step 5: Create OAuth 2.0 Client ID for Android (Mobile App)

**This is for mobile app (optional - can use Web client ID with deep link)**

**Option A: Create Android Client ID (Recommended for better security)**

1. In **"APIs & Services"** → **"Credentials"**, click **"Create credentials"** → **"OAuth client ID"** again
2. In **"Application type"** dropdown, select **"Android"**
3. Fill in the form:
   - **Name** (Имя): Enter **XDexBoard Android**
   - **Package name** (Имя пакета): Enter `com.xdexboard.xboard`
   - **SHA-1 certificate fingerprint** (Отпечаток сертификата SHA-1): Enter your SHA-1 fingerprint obtained from debug keystore (see instructions above)
     - **Format:** Must be in format with colons: `XX:XX:XX:XX:...` (20 pairs of hex digits separated by colons)
     - **How to get:** Run `keytool -keystore %USERPROFILE%\.android\debug.keystore -list -v` (Windows) or `keytool -keystore ~/.android/debug.keystore -list -v` (Linux/Mac), then enter password `android` when prompted
     - **Note:** For production, use release keystore SHA-1 fingerprint
4. Click **"Create"** (or "Создать")
5. **A popup/dialog will appear** with your Client ID - **COPY THIS** (this is `GMAIL_CLIENT_ID` for `OAuthConfig.kt`)
6. Click **"OK"** to close

**Option B: Use Web Client ID (Simpler, but less secure)**

If you don't want to create separate Android credentials, you can use the Web Client ID from Step 4 for mobile app as well. Just use the same Client ID in `OAuthConfig.kt`.

## Configuration

### Step 1: Configure Server-Side Credentials (API)

**File:** `xboard-api/.env`

```env
# From Step 4: Web Application OAuth Client ID and Secret
GMAIL_OAUTH_CLIENT_ID=your_web_client_id_from_step_4
GMAIL_OAUTH_CLIENT_SECRET=your_web_client_secret_from_step_4
```

**File:** `xboard-api/config/services.php`

The configuration is already set up to read from environment variables:

```php
'gmail_oauth' => [
    'client_id' => env('GMAIL_OAUTH_CLIENT_ID', ''),
    'client_secret' => env('GMAIL_OAUTH_CLIENT_SECRET', ''),
],
```

### Step 2: Configure Client-Side Credentials (Mobile App)

**File:** `xboard-client/shared/src/commonMain/kotlin/com/xdexboard/xboard/config/OAuthConfig.kt`

**Option A: If you created Android Client ID (Step 5, Option A):**
```kotlin
const val GMAIL_CLIENT_ID = "your_android_client_id_from_step_5"
```

**Option B: If you're using Web Client ID (Step 5, Option B):**
```kotlin
const val GMAIL_CLIENT_ID = "your_web_client_id_from_step_4"
```

**Important:** 
- If you created separate Android credentials, use Android Client ID (from Step 5)
- If you're using Web Client ID for both, use the same Client ID from Step 4

## OAuth Scopes

The following scopes are required:

- `https://www.googleapis.com/auth/gmail.readonly` - Read-only access to Gmail via IMAP
- `https://www.googleapis.com/auth/userinfo.email` - Get user's email address

**VERIFICATION REQUIRED:**

- **`gmail.readonly`** is a **SENSITIVE scope** - **REQUIRES Google verification for production use**
- **`userinfo.email`** is a **NON-SENSITIVE scope** - does not require verification

**IMPORTANT:** Since we use `gmail.readonly`, your app **WILL require Google verification** if you want to publish it for all users (Production mode). There's no way around this - Google requires verification for any app that accesses Gmail data.

**Verification Process:**
1. Google will review your app's use case
2. You'll need to provide:
   - Privacy policy URL
   - Terms of service URL
   - Video demonstration of how you use Gmail data
   - Explanation of why you need Gmail access
3. Process can take 1-4 weeks
4. Google may ask for additional information

**Alternative:** Keep app in Testing mode (only test users can use it) - no verification needed, but limited to test users only.

These scopes are configured in:
- OAuth consent screen (Google Cloud Console) - Step 3
- `GmailOAuthService.startOAuthFlow()` method in the code

## Security Notes

- **Client Secret Security:**
  - **Mobile apps:** Client Secret should NEVER be stored in mobile app code (can be decompiled)
  - **Web apps:** Client Secret MUST be stored on the server (backend) only, in `.env` file
  - **Web apps:** Server exchanges authorization code for access token using Client Secret
- The redirect URI for web must exactly match the registered URI in Google Cloud Console
- **Android deep link:** `xboard://gmail-oauth` must be registered in Authorized redirect URIs for Web application credentials (Step 4)
- **Client Secret is shown only once** when creating credentials - save it immediately!

## Troubleshooting

### "redirect_uri_mismatch" Error
- Ensure `xboard://gmail-oauth` is added to Authorized redirect URIs in Web application credentials (Step 4)
- Verify the redirect URI exactly matches what's registered (no trailing slashes, exact case)

### "invalid_client" Error
- Check that Client ID is correctly set in `OAuthConfig.kt` (use Android Client ID if created, or Web Client ID)
- Verify Client ID and Secret are correctly set in `.env` file (no extra spaces, quotes)

### "access_denied" Error
- Check that OAuth consent screen is properly configured (Step 3)
- Verify required scopes are added in OAuth consent screen (Step 3, Scopes tab)
- If app is in testing mode, ensure user email is added to test users list (Step 3, Test users tab)

### Can't see Client Secret after creation
- Client Secret is shown only once when creating credentials
- If you lost it, you need to create new credentials or reset the existing one (which will generate a new secret)

## Testing vs Production Mode

### Testing Mode (Recommended for Development)

- **No verification required** - you can use the app immediately
- App is available **only to test users** you add in OAuth consent screen
- Perfect for development and testing
- **How to use:** Just add test users in Step 3 (Test users tab) and use the app

### Production Mode (Requires Verification)

- App is available to **all Google users**
- **Requires Google verification** if:
  - You use sensitive/restricted scopes (like `gmail.readonly`)
  - You have more than 10 authorized domains
  - You add a logo
- Verification process can take several days/weeks
- **When to use:** Only when you're ready to release the app to public

### How to Avoid Verification

1. **Keep app in Testing mode** - don't click "Publish App"
2. **Add test users** in OAuth consent screen → Test users tab
3. **Use the app** - only test users will be able to authorize it
4. **No verification needed** for testing!

**Note:** Even in Testing mode, you can use all OAuth scopes and features. The only limitation is that only test users can authorize the app.

## References

- [Google OAuth 2.0 Documentation](https://developers.google.com/identity/protocols/oauth2)
- [Gmail API Documentation](https://developers.google.com/gmail/api)
- [Google Cloud Console](https://console.cloud.google.com/)
- [OAuth Verification Process](https://support.google.com/cloud/answer/9110914)
