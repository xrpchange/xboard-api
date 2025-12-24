# Client-Side Email Integration (Secure Approach)

## Problem with Full IMAP Access

**Security & Privacy Concerns:**
- ❌ Full access to user's email inbox
- ❌ Privacy violation (reading all emails)
- ❌ Security risk (stored credentials)
- ❌ User trust issues
- ❌ GDPR/compliance concerns

## ✅ Recommended: OAuth + Raw Email Forwarding

### Architecture

```
User's Email Client (Gmail/Outlook app)
    ↓
User selects specific bank email in our app
    ↓
App requests OAuth (minimal scope: read this email)
    ↓
App gets email in RAW format via API (DKIM preserved!)
    ↓
App sends raw email to check@xdexboard.com via SMTP
    ↓
Server receives original email (DKIM intact)
    ↓
Server verifies DKIM (original email preserved)
```

### How It Works

1. **User selects email** in our app (from email list)
2. **OAuth request** - minimal scope: `gmail.readonly` (Gmail) or `Mail.Read` (Outlook)
3. **Get raw email** - API returns email in raw format (all headers + DKIM preserved)
4. **Send to server** - App sends raw email to `check@xdexboard.com` via SMTP
5. **Server verifies** - Original email with DKIM signature

### Advantages

- ✅ **Minimal permissions** - only read access (not full inbox)
- ✅ **User control** - user chooses which email to verify
- ✅ **DKIM preserved** - raw format = original email
- ✅ **No .eml file needed** - works with any email provider
- ✅ **Server-side verification** - secure and legally sound
- ✅ **Privacy** - only selected email, not full inbox

## Implementation: OAuth + Raw Email Forwarding

### How It Works

1. **User selects email** in our app (from email list)
2. **OAuth request** - minimal scope: `gmail.readonly` (Gmail) or `Mail.Read` (Outlook)
3. **Get raw email** - API returns email in **raw format** (all headers + DKIM preserved)
4. **Send to server** - App sends raw email to `check@xdexboard.com` via SMTP
5. **Server verifies** - Original email with DKIM signature

### Gmail API Implementation

```kotlin
// Android (Kotlin)
class GmailEmailForwarder {
    suspend fun forwardEmailToServer(messageId: String) {
        // 1. Get email in RAW format (preserves DKIM!)
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
        
        // 3. Send raw email to server via SMTP
        // (or via API endpoint if SMTP not available)
        sendRawEmailToServer(rawEmail)
    }
    
    private fun sendRawEmailToServer(rawEmail: String) {
        // Option A: Send via SMTP (preserves all headers)
        val session = Session.getDefaultInstance(Properties())
        val message = MimeMessage(session)
        message.setContent(
            ByteArrayInputStream(rawEmail.toByteArray()),
            "message/rfc822"
        )
        message.setRecipient(
            Message.RecipientType.TO,
            InternetAddress("check@xdexboard.com")
        )
        Transport.send(message)
        
        // Option B: Send via API (if SMTP not available)
        // apiService.verifyPaymentEmail(
        //     emailRaw = Base64.encodeToString(rawEmail.toByteArray()),
        //     transactionId = transactionId
        // )
    }
}
```

**OAuth Flow:**
1. User selects email in our app
2. App requests OAuth: `gmail.readonly` scope
3. User grants permission (one-time per provider)
4. App gets email in raw format (DKIM preserved)
5. App sends raw email to server (SMTP or API)
6. Server verifies DKIM on original email

### Alternative: Share Email as Attachment (If Available)

**Some email clients support .eml export:**

```kotlin
// Android - Handle email attachment share
class EmailShareReceiver : Activity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        // User shares email from email client
        // App receives .eml file (if supported)
        val emailFile = intent.getParcelableExtra<Uri>(Intent.EXTRA_STREAM)
        
        if (emailFile != null) {
            // Read .eml file (original email with DKIM)
            val rawEmail = readEmailFile(emailFile)
            sendToVerificationServer(rawEmail)
        }
    }
}
```

**Note:** Most email clients don't support .eml export, so OAuth approach is primary.

### Option 3: Email Intent with Attachment

**Android Intent to share email:**

```kotlin
// In email client, user selects "Forward as Attachment"
// Our app receives intent with email attachment
class EmailIntentHandler {
    fun handleEmailIntent(intent: Intent) {
        val emailUri = intent.getParcelableExtra<Uri>(Intent.EXTRA_STREAM)
        
        // Read attached .eml file
        val rawEmail = readEmailAttachment(emailUri)
        
        // Verify it's from bank
        if (isFromBank(rawEmail)) {
            // Send to server
            uploadToServer(rawEmail)
        }
    }
}
```

## Recommended: OAuth + Raw Email Forwarding

### Primary Approach

**OAuth with minimal scope + Raw email forwarding:**

1. **Android App:**
   - User selects email in app
   - Request OAuth: `gmail.readonly` or `Mail.Read`
   - Get email in **raw format** (preserves DKIM)
   - Send raw email to `check@xdexboard.com` via SMTP
   - Or send via API endpoint if SMTP not available

2. **API Endpoint (if SMTP not available):**
   ```php
   POST /api/v1/payments/verify-email
   {
     "email_raw": "base64_encoded_raw_email",
     "transaction_id": 123
   }
   ```

3. **Server:**
   - Receives original email (DKIM preserved)
   - Verifies DKIM
   - Checks bank domain
   - Checks payment patterns
   - Updates transaction

### Why Raw Format Preserves DKIM

- **Raw format** = original email with all headers
- **DKIM-Signature header** included
- **Body unchanged** = signature valid
- **No modification** = DKIM verification passes

## Implementation

### Android App Integration

**1. Intent Filter for Email Sharing:**

```xml
<!-- AndroidManifest.xml -->
<activity android:name=".EmailShareActivity">
    <intent-filter>
        <action android:name="android.intent.action.SEND" />
        <category android:name="android.intent.category.DEFAULT" />
        <data android:mimeType="message/rfc822" />
        <data android:mimeType="application/octet-stream" />
    </intent-filter>
</activity>
```

**2. Handle Shared Email:**

```kotlin
// EmailShareActivity.kt
class EmailShareActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        
        val emailUri = intent.getParcelableExtra<Uri>(Intent.EXTRA_STREAM)
        
        if (emailUri != null) {
            // Read email file
            val emailContent = readEmailFile(emailUri)
            
            // Check if from bank
            if (isFromBank(emailContent)) {
                // Upload to server
                uploadEmailToServer(emailContent)
            } else {
                showError("This email is not from a bank")
            }
        }
    }
    
    private fun readEmailFile(uri: Uri): String {
        // Read .eml file content
        contentResolver.openInputStream(uri)?.use { input ->
            return input.bufferedReader().use { it.readText() }
        }
    }
    
    private fun uploadEmailToServer(emailContent: String) {
        val base64Email = Base64.encodeToString(
            emailContent.toByteArray(),
            Base64.NO_WRAP
        )
        
        // Call API
        apiService.verifyPaymentEmail(
            emailRaw = base64Email,
            transactionId = currentTransactionId
        )
    }
}
```

### API Endpoint

**New endpoint for email verification:**

```php
// app/Http/Controllers/Api/PaymentVerificationController.php
class PaymentVerificationController extends Controller
{
    public function verifyEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email_raw' => 'required|string', // Base64 encoded .eml
            'transaction_id' => 'required|exists:transactions,id',
        ]);
        
        // Decode email
        $rawEmail = base64_decode($request->email_raw);
        
        // Verify DKIM (original email, not forwarded!)
        $dkimResult = $this->verifyDKIM($rawEmail);
        
        // Check bank domain
        $senderCheck = $this->verifySender($rawEmail);
        
        // Check payment pattern
        $paymentData = $this->extractPaymentData($rawEmail);
        
        // Update transaction
        $transaction = Transaction::find($request->transaction_id);
        $transaction->update([
            'status' => 'verified',
            'payment_verified_at' => now(),
        ]);
        
        // Store proof
        $this->storeEmailProof($transaction, $rawEmail, $dkimResult);
        
        return response()->json([
            'success' => true,
            'message' => 'Payment verified successfully',
        ]);
    }
}
```

## Security Comparison

| Aspect | Full IMAP Access | Client Share (.eml) |
|--------|------------------|---------------------|
| **Privacy** | ❌ Full inbox access | ✅ Only shared email |
| **User Control** | ⚠️ All or nothing | ✅ Per-email choice |
| **DKIM Preservation** | ✅ Yes | ✅ Yes (.eml = original) |
| **OAuth Complexity** | ⚠️ Required | ✅ Not needed |
| **Server Storage** | ❌ Credentials stored | ✅ No credentials |
| **Compliance** | ⚠️ GDPR concerns | ✅ GDPR friendly |
| **User Trust** | ⚠️ Low | ✅ High |

## User Experience

### Share Email Flow

1. **User receives bank payment email**
2. **User opens email in Gmail/Outlook**
3. **User clicks "Share" → "XBoard"**
4. **App opens, shows:**
   ```
   "Verify payment from [Bank Name]?"
   [Amount] [Date] [Reference]
   
   [Verify Payment] [Cancel]
   ```
5. **User confirms**
6. **App uploads .eml to server**
7. **Server verifies DKIM**
8. **Transaction updated**

### Benefits

- ✅ **Transparent** - user sees exactly what's shared
- ✅ **Secure** - no full inbox access
- ✅ **Simple** - familiar share flow
- ✅ **Fast** - one tap to verify

## Implementation Priority

1. **Immediate:** Share as .eml file (no OAuth)
2. **Future:** Optional OAuth for auto-verification (if users request)

## Conclusion

**Multiple Methods Supported:**

1. **OAuth + Raw Email Forwarding** (Recommended - Automated)
   - ✅ Preserves DKIM (raw format = original email)
   - ✅ Minimal permissions (`gmail.readonly` / `Mail.Read`)
   - ✅ Fully automated
   - ✅ Works with Gmail and Outlook

2. **.eml File Upload** (Manual - Best DKIM Preservation)
   - ✅ DKIM preserved (.eml = original email)
   - ✅ Works with Thunderbird, Outlook, Apple Mail
   - ✅ No OAuth needed

3. **Manual Email Forwarding** (Manual - Preserves DKIM if done correctly)
   - ✅ Forward as attachment to `check@xdexboard.com`
   - ✅ Works with any email client
   - ✅ No app needed

4. **Copy-Paste Email Content** (Manual - No DKIM, but acceptable)
   - ✅ Works with any email client
   - ✅ No OAuth needed
   - ✅ Seller has full templates to verify

5. **Client-Side Email Copy** (Semi-Automated)
   - ✅ OAuth to copy email content
   - ✅ Pattern matching verification
   - ✅ Seller has full templates

**See `EMAIL_VERIFICATION_METHODS.md` for complete guide with instructions for all methods.**

