# DKIM Signature Preservation Solutions

## Problem

When users forward emails through email clients (Gmail, Outlook, etc.), DKIM signatures are **broken** because:
- Email client adds new headers (From, To, Subject)
- Email client may modify body (add forwarding text)
- DKIM signature validates original headers + body
- Forwarded email = new email = broken DKIM

## Solutions

### ✅ Solution 1: Forward as Attachment (Best UX)

**How it works:**
- User forwards email **as attachment** (not inline)
- Original email preserved as `.eml` file
- DKIM signature intact in attachment
- Server extracts and verifies attached email

**Implementation:**

1. **Update php-verifier.php** to handle attached emails:

```php
function extractAttachedEmail(string $rawEmail): ?string
{
    // Parse multipart email
    // Find attachment with .eml or message/rfc822
    // Extract original email from attachment
    // Return original email with DKIM intact
}
```

2. **User instructions:**
   - Gmail: "Forward as attachment" option
   - Outlook: "Forward as attachment" 
   - Apple Mail: "Forward as attachment"

**Advantages:**
- ✅ DKIM preserved
- ✅ Simple for users
- ✅ Works with any email client
- ✅ No OAuth needed

**Disadvantages:**
- ⚠️ Not all clients support it well
- ⚠️ Some clients may still modify headers

### ✅ Solution 2: IMAP Server-Side Monitoring (Most Reliable)

**How it works:**
- User provides IMAP credentials (one-time)
- Server monitors user's inbox via IMAP IDLE
- Server reads emails directly (preserves DKIM)
- Server filters: only bank emails + payment patterns
- Server verifies DKIM on original email

**Implementation:**

```php
// New service: app/Services/ImapMonitorService.php
class ImapMonitorService
{
    public function monitorUserInbox(string $email, string $password, string $imapHost)
    {
        // Connect via IMAP
        $imap = imap_open(
            "{{$imapHost}:993/imap/ssl}INBOX",
            $email,
            $password
        );
        
        // Use IDLE for real-time notifications
        imap_idle($imap);
        
        // Get new emails
        $emails = imap_search($imap, 'UNSEEN');
        
        foreach ($emails as $emailNum) {
            // Get raw email (preserves DKIM)
            $rawEmail = imap_fetchheader($imap, $emailNum, FT_PREFETCHTEXT) 
                      . imap_body($imap, $emailNum);
            
            // Check if from bank domain
            if (isFromBank($rawEmail)) {
                // Check if matches payment pattern
                if (matchesPaymentPattern($rawEmail)) {
                    // Verify DKIM (original, not forwarded)
                    verifyDKIM($rawEmail);
                    
                    // Process payment
                    processPaymentEmail($rawEmail);
                }
            }
        }
    }
}
```

**Advantages:**
- ✅ DKIM always preserved (original email)
- ✅ Real-time processing
- ✅ Pattern-based filtering
- ✅ Server-side security
- ✅ Works with any IMAP provider

**Disadvantages:**
- ⚠️ Requires IMAP credentials
- ⚠️ Need to store credentials securely
- ⚠️ More complex setup

### ✅ Solution 3: Email Server Forwarding (If User Has Own Server)

**How it works:**
- User configures email server forwarding
- Server forwards email at SMTP level (preserves headers)
- Original email with DKIM arrives at check@xdexboard.com

**Implementation:**

User's email server configuration:
```
# Postfix alias or forwarding rule
user@example.com → check@xdexboard.com
```

**Advantages:**
- ✅ DKIM preserved (server-level forwarding)
- ✅ Automatic
- ✅ No user action needed

**Disadvantages:**
- ❌ Only works if user has own email server
- ❌ Most users use Gmail/Outlook (no server access)

### ✅ Solution 4: OAuth + Server-Side Reading (Hybrid)

**How it works:**
- User grants OAuth access (Gmail/Outlook API)
- **Server** uses OAuth token to read emails
- Server reads original emails (preserves DKIM)
- Server filters and verifies

**Implementation:**

```php
// Server-side Gmail API reading
class GmailApiService
{
    public function readUserEmails(string $accessToken)
    {
        // Use Gmail API (server-side)
        $client = new Google_Client();
        $client->setAccessToken($accessToken);
        
        $gmail = new Google_Service_Gmail($client);
        
        // Get messages
        $messages = $gmail->users_messages->listUsersMessages('me', [
            'q' => 'from:sberbank.ru OR from:tinkoff.ru',
        ]);
        
        foreach ($messages as $message) {
            // Get raw email (format=raw preserves DKIM)
            $rawEmail = $gmail->users_messages->get('me', $message->getId(), [
                'format' => 'raw'
            ]);
            
            // Decode base64
            $email = base64_decode(strtr($rawEmail->getRaw(), '-_', '+/'));
            
            // Verify DKIM (original email)
            verifyDKIM($email);
        }
    }
}
```

**Advantages:**
- ✅ DKIM preserved (original email via API)
- ✅ Works with Gmail/Outlook
- ✅ Pattern filtering possible
- ✅ Server-side security

**Disadvantages:**
- ⚠️ Requires OAuth (but server-side, not client)
- ⚠️ Provider lock-in (Gmail/Outlook only)
- ⚠️ Rate limits

## Recommended Approach: Hybrid Solution

### Phase 1: Forward as Attachment (Quick Win)
- Update `php-verifier.php` to handle `.eml` attachments
- Provide clear user instructions
- Works immediately for users who can forward as attachment

### Phase 2: IMAP Monitoring (Power Users)
- Add IMAP credential storage (encrypted)
- Implement IMAP IDLE monitoring
- Filter by bank domains + payment patterns
- Verify DKIM on original emails

### Phase 3: OAuth API (Gmail/Outlook Users)
- Add OAuth flow (server-side token storage)
- Use Gmail/Outlook API to read emails
- Filter and verify on server

## Security Considerations

### IMAP Credentials Storage
**Note:** Email accounts are stored only on the client side. The server does not store IMAP credentials.

```kotlin
// Client-side: Encrypt credentials before storage (example)
val encrypted = encrypt(password, encryptionKey)

// Store in client-side encrypted storage
emailAccountStorage.save(
    email = email,
    passwordEncrypted = encrypted,
    imapHost = imapHost
)
```

### OAuth Token Storage
```php
// Store OAuth tokens securely
OAuthToken::create([
    'user_id' => $userId,
    'provider' => 'gmail',
    'access_token_encrypted' => encrypt($accessToken),
    'refresh_token_encrypted' => encrypt($refreshToken),
]);
```

## Implementation Priority

1. **Immediate:** Forward as Attachment support
2. **Short-term:** IMAP monitoring
3. **Long-term:** OAuth API integration

## User Experience

### For Forward as Attachment:
```
"To verify your payment, forward the bank email 
as an attachment (not inline). This preserves 
the security signature.

Gmail: Click ⋮ → Forward as attachment
Outlook: Right-click → Forward as attachment"
```

### For IMAP Setup:
```
"Connect your email for automatic verification:
1. Enter your email and password
2. We'll monitor for bank payment emails
3. Only payment emails are processed
4. Your credentials are encrypted"
```

## Conclusion

**Best solution:** IMAP server-side monitoring
- Preserves DKIM (original emails)
- Pattern-based filtering
- Server-side security
- Works with any IMAP provider
- Legal proof maintained

**Fallback:** Forward as Attachment
- Simple for users
- Works immediately
- Good for one-time verifications

