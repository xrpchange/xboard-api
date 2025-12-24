# PHP IMAP DLL Search for PHP 8.4.11 Windows

## Requirements
- **PHP Version:** 8.4.11
- **Thread Safety:** TS (enabled)
- **Architecture:** x64
- **Compiler:** VS17 (Visual Studio 2022)
- **Extension Build:** API20240924,TS,VS17

## Required DLL Version
**Download:** `8.4 Thread Safe (TS) x64`

## Official Sources

### 1. PECL Windows Downloads
- **URL:** https://windows.php.net/downloads/pecl/releases/imap/
- Check for PHP 8.4 version availability

### 2. PECL Package Page
- **URL:** https://pecl.php.net/package/imap
- Latest version: check PHP 8.4 compatibility

### 3. Alternative Sources
- **GitHub:** Search for "php_imap.dll PHP 8.4"
- **SourceForge:** May have pre-built binaries
- **XAMPP Community:** Forums and repositories

## Installation Steps

1. **Download DLL:**
   - Download `php_imap.dll` for **PHP 8.4 Thread Safe (TS) x64**

2. **Copy to extensions directory:**
   ```
   Copy php_imap.dll to C:\xampp\php\ext\
   ```

3. **Enable in php.ini:**
   - Open `C:\xampp\php\php.ini`
   - Find line: `;extension=imap`
   - Uncomment: `extension=imap`

4. **Restart Apache:**
   - Restart Apache service in XAMPP Control Panel

5. **Verify installation:**
   ```bash
   php -m | findstr imap
   ```
   Should output: `imap`

## If DLL Not Found

### Option 1: Use PHP 8.3
If DLL for PHP 8.4 is not available:
1. Download XAMPP with PHP 8.3
2. Use php_imap.dll from PHP 8.3 (may work, but not guaranteed)

### Option 2: Compile from Source
Requires:
- Visual Studio 2022
- PHP SDK
- IMAP extension sources from PECL

### Option 3: Use webklex/php-imap Library
```bash
composer require webklex/php-imap
```
Does not require system IMAP extension.

## Current Status
- ✅ PHP 8.4.11 installed
- ✅ Required version identified: **8.4 Thread Safe (TS) x64**
- ❌ php_imap.dll not found/downloaded yet
- ❌ IMAP extension not installed

