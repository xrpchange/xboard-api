# User Guide: How to Verify Payment Email

## Quick Start

When you need to verify a payment, you have **5 options**. Choose the one that works best for you:

1. **Automatic (OAuth)** - Fastest, works with Gmail/Outlook
2. **Upload .eml File** - Works if your email client supports it
3. **Forward as attachment → upload** - Use your client’s “forward as attachment”, then upload the file in XBoard (preserves MIME when done correctly)
4. **Copy-Paste** - Always works, just copy email content
5. **Client Copy** - OAuth to copy email content

---

## Method 1: Automatic (OAuth) - Recommended

**Works with:** Gmail, Outlook

**Steps:**
1. Open transaction in XBoard app
2. Click "Verify Payment"
3. Select "Automatic (OAuth)"
4. Grant access to read emails (one-time)
5. Select the bank email
6. Done! ✅

**Why it's best:**
- ✅ Fully automated
- ✅ Preserves DKIM signature
- ✅ Secure

---

## Method 2: Upload .eml File

**Works with:** Thunderbird, Outlook Desktop, Apple Mail

**Steps:**

### Thunderbird
1. Right-click email → "Save As"
2. Save as `.eml` file
3. In XBoard app: Upload `.eml` file
4. Done! ✅

### Outlook Desktop
1. Select email
2. File → Save As
3. Choose "Email Message Format (.eml)"
4. Save file
5. In XBoard app: Upload `.eml` file
6. Done! ✅

### Apple Mail
1. Select email
2. File → Save As
3. Choose "Raw Message Source"
4. Save as `.eml` file
5. In XBoard app: Upload `.eml` file
6. Done! ✅

**Why it's good:**
- ✅ Preserves DKIM signature
- ✅ Works offline
- ✅ No OAuth needed

---

## Method 3: Forward as attachment, then upload

**Works with:** Any email client that can forward as attachment or export MIME

**Goal:** Get the **original MIME** (or an `.eml`) into the XBoard app so it can call the server’s verification API with unchanged headers/body.

**Steps (generic):**
1. In your mail client, use **“Forward as attachment”** (not a plain inline forward) if available.
2. Either:
   - **Save** the resulting attachment as `.eml` and use **Upload** in XBoard (same as Method 2), or  
   - **Share** the attachment / message into XBoard if your build supports a share target.
3. Submit verification in the app.

**⚠️ Important:** A normal inline forward often **breaks DKIM**. Prefer attachment/export flows that keep the bank MIME intact.

**Why it works:**
- ✅ Can preserve DKIM when the raw message is preserved
- ✅ No OAuth needed
- ✅ Works with many desktop clients

---

## Method 4: Copy-Paste (Always Works)

**Works with:** Any email client, any device

**Steps:**
1. Open email from bank
2. Copy email content:
   - Copy subject line
   - Copy email body (all text)
   - Note sender email address (e.g., `noreply@sberbank.ru`)
3. In XBoard app:
   - Open transaction
   - Click "Verify Payment"
   - Select "Copy-Paste Method"
   - Paste email content
   - Enter sender email address
   - Submit
4. Done! ✅

**Example of what to copy:**
```
Subject: Оплата получена

Здравствуйте!

Поступил платеж на сумму 1,000.00 RUB
Дата: 22.12.2025
Номер операции: ABC123456

С уважением,
Сбербанк
```

**Why it works:**
- ✅ Always works (any email client)
- ✅ No OAuth needed
- ✅ No .eml file needed
- ⚠️ No DKIM check (but seller has templates to verify)

---

## Method 5: Client Copy (OAuth to Copy)

**Works with:** Gmail, Outlook

**Steps:**
1. Open transaction in XBoard app
2. Click "Verify Payment"
3. Select "Client Copy"
4. Grant OAuth access (one-time)
5. Select the bank email
6. App copies email content automatically
7. Done! ✅

**Why it works:**
- ✅ Semi-automated
- ✅ User controls which email
- ⚠️ No DKIM check (but seller has templates)

---

## Which Method Should I Use?

### If you use Gmail or Outlook:
→ **Method 1 (OAuth)** - Fastest and most secure

### If you use Thunderbird/Outlook Desktop/Apple Mail:
→ **Method 2 (.eml Upload)** - Preserves DKIM

### If you can forward as attachment but not OAuth:
→ **Method 3** - Then upload/share into XBoard

### If nothing else works:
→ **Method 4 (Copy-Paste)** - Always works!

---

## Troubleshooting

### "DKIM verification failed"

**Solution:**
- Try Method 1 (OAuth) - preserves DKIM
- Try Method 2 (.eml Upload) - preserves DKIM
- Try Method 3 with **attachment/export**, not a broken inline forward
- Use Method 4 (Copy-Paste) - no DKIM needed

### "Cannot export .eml file"

**Solution:**
- Use Method 1 (OAuth) if Gmail/Outlook
- Use Method 3 (attachment → upload) if your client supports it
- Use Method 4 (Copy-Paste)

### "OAuth not available"

**Solution:**
- Use Method 2 (.eml Upload) if supported
- Use Method 3 (attachment → upload)
- Use Method 4 (Copy-Paste)

---

## Security Notes

**Methods with DKIM verification (when MIME is intact):**
- Method 1 (OAuth)
- Method 2 (.eml Upload)
- Method 3 (attachment → upload, if MIME preserved)

**Methods without DKIM:**
- Method 4 (Copy-Paste)
- Method 5 (Client Copy)

**For methods without DKIM:**
- Seller receives full email templates
- Seller can verify authenticity manually
- Acceptable for P2P transactions

---

## Need Help?

If you're having trouble verifying your payment email:
1. Try Method 4 (Copy-Paste) - it always works
2. Contact support with your transaction ID
3. We'll help you verify manually

---

## Summary

✅ **5 methods available** - choose what works for you
✅ **All methods are acceptable** - seller has templates to verify
✅ **Copy-Paste always works** - no special requirements
✅ **OAuth is fastest** - if you use Gmail/Outlook

**Just pick the method that works with your email client!**
