# Client Implementation Guide: OAuth Email Forwarding

## Overview

Client app uses OAuth to get email in **raw format** (preserves DKIM), then forwards it to server.

## Flow

```
1. User selects email in app
2. OAuth request (gmail.readonly / Mail.Read)
3. Get email in raw format via API
4. POST base64(raw MIME) to `/api/v1/payments/verify-email`
5. Server verifies DKIM (original email preserved)
```

## Android Implementation (Kotlin)

### 1. Add Dependencies

```kotlin
// build.gradle.kts
dependencies {
    // Gmail API
    implementation("com.google.api-client:google-api-client-android:2.0.0")
    implementation("com.google.apis:google-api-services-gmail:v1-rev20220404-2.0.0")
    
    // OAuth
    implementation("com.google.android.gms:play-services-auth:20.7.0")
    
}
```

### 2. OAuth Setup

```kotlin
// EmailOAuthManager.kt
class EmailOAuthManager(private val context: Context) {
    private val REQUEST_CODE_GOOGLE_SIGN_IN = 1001
    
    fun requestGmailAccess() {
        val googleSignInOptions = GoogleSignInOptions.Builder(GoogleSignInOptions.DEFAULT_SIGN_IN)
            .requestScopes(Scope("https://www.googleapis.com/auth/gmail.readonly"))
            .requestEmail()
            .build()
        
        val googleSignInClient = GoogleSignIn.getClient(context, googleSignInOptions)
        val signInIntent = googleSignInClient.signInIntent
        (context as Activity).startActivityForResult(signInIntent, REQUEST_CODE_GOOGLE_SIGN_IN)
    }
    
    fun handleSignInResult(result: GoogleSignInResult): String? {
        if (result.isSuccess) {
            val account = result.signInAccount
            return account?.idToken // Use this for Gmail API
        }
        return null
    }
}
```

### 3. Get Email in Raw Format

```kotlin
// GmailEmailService.kt
class GmailEmailService(private val accessToken: String) {
    private val gmailService: Gmail by lazy {
        val credential = GoogleCredential().setAccessToken(accessToken)
        Gmail.Builder(
            AndroidHttp.newCompatibleTransport(),
            GsonFactory.getDefaultInstance(),
            credential
        ).setApplicationName("XBoard").build()
    }
    
    /**
     * Get email in raw format (preserves DKIM!)
     */
    suspend fun getEmailRaw(messageId: String): String = withContext(Dispatchers.IO) {
        val message = gmailService.users().messages()
            .get("me", messageId)
            .setFormat("raw") // Critical: raw format preserves all headers including DKIM
            .execute()
        
        // Decode base64url to base64
        val rawBase64 = message.raw
            .replace("-", "+")
            .replace("_", "/")
        
        // Decode base64 to string
        val rawEmailBytes = Base64.decode(rawBase64, Base64.DEFAULT)
        String(rawEmailBytes, Charsets.UTF_8)
    }
    
    /**
     * List emails from banks
     */
    suspend fun listBankEmails(): List<Message> = withContext(Dispatchers.IO) {
        val query = "from:sberbank.ru OR from:tinkoff.ru OR from:alfabank.ru"
        val response = gmailService.users().messages()
            .list("me")
            .setQ(query)
            .setMaxResults(50L)
            .execute()
        
        response.messages ?: emptyList()
    }
}
```

### 4. Send raw MIME to the API

```kotlin
// ApiService.kt
class ApiService {
    suspend fun verifyPaymentEmail(
        emailRaw: String,
        transactionId: Int
    ): ApiResponse {
        val base64Email = Base64.encodeToString(
            emailRaw.toByteArray(Charsets.UTF_8),
            Base64.NO_WRAP
        )
        
        return httpClient.post("/api/v1/payments/verify-email") {
            contentType(ContentType.Application.Json)
            setBody(mapOf(
                "email_raw" to base64Email,
                "transaction_id" to transactionId
            ))
        }
    }
}
```

### 5. Complete Flow

```kotlin
// PaymentVerificationViewModel.kt
class PaymentVerificationViewModel : ViewModel() {
    private val gmailService = GmailEmailService(accessToken)
    private val emailSender = EmailSender()
    
    suspend fun verifyPayment(messageId: String, transactionId: Int): Result<Unit> {
        return try {
            // 1. Get email in raw format
            val rawEmail = gmailService.getEmailRaw(messageId)
            
            // 2. Quick check: is it from bank?
            if (!isFromBank(rawEmail)) {
                return Result.failure(Exception("Email is not from a bank"))
            }
            
            // 3. Send to server (SMTP or API)
            emailSender.sendRawEmailViaSMTP(rawEmail)
            // OR: apiService.verifyPaymentEmail(rawEmail, transactionId)
            
            Result.success(Unit)
        } catch (e: Exception) {
            Result.failure(e)
        }
    }
    
    private fun isFromBank(rawEmail: String): Boolean {
        val allowedBanks = listOf(
            "sberbank.ru", "tinkoff.ru", "alfabank.ru"
        )
        
        return allowedBanks.any { bank ->
            rawEmail.contains("From:.*@$bank", RegexOption.IGNORE_CASE)
        }
    }
}
```

## Outlook Implementation

### OAuth Setup

```kotlin
// OutlookOAuthManager.kt
class OutlookOAuthManager {
    private val clientId = "your_client_id"
    private val redirectUri = "your_redirect_uri"
    private val scope = "Mail.Read" // Minimal scope
    
    fun requestAccess() {
        val authUrl = "https://login.microsoftonline.com/common/oauth2/v2.0/authorize" +
            "?client_id=$clientId" +
            "&response_type=code" +
            "&redirect_uri=$redirectUri" +
            "&scope=$scope"
        
        // Open browser for OAuth
    }
}
```

### Get Email in Raw Format

```kotlin
// OutlookEmailService.kt
class OutlookEmailService(private val accessToken: String) {
    suspend fun getEmailRaw(messageId: String): String = withContext(Dispatchers.IO) {
        val response = httpClient.get(
            "https://graph.microsoft.com/v1.0/me/messages/$messageId/\$value"
        ) {
            header("Authorization", "Bearer $accessToken")
        }
        
        response.bodyAsText() // Raw email format
    }
}
```

## Why Raw Format Preserves DKIM

### Gmail API Raw Format

When you request `format=raw`:
- Returns email in **RFC 2822 format**
- **All headers preserved** (including DKIM-Signature)
- **Body unchanged**
- **Base64url encoded** (need to decode)

### Example Raw Email

```
Return-Path: <noreply@sberbank.ru>
Received: ...
DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/simple; d=sberbank.ru; ...
From: noreply@sberbank.ru
To: user@gmail.com
Subject: Оплата получена

Body content...
```

**All headers intact = DKIM verification passes!**

## Security Best Practices

### 1. OAuth Scopes

**Gmail:**
- ✅ `https://www.googleapis.com/auth/gmail.readonly` - Read-only
- ❌ `https://www.googleapis.com/auth/gmail` - Full access (not needed)

**Outlook:**
- ✅ `Mail.Read` - Read emails only
- ❌ `Mail.ReadWrite` - Full access (not needed)

### 2. Token Storage

```kotlin
// Store OAuth token securely
val encryptedToken = encrypt(accessToken)
SharedPreferences.edit()
    .putString("gmail_access_token_encrypted", encryptedToken)
    .apply()
```

### 3. Raw Email Handling

- **Never modify** raw email before sending
- **Preserve all headers** (especially DKIM-Signature)
- **Send as-is** to server
- **Base64 encode** if sending via API

## User Experience

### OAuth Request Screen

```
"XBoard needs access to read emails to verify payments.

We will only access emails you select, not your entire inbox.
Your emails remain private and secure.

[Grant Access] [Cancel]"
```

### Email Selection Screen

```
"Select bank payment email to verify:

[Email 1] Sberbank - Payment received
[Email 2] T-Bank - Transfer completed
[Email 3] Alfa Bank - Payment notification

[Refresh]"
```

### Verification Result

```
"✅ Payment verified!

Bank: Sberbank
Amount: 1,000.00 RUB
Date: 22.12.2025
Reference: ABC123

Transaction updated successfully."
```

## Testing

### Test Gmail API

```kotlin
// Test getting raw email
val rawEmail = gmailService.getEmailRaw("message_id_here")
println("DKIM-Signature present: ${rawEmail.contains("DKIM-Signature")}")
```

### Test verify-email

```kotlin
apiService.verifyPaymentEmail(
    emailRaw = Base64.encodeToString(testRawEmail.toByteArray(Charsets.UTF_8), Base64.NO_WRAP),
    transactionId = testTransactionId
)
```

## Troubleshooting

### OAuth Fails
- Check OAuth credentials in Google Cloud Console
- Verify redirect URI matches
- Check scope permissions

### Raw Email Missing DKIM
- Ensure using `format=raw` (not `format=full` or `format=metadata`)
- Check email actually has DKIM signature
- Verify base64 decoding is correct

## API Endpoint

Server endpoint already ready:

```http
POST /api/v1/payments/verify-email
Authorization: Bearer {token}
Content-Type: application/json

{
  "email_raw": "base64_encoded_raw_email",
  "transaction_id": 123
}
```

Response:
```json
{
  "success": true,
  "message": "Payment verified successfully",
  "data": {
    "transaction_id": 123,
    "bank": "sberbank.ru",
    "amount": 1000.00,
    "verified_at": "2025-12-22T12:00:00Z"
  }
}
```

