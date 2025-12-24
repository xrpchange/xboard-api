# Email Verification Methods - Complete Guide

## Overview

XBoard supports **multiple methods** for verifying payment emails, from fully automated OAuth forwarding to manual copy-paste. Choose the method that works best for your situation.

## Available Methods

### 1. ✅ OAuth + Raw Email Forwarding (Recommended - Automated)

**Best for:** Gmail and Outlook users who want automatic verification

**How it works:**
- Client app uses OAuth to get email in raw format (preserves DKIM)
- App automatically forwards to `check@xdexboard.com`
- Server verifies DKIM and processes payment

**Advantages:**
- ✅ DKIM preserved (raw format)
- ✅ Fully automated
- ✅ Secure (minimal OAuth scope)
- ✅ Works with Gmail/Outlook

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

### 3. ✅ Manual Email Forwarding (Manual - Preserves DKIM)

**Best for:** Users who want to forward emails manually from their email client

**How it works:**
- User forwards email from their email client to `check@xdexboard.com`
- Server receives and verifies DKIM
- Server processes payment

**Advantages:**
- ✅ DKIM preserved (if forwarded correctly)
- ✅ No app needed
- ✅ Works with any email client

**Instructions by Client:**

#### Gmail (Web)

**Method 1: Forward as Attachment (Best - Preserves DKIM)**
1. Open email from bank
2. Click three dots (⋮) → "Forward as attachment"
3. Enter: `check@xdexboard.com`
4. Click "Send"

**Method 2: Regular Forward (May Break DKIM)**
1. Open email from bank
2. Click "Forward"
3. **Important:** Do NOT add any text
4. Enter: `check@xdexboard.com`
5. Click "Send"

#### Outlook (Web)

**Method 1: Forward as Attachment**
1. Open email from bank
2. Click three dots (⋮) → "Forward as attachment"
3. Enter: `check@xdexboard.com`
4. Click "Send"

**Method 2: Regular Forward**
1. Open email from bank
2. Click "Forward"
3. **Important:** Do NOT add any text
4. Enter: `check@xdexboard.com`
5. Click "Send"

#### Thunderbird

**Forward as Attachment:**
1. Right-click email → "Forward As" → "Attachment"
2. Enter: `check@xdexboard.com`
3. Click "Send"

#### Apple Mail (macOS/iOS)

**Forward as Attachment:**
1. Right-click email → "Forward as Attachment"
2. Enter: `check@xdexboard.com`
3. Click "Send"

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

### Verify Email (Multiple Formats)

```http
POST /api/v1/payments/verify-email
Authorization: Bearer {token}
Content-Type: application/json

{
  "email_data": "base64_encoded_email_or_plain_text",
  "transaction_id": 123,
  "format": "raw|eml|plain",
  "from_address": "noreply@sberbank.ru"  // Required for "plain" format
}
```

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

### Forward Email (Client-Side Forwarding)

```http
POST /api/v1/payments/forward-email
Authorization: Bearer {token}
Content-Type: application/json

{
  "email_raw": "base64_encoded_raw_email",
  "transaction_id": 123
}
```

**Response:**
```json
{
  "success": true,
  "message": "Email forwarded to verification server",
  "data": {
    "transaction_id": 123,
    "status": "pending_verification"
  }
}
```

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

### Step 3: Try Manual Forward (If Available)
- Forward as attachment to `check@xdexboard.com`
- Preserves DKIM if done correctly

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

**4. Manual Forward:**
- User forwards email to `check@xdexboard.com`
- Server processes automatically
- No app action needed

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

