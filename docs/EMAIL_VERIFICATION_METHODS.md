# Email Verification Methods - Complete Guide

## Overview

XBoard supports **multiple methods** for verifying payment emails, from fully automated OAuth forwarding to manual copy-paste. Choose the method that works best for your situation.

## Available Methods

### 1. ✅ OAuth + Raw Email Forwarding (Recommended - Automated)

**Best for:** Gmail and Outlook users who want automatic verification

**How it works:**
- Client app uses OAuth to get email in raw format (preserves DKIM)
- App POSTs base64(raw MIME) to `POST /api/v1/payments/verify-email`
- Server verifies DKIM and processes payment

**Advantages:**
- ✅ DKIM preserved (raw format)
- ✅ Fully automated
- ✅ Secure (minimal OAuth scope)
- ✅ Works with Gmail/Outlook

**Limitations:**
- ⚠️ **Yandex personal accounts (@yandex.ru) are NOT supported** - Yandex does not allow IMAP access via OAuth for personal accounts. Use copy-paste method (Method 4) or .eml export (Method 2) instead.
- ⚠️ Yandex 360 (business) accounts may work, but are not tested.

**Implementation:**
See `CLIENT_OAUTH_EMAIL_FORWARDING.md` for detailed guide.

---

### 2. ✅ .eml File Upload (Manual - Best DKIM Preservation)

**Best for:** Users who can export emails as .eml files

**How it works:**
- User exports email as .eml file from email client
- User uploads .eml file in app
- App sends to server for verification

**Advantages:**
- ✅ DKIM preserved (.eml = original email)
- ✅ Works with any email client that supports .eml export
- ✅ No OAuth needed

**Limitations:**
- ⚠️ Not all email clients support .eml export
- ⚠️ Manual process

**Supported Clients:**
- ✅ Thunderbird (File → Save As → .eml)
- ✅ Outlook (File → Save As → .eml)
- ✅ Apple Mail (File → Save As → .eml)
- ❌ Gmail Web (no .eml export)
- ❌ Outlook Web (no .eml export)

---

### 3. ⚠️ Manual forward then upload (Manual - DKIM may not be preserved)

**Best for:** Users who cannot use OAuth (e.g., Yandex personal accounts) but can save or share the message

**How it works:**
- User forwards or saves the bank message (e.g. as `.eml` / attachment) and uploads it in the client, **or** pastes content for `verify-email-content`
- The app calls `POST /api/v1/payments/verify-email` (raw MIME) or `verify-email-content` (text path)
- If DKIM is broken, the server falls back to pattern matching where applicable

**Advantages:**
- ✅ No OAuth needed
- ✅ Works with any email client that can export/share the message into the app

**Limitations:**
- ⚠️ **DKIM is usually NOT preserved** when forwarding from web interfaces
- ⚠️ Server may rely on pattern matching instead of DKIM
- ⚠️ Less secure than raw MIME with intact DKIM, but acceptable for P2P with seller template verification

**Instructions:**
1. Open the bank email in the client
2. Prefer **Save as .eml** or **share attachment** into XBoard; otherwise use **copy-paste** (method 4)
3. **Note:** Plain “forward in mail client” without upload does not hit the API — the app must send the bytes to `verify-email` / `verify-email-content`

---

### 4. ✅ Copy-Paste Email Content (Manual - No DKIM)

**Best for:** Users who cannot use other methods (no OAuth, no .eml, forwarding breaks DKIM)

**How it works:**
- User copies email content (subject + body) from email client
- User pastes in app
- App sends to server
- Server verifies using pattern matching (no DKIM check)
- Seller has full templates to verify authenticity

**Advantages:**
- ✅ Works with any email client
- ✅ No OAuth needed
- ✅ No .eml file needed
- ✅ Simple for users

**Limitations:**
- ⚠️ No DKIM verification (relies on pattern matching)
- ⚠️ Seller must verify using templates
- ⚠️ Less secure (but acceptable for P2P)

**Instructions:**

1. **Open email from bank** in your email client
2. **Copy email content:**
   - Copy subject line
   - Copy email body (all text)
   - Note the sender email address
3. **Paste in XBoard app:**
   - Open transaction
   - Click "Verify Payment"
   - Select "Copy-Paste Method"
   - Paste email content
   - Enter sender email address
   - Submit

**What to copy:**
```
Subject: Оплата получена

Здравствуйте!

Поступил платеж на сумму 1,000.00 RUB
Дата: 22.12.2025
Номер операции: ABC123456

С уважением,
Сбербанк
```

---

### 5. ✅ Client-Side Email Copy (Semi-Automated)

**Best for:** Users who can grant OAuth but want to select specific email

**How it works:**
- User grants OAuth (minimal scope)
- User selects email in app
- App copies email content (subject + body)
- App sends to server
- Server verifies using pattern matching

**Advantages:**
- ✅ User controls which email
- ✅ No DKIM needed (pattern matching)
- ✅ Semi-automated

**Implementation:**
Similar to OAuth method, but instead of forwarding raw email, app extracts content and sends via API.

---

## API Endpoints

### Get Email List (OAuth Access Token Required)

**Note:** This endpoint uses OAuth access tokens from email providers (Yandex, Gmail, Outlook), **NOT escrow tokens**. The access token is passed as a query parameter.

```http
GET /api/v1/payments/emails?provider=yandex&email=user@yandex.ru&access_token={oauth_access_token}&limit=50&offset=0
Content-Type: application/json
```

**Query Parameters:**
- `provider` (required): Email provider - `yandex`, `gmail`, or `outlook`
- `email` (required): User's email address
- `access_token` (required): OAuth access token from the email provider
- `limit` (optional): Maximum number of emails to return (default: 50, max: 100)
- `offset` (optional): Offset for pagination (default: 0)

**Response:**
```json
{
  "success": true,
  "data": {
    "emails": [
      {
        "message_id": "12345",
        "email_num": 12345,
        "subject": "Payment received",
        "from": "noreply@sberbank.ru",
        "date": "2025-12-24 12:00:00",
        "read": false
      }
    ],
    "count": 1,
    "limit": 50,
    "offset": 0
  }
}
```

**Authentication:**
- ❌ **Escrow token NOT required** - this endpoint uses OAuth access token only
- ✅ OAuth access token must be valid and have appropriate scopes
- ⚠️ **Yandex Limitation:** Personal Yandex accounts (@yandex.ru) **do not support IMAP access via OAuth**. This endpoint will fail for Yandex personal accounts. Use copy-paste method (Method 4) or .eml export (Method 2) instead.

---

### Get Raw Email (OAuth Access Token Required)

**Note:** This endpoint uses OAuth access tokens from email providers, **NOT escrow tokens**. The access token is passed as a query parameter.

```http
GET /api/v1/payments/emails/{messageId}?provider=yandex&email=user@yandex.ru&access_token={oauth_access_token}
Content-Type: application/json
```

**URL Parameters:**
- `messageId` (required): Email message ID or email number (from getEmailList response)

**Query Parameters:**
- `provider` (required): Email provider - `yandex`, `gmail`, or `outlook`
- `email` (required): User's email address
- `access_token` (required): OAuth access token from the email provider

**Response:**
```json
{
  "success": true,
  "data": {
    "message_id": "12345",
    "email_raw": "base64_encoded_raw_email_with_full_headers_and_dkim"
  }
}
```

**Authentication:**
- ❌ **Escrow token NOT required** - this endpoint uses OAuth access token only
- ✅ OAuth access token must be valid and have appropriate scopes

---

### Verify Email (Multiple Formats)

**Note:** This endpoint is currently used for testing email verification and DKIM validation. `transaction_id` is optional.

```http
POST /api/v1/payments/verify-email
Content-Type: application/json

{
  "email_data": "base64_encoded_email_or_plain_text",
  "format": "raw|eml|plain",
  "from_address": "noreply@sberbank.ru"  // Required for "plain" format
}
```

**Authentication:**
- ❌ **Escrow token NOT required** - currently used for testing DKIM validation only
- Endpoint verifies DKIM signature and email structure

**Formats:**
- `raw`: OAuth raw email (base64 encoded)
- `eml`: .eml file (base64 encoded)
- `plain`: Plain text copy-paste (not base64, just text)

**Response:**
```json
{
  "success": true,
  "message": "Payment verified successfully",
  "data": {
    "transaction_id": 123,
    "bank": "sberbank.ru",
    "amount": 1000.00,
    "verified_at": "2025-12-22T12:00:00Z",
    "dkim_verified": true
  }
}
```

---

## Authentication Summary

| Endpoint | Escrow Token Required? | OAuth Token Required? | Notes |
|----------|----------------------|---------------------|-------|
| `GET /api/v1/payments/emails` | ❌ No | ✅ Yes | Uses OAuth access token only |
| `GET /api/v1/payments/emails/{messageId}` | ❌ No | ✅ Yes | Uses OAuth access token only |
| `POST /api/v1/payments/verify-email` | ❌ No | ❌ No | Testing mode - verifies DKIM and email structure |
| `POST /api/v1/payments/verify-email-content` | ❌ No | ❌ No | Testing mode - DKIM, sender, optional `bank_id` |

**Authentication Summary:**
- Email list/raw endpoints use OAuth because they access the user's email account directly (Yandex/Gmail/Outlook)
- Verification endpoints are currently in testing mode - no authentication required
- All endpoints focus on testing email verification and DKIM validation functionality
- No transaction-related data required - pure email verification and DKIM testing

---

## Comparison Table

| Method | DKIM | Automation | OAuth | Complexity | Security |
|--------|------|------------|-------|------------|----------|
| OAuth + Raw | ✅ | ✅ Full | ✅ | Medium | High |
| .eml Upload | ✅ | ⚠️ Manual | ❌ | Low | High |
| Manual Forward | ✅* | ⚠️ Manual | ❌ | Low | High |
| Copy-Paste | ❌ | ⚠️ Manual | ❌ | Very Low | Medium** |
| Client Copy | ❌ | ⚠️ Semi | ✅ | Medium | Medium** |

*If forwarded correctly (as attachment)
**Relies on pattern matching + seller verification

---

## Recommended Flow for Users

### Step 1: Try OAuth (If Available)
- If using Gmail/Outlook → Use OAuth method
- Fastest and most secure

### Step 2: Try .eml Export (If Available)
- If email client supports .eml → Export and upload
- Preserves DKIM

### Step 3: Manual path (If OAuth unavailable)
- Export/share as `.eml` or attachment and upload in the app (`verify-email`)
- Preserves DKIM when the original MIME is preserved

### Step 4: Use Copy-Paste (Fallback)
- Copy email content and paste in app
- No DKIM, but seller can verify using templates

---

## Security Considerations

### DKIM Verification

**Methods with DKIM:**
- OAuth + Raw Email
- .eml File Upload
- Manual Forward (if done correctly)

**Methods without DKIM:**
- Copy-Paste
- Client Copy

**For methods without DKIM:**
- Server uses pattern matching
- Seller receives full email templates
- Seller can verify authenticity manually
- Acceptable for P2P transactions (seller has full context)

### Fraud Prevention

**Seller Protection:**
- Full email templates stored
- Bank domain verification
- Payment pattern matching
- Transaction history

**Buyer Protection:**
- Payment verification required
- Email proof stored (encrypted)
- Transaction status tracking

---

## Implementation Guide

### Client App (Android/Web/iOS)

**1. OAuth Method:**
```kotlin
// Get email in raw format
val rawEmail = gmailService.getEmailRaw(messageId)
// Send to server
apiService.verifyEmail(
    emailData = Base64.encodeToString(rawEmail.toByteArray()),
    format = "raw",
    transactionId = transactionId
)
```

**2. .eml Upload:**
```kotlin
// User selects .eml file
val emlFile = filePicker.pickFile()
val emlContent = emlFile.readBytes()
// Send to server
apiService.verifyEmail(
    emailData = Base64.encodeToString(emlContent),
    format = "eml",
    transactionId = transactionId
)
```

**3. Copy-Paste:**
```kotlin
// User pastes email content
val emailText = userInput.text
val fromAddress = userInput.fromAddress
// Send to server
apiService.verifyEmail(
    emailData = emailText, // Not base64!
    format = "plain",
    fromAddress = fromAddress,
    transactionId = transactionId
)
```

**4. Manual upload / paste:**
- User brings the message into the app (file upload or paste)
- App calls `verify-email` or `verify-email-content`

---

## Troubleshooting

### DKIM Verification Fails

**Problem:** "DKIM verification failed"

**Solutions:**
1. Try forwarding as attachment (not regular forward)
2. Use OAuth method (preserves DKIM)
3. Export as .eml file
4. Use copy-paste method (no DKIM needed)

### OAuth Not Available

**Problem:** Cannot use OAuth (not Gmail/Outlook)

**Solutions:**
1. Export as .eml file (if supported)
2. Forward as attachment manually
3. Use copy-paste method

### .eml Export Not Available

**Problem:** Email client doesn't support .eml export

**Solutions:**
1. Use OAuth (if Gmail/Outlook)
2. Forward as attachment manually
3. Use copy-paste method

---

## Conclusion

**Best Method:** OAuth + Raw Email Forwarding
- ✅ Preserves DKIM
- ✅ Fully automated
- ✅ Secure

**Fallback Methods:**
- .eml Upload (if available)
- Manual Forward (if done correctly)
- Copy-Paste (always works, no DKIM)

**All methods are acceptable** - choose based on user's email client and preferences. Seller has full templates to verify authenticity even without DKIM.

