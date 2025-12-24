# PHP IMAP Extension Setup for Windows

## Required Version
For PHP 8.4.11 with Thread Safety enabled and x64 architecture:
**Download: 8.4 Thread Safe (TS) x64**

## Problem
The `imap_open()` function is undefined because the PHP IMAP extension is not installed.

## Solution Options

### Option 1: Download PHP IMAP DLL (Recommended)

1. **Download php_imap.dll for PHP 8.4:**
   - Visit: https://windows.php.net/downloads/pecl/releases/imap/
   - Or use PECL: https://pecl.php.net/package/imap
   - **Note:** PHP 8.4 is very new, and pre-compiled DLLs may not be available yet.

2. **Alternative: Use PHP 8.3 or 8.2:**
   - If PHP 8.4 DLLs are not available, consider downgrading to PHP 8.3 or 8.2
   - Download php_imap.dll for your PHP version and architecture (TS/NTS, x64)

3. **Installation steps:**
   ```
   1. Download php_imap.dll
   2. Copy to C:\xampp\php\ext\
   3. Download required dependencies (libc-client.dll, etc.) if needed
   4. Place dependencies in C:\xampp\php\ or C:\xampp\apache\bin\
   5. Edit C:\xampp\php\php.ini
   6. Find and uncomment: extension=imap
   7. Restart Apache
   ```

### Option 2: Use Alternative IMAP Library (Temporary Solution)

Instead of PHP's built-in IMAP functions, use a library like:
- `webklex/php-imap` (Composer package)
- Or use Yandex Mail API directly (REST API instead of IMAP)

### Option 3: Enable IMAP in php.ini (If DLL exists)

1. Open `C:\xampp\php\php.ini`
2. Search for `extension=imap`
3. Uncomment the line (remove `;`)
4. Restart Apache
5. Verify: `php -m | findstr imap`

## Verification

After installation, verify:
```bash
php -m | findstr imap
```

Should output: `imap`

## Current Status

- PHP Version: 8.4.11
- Extension Dir: C:\xampp\php\ext
- IMAP Status: **NOT INSTALLED**

## Temporary Workaround

Until IMAP extension is installed, email listing functionality will not work.
Consider using Yandex Mail API (REST) as an alternative approach.

