# OAuth Setup Guide

## Overview

This document describes how to set up OAuth integration for email providers (Yandex, Gmail, Outlook) to enable automatic payment verification through email access.

## Architecture

The OAuth flow works differently for Android and Web platforms:

- **Android with Custom Tabs**: Uses browser-based OAuth (Custom Tabs) with package name and SHA-256 fingerprint for verification. **Requires deep link to be registered in Callback URI list** (even though Android fields don't show redirect URI field).
- **Android with official SDK**: Uses Yandex LoginSDK which handles redirects internally. No redirect URI registration needed.
- **Web**: Uses redirect URI callback. The redirect URI must be registered in OAuth provider.

## Yandex OAuth Setup

### Step 1: Register Application at Yandex OAuth

1. Go to https://oauth.yandex.ru/
2. Select application type: **"Для авторизации пользователей"** (For user authorization)
3. Click "Register new application"
4. Fill in the form:

#### Application Details
- **Application name:** XDexBoard
- **Description:** P2P exchange platform for payment verification

#### Android Configuration
- **Android package name:** `com.xrpchange.xboard`
- **SHA-256 fingerprint:** `66:00:A9:9E:3A:E4:6E:0F:E1:7F:07:59:89:6A:76:7D:81:CE:E5:44:EC:29:27:F2:AD:55:99:F3:DC:06:B2:08`
  - Note: This is the debug keystore fingerprint. For production, use release keystore fingerprint.

#### Callback URIs (Redirect URIs) - CRITICAL
You need to register BOTH redirect URIs in Yandex OAuth:

1. **For Web:** `https://api.xdexboard.com/api/oauth/yandex/callback`
   - This endpoint is implemented in `app/Http/Controllers/Api/OAuthController.php`
   - Route: `/api/oauth/yandex/callback` (see `routes/api.php`)
   - Used for web applications

2. **For Android (Deep Link):** `xboard://yandex-oauth`
   - **CRITICAL:** When using Custom Tabs (browser-based OAuth) instead of official Yandex LoginSDK, you MUST add this deep link to the "Callback URI" list in Yandex OAuth
   - **How to add:** Go to https://oauth.yandex.ru/client/my → select your app → find "Callback URI" or "Redirect URI" section (may be under "Веб-сервисы" or general settings) → add `xboard://yandex-oauth`
   - Yandex allows multiple Callback URIs, including custom schemes
   - The deep link is configured in AndroidManifest.xml and handled by MainActivity
   - Without this registration, you'll get error: "400 redirect_uri не совпадает с Callback URL"

#### OAuth Scopes
- In "Дополнительные" (Additional) section, manually enter: `mail:imap_full`
  - **Required scope:** `mail:imap_full` - Full access to mail via IMAP (read, flags, delete)
    - This scope provides full access to mail via IMAP protocol
    - **Important:** This scope may not appear in the dropdown list. You must enter it manually in the "Дополнительные" field
    - The scope `mail:imap_ro` (read-only) is not sufficient - Yandex requires `mail:imap_full` for IMAP access
  - **Additional scope:** `login:email` - To retrieve user's email address
  - Alternative scopes (if needed):
    - `mail:smtp` - Send emails via SMTP

5. Save and copy the **Client ID**

### Step 2: Configure Client ID

Add the Client ID to the client application:

**File:** `xboard-client/shared/src/commonMain/kotlin/com/xdexboard/xboard/config/OAuthConfig.kt`

```kotlin
const val YANDEX_CLIENT_ID = "your_client_id_here"
```

### Step 3: Configure Web App URL (API)

Set the web application URL in API configuration:

**File:** `xboard-api/.env`

```env
APP_WEB_URL=https://xdexboard.com
```

Or in `config/app.php`:

```php
'web_app_url' => env('APP_WEB_URL', 'https://xdexboard.com'),
```

### Step 4: Configure Yandex OAuth Credentials (Server-Side)

**IMPORTANT:** Client Secret should ONLY be stored on the server, never in client-side code!

**File:** `xboard-api/.env`

```env
YANDEX_OAUTH_CLIENT_ID=59102e8a9c744f04a1d724f868cf90ba
YANDEX_OAUTH_CLIENT_SECRET=8a733365d441491f9b74d4077874c329
```

**File:** `xboard-api/config/services.php`

The configuration is already set up to read from environment variables:

```php
'yandex_oauth' => [
    'client_id' => env('YANDEX_OAUTH_CLIENT_ID', ''),
    'client_secret' => env('YANDEX_OAUTH_CLIENT_SECRET', ''),
],
```

**Security Note:**
- Client Secret is used only on the server for Web OAuth flow (exchanging authorization code for access token)
- Mobile apps should use empty string for Client Secret (Yandex allows this)
- Never commit `.env` file with secrets to version control

## OAuth Flow

### Android Flow

1. User clicks "Connect Yandex" in the app
2. App opens Yandex OAuth page in Custom Tabs with scope `mail:imap_full login:email`
3. User authorizes the application
4. Yandex redirects to deep link: `xboard://yandex-oauth?code=...`
5. `MainActivity` receives the deep link and extracts authorization code
6. Code is passed to `YandexOAuthManager`
7. `SettingsScreen` exchanges code for access token
8. App gets user info (email) from Yandex API
9. Email account is saved to `EmailAccountStorage`

### Web Flow

1. User clicks "Connect Yandex" in the web app
2. App redirects to Yandex OAuth page
3. User authorizes the application
4. Yandex redirects to: `https://api.xdexboard.com/api/oauth/yandex/callback?code=...`
5. `OAuthController::yandexCallback()` receives the callback
6. Controller redirects user back to web app: `https://xdexboard.com/oauth/callback?provider=yandex&code=...`
7. Web app exchanges code for access token
8. App gets user info (email) from Yandex API
9. Email account is saved

## API Endpoints

### OAuth Callback Endpoint

**URL:** `GET /api/oauth/yandex/callback`

**Parameters:**
- `code` (string, optional): Authorization code from Yandex (on success)
- `error` (string, optional): Error code from Yandex (on failure)
- `error_description` (string, optional): Error description from Yandex
- `state` (string, optional): State parameter for CSRF protection

**Response:**
- On success: Redirects to `APP_WEB_URL/oauth/callback?provider=yandex&code=...`
- On error: Redirects to `APP_WEB_URL/oauth/callback?provider=yandex&error=...`
- For JSON requests: Returns JSON response with code/error

**Implementation:** `app/Http/Controllers/Api/OAuthController.php`

## Configuration Files

### Client Configuration

**File:** `xboard-client/shared/src/commonMain/kotlin/com/xdexboard/xboard/config/OAuthConfig.kt`

```kotlin
object OAuthConfig {
    const val YANDEX_CLIENT_ID = "" // Set after registration
    const val YANDEX_REDIRECT_URI_WEB = "https://api.xdexboard.com/api/oauth/yandex/callback"
    const val YANDEX_REDIRECT_URI_ANDROID = "xboard://yandex-oauth" // Internal use only
}
```

### API Configuration

**File:** `xboard-api/config/app.php`

```php
'web_app_url' => env('APP_WEB_URL', 'https://xdexboard.com'),
```

**File:** `xboard-api/.env`

```env
APP_WEB_URL=https://xdexboard.com
```

## Security Notes

- **Client Secret Security:**
  - **Mobile apps:** Client Secret should NEVER be stored in mobile app code (can be decompiled)
  - **Mobile apps:** Use empty string for Client Secret - Yandex allows this for mobile apps
  - **Web apps:** Client Secret MUST be stored on the server (backend) only, never in client-side code
  - **Web apps:** Server exchanges authorization code for access token using Client Secret
- The redirect URI for web must exactly match the registered URI in Yandex OAuth
- **Android with Custom Tabs:** Deep link `xboard://yandex-oauth` must be registered in Callback URI list in Yandex OAuth (even though Android registration form doesn't show redirect URI field)
- **Android with official SDK:** No redirect URI registration needed (SDK handles redirects internally)

## Troubleshooting

### Android: Deep link not working
- Check `AndroidManifest.xml` has deep link intent filter for `xboard://yandex-oauth`
- Verify `MainActivity::handleIntent()` is called on deep link

### Web: Redirect URI mismatch
- Ensure redirect URI in Yandex OAuth exactly matches: `https://api.xdexboard.com/api/oauth/yandex/callback`
- Check `APP_WEB_URL` is correctly set in API configuration

### Authorization code not received
- Check logs in `OAuthController::yandexCallback()` for incoming requests
- Verify Client ID is correctly set in `OAuthConfig`
- Ensure OAuth scopes include `mail:imap_full` (entered manually in "Дополнительные" field)

## Future Implementations

- Gmail OAuth integration
- Outlook OAuth integration
- Token refresh mechanism
- Client Secret support for web (if required)

