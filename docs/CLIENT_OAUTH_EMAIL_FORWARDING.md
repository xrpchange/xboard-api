# OAuth Email Forwarding with DKIM Preservation

## Overview

Client app uses OAuth to get email in **raw format** (preserves DKIM), then sends it to the server with **`POST /api/v1/payments/verify-email`** (base64 raw MIME over HTTPS) without modifying the message body or headers.

## Architecture

```
┌─────────────────────────────────────────┐
│  User's Email (Gmail/Outlook)          │
└──────────────┬──────────────────────────┘
               │
               │ 1. User selects email in our app
               │
               ▼
┌─────────────────────────────────────────┐
│  XBoard Client App                       │
│  - Requests OAuth (minimal scope)        │
│  - Gets email in RAW format              │
└──────────────┬──────────────────────────┘
               │
               │ 2. OAuth: gmail.readonly / Mail.Read
               │
               ▼
┌─────────────────────────────────────────┐
│  Gmail/Outlook API                       │
│  - Returns email in raw format          │
│  - All headers preserved (DKIM intact)  │
└──────────────┬──────────────────────────┘
               │
               │ 3. Raw email (base64 encoded)
               │
               ▼
┌─────────────────────────────────────────┐
│  XBoard Client App                       │
│  - Decodes raw email                     │
│  - POST /api/v1/payments/verify-email    │
│    (JSON: base64 raw MIME, transaction id)│
└──────────────┬──────────────────────────┘
               │
               │ 4. HTTPS (original bytes unchanged)
               │
               ▼
┌─────────────────────────────────────────┐
│  XBoard API (Laravel)                    │
│  - Decodes base64 → original MIME        │
│  - DKIM signature preserved              │
└──────────────┬──────────────────────────┘
               │
               │ 5. Verification pipeline
               │
               ▼
┌─────────────────────────────────────────┐
│  Server Verification                     │
│  - Verifies DKIM (original email)       │
│  - Checks bank domain                    │
│  - Updates transaction                   │
└─────────────────────────────────────────┘
```

## Implementation

### Android (Kotlin) - Gmail API

```kotlin
class GmailEmailForwarder {
    private val gmailService: Gmail
    
    /**
     * Get email in raw format and forward to server
     */
    suspend fun forwardEmailToServer(messageId: String): Result<Unit> {
        return try {
            // 1. Get email in raw format (preserves DKIM!)
            val message = gmailService.users().messages()
                .get("me", messageId)
                .setFormat("raw") // Critical: raw format preserves DKIM
                .execute()
            
            // 2. Decode base64 raw email
            val rawEmailBytes = Base64.decode(
                message.raw.replace("-", "+").replace("_", "/"),
                Base64.DEFAULT
            )
            val rawEmail = String(rawEmailBytes, Charsets.UTF_8)
            
            // 3. Check if from bank (before sending)
            if (!isFromBank(rawEmail)) {
                return Result.failure(Exception("Email is not from a bank"))
            }
            
            // 4. Send raw MIME to XBoard API
            sendRawEmailToServer(rawEmail)
            
            Result.success(Unit)
        } catch (e: Exception) {
            Result.failure(e)
        }
    }
    
    /**
     * POST base64(raw MIME) to /api/v1/payments/verify-email
     */
    private suspend fun sendRawEmailToServer(rawEmail: String) {
        val emailRaw = Base64.encodeToString(
            rawEmail.toByteArray(Charsets.UTF_8),
            Base64.NO_WRAP
        )
        apiService.verifyPaymentEmail(emailRaw = emailRaw, transactionId = transactionId)
    }
    
    private fun isFromBank(rawEmail: String): Boolean {
        // Quick check before sending
        val allowedBanks = listOf(
            "sberbank.ru", "tinkoff.ru", "alfabank.ru"
        )
        
        val fromHeader = rawEmail.lines()
            .firstOrNull { it.startsWith("From:", ignoreCase = true) }
            ?: return false
        
        return allowedBanks.any { bank ->
            fromHeader.contains(bank, ignoreCase = true)
        }
    }
}
```

### OAuth Setup

**Gmail OAuth:**

```kotlin
// Request minimal scope
val scope = "https://www.googleapis.com/auth/gmail.readonly"

// OAuth flow
val googleSignInOptions = GoogleSignInOptions.Builder(GoogleSignInOptions.DEFAULT_SIGN_IN)
    .requestScopes(Scope(scope))
    .requestEmail()
    .build()

val googleSignInClient = GoogleSignIn.getClient(context, googleSignInOptions)
val signInIntent = googleSignInClient.signInIntent
startActivityForResult(signInIntent, REQUEST_CODE)
```

**Outlook OAuth:**

```kotlin
// Microsoft Graph API
val scope = "Mail.Read" // Minimal scope - only read emails

// OAuth flow with Microsoft Authentication Library (MSAL)
```

### Web (Kotlin/JS) - Gmail API

```kotlin
// Shared code for KMP
expect class EmailApiClient {
    suspend fun getEmailRaw(messageId: String): String
    suspend fun sendRawEmailToServer(rawEmail: String)
}

// Android implementation
actual class EmailApiClient {
    actual suspend fun getEmailRaw(messageId: String): String {
        // Gmail API call
    }
}

// JS implementation
actual class EmailApiClient {
    actual suspend fun getEmailRaw(messageId: String): String {
        // Gmail API via fetch
    }
}
```

## Server-Side

### Primary: HTTPS verify-email

Clients should call the API directly (no app-configured “forward to” mailbox env on the server).

### Optional: operator mailbox + Postfix

Some deployments may still pipe **a mailbox they configure** into the verifier script; that is infrastructure outside the Laravel app and is **not** required for clients.

### API contract

```php
POST /api/v1/payments/verify-email
{
  "email_raw": "base64_encoded_raw_email",
  "transaction_id": 123
}
```

Client sends raw email via HTTPS (preferred).

## Security Considerations

### OAuth Scopes

**Gmail:**
- ✅ `https://www.googleapis.com/auth/gmail.readonly` - Read-only access
- ❌ `https://www.googleapis.com/auth/gmail` - Full access (not needed)

**Outlook:**
- ✅ `Mail.Read` - Read emails only
- ❌ `Mail.ReadWrite` - Full access (not needed)

### Raw Email Handling

- **Never modify** raw email before sending
- **Preserve all headers** (especially DKIM-Signature)
- **Send as-is** to server
- **Base64 encode** the raw MIME for the JSON body

### User Experience

**OAuth Request Screen:**
```
"XBoard needs access to read this email to verify 
the payment. We will only access the email you 
select, not your entire inbox.

[Grant Access] [Cancel]"
```

**After OAuth:**
```
"Email verified! Payment confirmed."
```

## Comparison: OAuth vs IMAP

| Aspect | OAuth (Recommended) | IMAP (Server-side) |
|--------|---------------------|---------------------|
| **Permissions** | ✅ Minimal (read only) | ❌ Full inbox access |
| **User Control** | ✅ Per-email choice | ❌ All or nothing |
| **DKIM Preservation** | ✅ Yes (raw format) | ✅ Yes (original) |
| **Privacy** | ✅ Only selected email | ❌ All emails |
| **Server Storage** | ✅ No credentials | ❌ Encrypted passwords |
| **Compliance** | ✅ GDPR friendly | ⚠️ GDPR concerns |
| **Provider Support** | ⚠️ Gmail/Outlook only | ✅ Any IMAP |

## Implementation Steps

### 1. Client App (Android)

1. Add Gmail API dependency
2. Implement OAuth flow
3. Get email in raw format
4. Send to server (`POST /api/v1/payments/verify-email`)

### 2. Server

The API endpoint `POST /api/v1/payments/verify-email` accepts the raw message and runs verification.

### 3. Testing

1. Test OAuth flow
2. Test raw email retrieval
3. Test HTTPS upload to verify-email
4. Verify DKIM on server

## Advantages

- ✅ **DKIM preserved** - raw format = original email
- ✅ **Minimal permissions** - only read access
- ✅ **User control** - chooses which email
- ✅ **No .eml file needed** - works with API
- ✅ **Server-side verification** - secure
- ✅ **Works with Gmail/Outlook** - major providers

## Limitations

- ⚠️ **Provider support** - Gmail and Outlook only (not custom IMAP)
- ⚠️ **OAuth required** - but minimal scope
- ⚠️ **User must grant access** - but only once per provider

## Conclusion

**Best approach:** OAuth + Raw Email Forwarding
- ✅ Preserves DKIM (raw format)
- ✅ Minimal permissions (read only)
- ✅ User control (selects email)
- ✅ Server-side verification (secure)
- ✅ No .eml file needed

