# Email Integration Architecture for Payment Verification

## Problem Analysis

We need to verify bank payment emails with DKIM signatures for dispute resolution, but we want to:
- Check only bank emails matching specific patterns (not all user emails)
- Maintain security and legal proof value for disputes
- Provide good UX without scaring users

## Security Concerns with OAuth Approach

### ❌ Why OAuth to User's Email is NOT Recommended for Payment Verification

1. **Legal Proof Issues**
   - OAuth tokens stored on client can be compromised
   - User can manipulate verification results
   - Hard to prove authenticity in disputes
   - No server-side audit trail

2. **Security Risks**
   - Tokens stored on device can be extracted
   - Client-side verification can be bypassed
   - No guarantee of DKIM signature integrity
   - Risk of token theft or replay attacks

3. **Trust Issues**
   - Third-party (Google/Microsoft) controls access
   - User can revoke access anytime
   - Service dependency on external providers
   - Rate limiting by email providers

## ✅ Recommended Architecture

### Problem: Forwarding Breaks DKIM

**Critical Issue:** Regular email forwarding breaks DKIM signatures because:
- Email client adds new headers (From, To, Subject)
- Email client may modify body
- DKIM validates original headers + body
- Forwarded email = new email = broken DKIM

### Solution 1: Client-Side Email Sharing (RECOMMENDED - Most Secure)

**User shares email as .eml file through app:**
- User selects bank email in their email client
- User shares email as attachment (.eml) to XBoard app
- App receives original email file (DKIM preserved)
- App uploads to server for verification

**Advantages:**
- ✅ Minimal permissions (only shared email)
- ✅ User control (chooses what to share)
- ✅ DKIM preserved (.eml = original email)
- ✅ No server credentials storage
- ✅ Privacy-friendly (GDPR compliant)
- ✅ Simple UX (familiar share flow)

### Solution 2: IMAP Server-Side Monitoring (Alternative - Less Secure)

**How it works:**
- User provides IMAP credentials (one-time, encrypted storage)
- Server monitors user's inbox via IMAP IDLE
- Server reads **original emails** directly (DKIM preserved)
- Server filters: only bank domains + payment patterns
- Server verifies DKIM on original email

**Architecture:**
```
User's Email (Gmail/Outlook/etc) 
    ↓
IMAP Connection (Server-side)
    ↓
Server reads original email (DKIM intact)
    ↓
Pattern filtering (bank + payment patterns)
    ↓
DKIM verification (original email)
    ↓
Payment processing
```

**Advantages:**
- ✅ DKIM always preserved (original email, not forwarded)
- ✅ Real-time processing via IMAP IDLE
- ✅ Pattern-based filtering (only payment emails)
- ✅ Server-side security (credentials encrypted)
- ✅ Works with any IMAP provider (Gmail, Outlook, custom)
- ✅ Legal proof maintained (server-side audit trail)

### Enhanced Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    User's Email Client                      │
│  (Gmail, Outlook, etc. - any provider)                      │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       │ 1. User forwards bank email
                       │    (with DKIM signature preserved)
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│              check@xdexboard.com (Postfix)                  │
│  - Receives forwarded email                                  │
│  - Preserves DKIM signature                                  │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       │ 2. Pipe to PHP verifier
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│           php-verifier.php (Server-side)                     │
│  - Verifies DKIM signature (cryptographic)                   │
│  - Checks sender domain (bank whitelist)                     │
│  - Extracts payment details (pattern matching)              │
│  - Stores proof on server                                    │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       │ 3. API call to update transaction
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│              Laravel API (xboard-api)                        │
│  - Updates transaction status                                │
│  - Stores email proof (encrypted)                           │
│  - Links verification to transaction                         │
└─────────────────────────────────────────────────────────────┘
```

## Bank domain whitelist (DKIM / sender verification)

The API verifies that the email sender's domain belongs to an allowed bank. The whitelist is stored in the database:

- **Table:** `banks`
- **Field:** `allowed_domain` (nullable string) — second-level domain, e.g. `sberbank.ru`, `tinkoff.ru`. Filled via `BankSeeder`.
- Only banks with non-empty `allowed_domain` are used for the whitelist (`Bank::withAllowedDomain()`).

**Verification response:**
- **Success (sender in whitelist):** `data.sender_known = true`, `data.sender_bank_name` = bank name (e.g. "Sberbank of Russia"). Client can show: "Letter from bank X" (green).
- **Failure (sender not in whitelist):** HTTP 422 with `sender_known = false`, `sender_domain` = extracted domain. Client can show: "Letter from unknown sender" (red).

## Implementation Plan

### Phase 1: Pattern-Based Email Filtering

Add pattern matching to `php-verifier.php` to check only bank payment emails:

```php
// Payment email patterns (subject/body matching)
const PAYMENT_PATTERNS = [
    'sberbank' => [
        'subject' => '/оплата|платеж|перевод|payment/i',
        'body' => '/сумма.*?(\d+[.,]\d+)|amount.*?(\d+[.,]\d+)/i',
    ],
    'tinkoff' => [
        'subject' => '/оплата|платеж|перевод/i',
        'body' => '/сумма.*?(\d+[.,]\d+)/i',
    ],
    // Add more bank patterns
];

function isPaymentEmail(string $rawEmail, string $bankDomain): bool {
    // Extract subject and body
    // Match against patterns for this bank
    // Return true only if matches payment pattern
}
```

### Phase 2: API Integration

Create API endpoint to link email verification with transactions:

**New Endpoint:** `POST /api/v1/transactions/{id}/verify-email`

```php
// app/Http/Controllers/Api/TransactionController.php

public function verifyEmail(Request $request, Transaction $transaction)
{
    // Called by php-verifier.php after successful DKIM check
    // Links email verification to transaction
    // Stores proof (encrypted email, DKIM signature, metadata)
}
```

### Phase 3: Email Proof Storage

Add table to store email verification proofs:

```php
// Migration: create_email_verifications_table.php
Schema::create('email_verifications', function (Blueprint $table) {
    $table->id();
    $table->foreignId('transaction_id')->constrained();
    $table->string('email_from'); // Bank email
    $table->string('dkim_domain'); // Verified domain
    $table->text('dkim_signature'); // Full signature
    $table->text('email_hash'); // SHA256 of original email
    $table->text('email_encrypted'); // Encrypted original email
    $table->json('extracted_data'); // Payment amount, date, etc.
    $table->enum('status', ['verified', 'failed', 'pending']);
    $table->timestamp('verified_at');
    $table->timestamps();
    
    $table->index('transaction_id');
    $table->index('email_from');
});
```

### Phase 4: Enhanced PHP Verifier

Update `php-verifier.php` to:

1. **Pattern Matching** - Check if email matches payment patterns
2. **Data Extraction** - Extract payment details (amount, date, reference)
3. **API Integration** - Call Laravel API to update transaction
4. **Proof Storage** - Store encrypted email and DKIM signature

```php
// After successful DKIM verification
if (isPaymentEmail($rawEmail, $dkimCheck['domain'])) {
    $paymentData = extractPaymentData($rawEmail, $dkimCheck['domain']);
    
    // Call API to update transaction
    $apiResponse = callTransactionVerificationAPI([
        'transaction_id' => $paymentData['transaction_id'],
        'email_from' => $senderCheck['from'],
        'dkim_domain' => $dkimCheck['domain'],
        'dkim_signature' => $dkimCheck['signature'],
        'email_hash' => hash('sha256', $rawEmail),
        'extracted_data' => $paymentData,
    ]);
    
    // Store proof (encrypted)
    storeEmailProof($rawEmail, $dkimCheck, $paymentData);
}
```

## Alternative: IMAP Monitoring (Optional Enhancement)

For better UX, add optional IMAP monitoring on server:

### Server-Side IMAP Monitoring

Instead of OAuth on client, set up IMAP monitoring on server:

1. **User provides IMAP credentials** (one-time setup)
2. **Server monitors inbox** via IMAP IDLE
3. **Server filters** - only processes bank emails matching patterns
4. **Server verifies DKIM** - same secure process

**Advantages:**
- ✅ Server-side security
- ✅ Pattern-based filtering
- ✅ No OAuth complexity
- ✅ Works with any IMAP provider
- ✅ Legal proof maintained

**Note:** We do **not** implement server-side IMAP. Email is read **only on the client** (app); the API only accepts raw email (e.g. base64 .eml) from the client and verifies DKIM. No server connects to user mailboxes.

## Security Best Practices

### 1. Email Storage
- **Encrypt** original emails before storage
- **Hash** emails for integrity verification
- **Store DKIM signatures** separately for proof
- **Retention policy** - keep for dispute resolution period

### 2. API Security
- **Bearer token** authentication for php-verifier → API calls
- **Rate limiting** on verification endpoints
- **IP whitelist** for php-verifier (only from mail server)
- **Audit logging** of all verifications

### 3. Pattern Matching Security
- **Whitelist approach** - only known bank patterns
- **Regex validation** - prevent injection attacks
- **Pattern versioning** - update patterns as banks change formats
- **False positive handling** - manual review queue

## UX Improvements

### 1. Clear Instructions
- Show users exactly how to forward emails
- Provide examples of correct forwarding
- Explain why DKIM must be preserved

### 2. Status Feedback
- Real-time status updates via API
- Email notifications on verification
- Transaction status updates in app

### 3. Dispute Resolution
- Easy access to verification proofs
- Downloadable proof packages
- Clear audit trail

## Comparison: OAuth vs Server-Side

| Aspect | OAuth (Client) | Server-Side (Recommended) |
|--------|----------------|---------------------------|
| **Security** | ⚠️ Tokens on client | ✅ Server-side only |
| **Legal Proof** | ❌ Weak | ✅ Strong (cryptographic) |
| **DKIM Integrity** | ⚠️ Client can modify | ✅ Server verifies |
| **User Trust** | ⚠️ Requires OAuth consent | ✅ Simple forwarding |
| **Provider Lock-in** | ❌ Google/Microsoft only | ✅ Any email provider |
| **Dispute Resolution** | ❌ Hard to prove | ✅ Full audit trail |
| **Pattern Filtering** | ⚠️ Client-side filtering | ✅ Server-side filtering |

## Recommendation

**Use Client-Side Email Sharing** (RECOMMENDED - Most Secure):

1. ✅ User shares email as `.eml` file through app
2. ✅ App uploads original email (DKIM preserved) to API
3. ✅ Server verifies DKIM on original email
4. ✅ Server checks bank domain and payment patterns
5. ✅ Server stores proof and updates transaction

**Why this is better:**
- ✅ Minimal permissions (only shared email)
- ✅ User control (chooses what to share)
- ✅ DKIM preserved (.eml = original email)
- ✅ No server credentials storage
- ✅ Privacy-friendly (GDPR compliant)
- ✅ Simple UX (familiar share flow)

**Alternative (Less Secure):**
- IMAP server-side monitoring (requires full inbox access)
- Use only if users explicitly request automatic verification
- Always encrypt credentials
- Provide clear privacy policy

## Next Steps

1. Implement pattern matching in `php-verifier.php`
2. Create API endpoint for email verification
3. Add email proof storage
4. Update documentation
5. Test with real bank emails

