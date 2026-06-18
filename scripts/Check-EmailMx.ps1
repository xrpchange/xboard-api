# Check where an email address receives mail (MX records for its domain).
# Usage:
#   .\scripts\Check-EmailMx.ps1 ad@minaevlive.info
#   .\scripts\Check-EmailMx.ps1 user@gmail.com boss@company.ru
#   .\scripts\Check-EmailMx.ps1 -Address "a@x.com,b@y.ru"

param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $Addresses,

    [string[]] $Address = @()
)

$ErrorActionPreference = "Stop"

function Get-DomainFromEmail {
    param([string] $Email)
    $Email = $Email.Trim()
    if ($Email -notmatch '^[^@\s]+@([^@\s]+)$') {
        throw "Not an email address: $Email"
    }
    return $Matches[1].ToLowerInvariant()
}

function Get-MxSummary {
    param([string] $Domain)

    $records = @(Resolve-DnsName -Type MX -Name $Domain -ErrorAction Stop | Sort-Object Preference)
    if ($records.Count -eq 0) {
        return [pscustomobject]@{
            Domain   = $Domain
            Provider = "(no MX records)"
            MxHosts  = ""
        }
    }

    $hosts = ($records | ForEach-Object { "$($_.NameExchange) (prio $($_.Preference))" }) -join "; "

    $first = ($records[0].NameExchange).ToLowerInvariant()
    $provider = switch -Regex ($first) {
        '\.google\.com$|\.googlemail\.com$' { "Google (Gmail / Workspace)" }
        '\.yandex\.(net|ru)$|\.yandex\.net$' { "Yandex" }
        '\.outlook\.com$|\.protection\.outlook\.com$|\.mail\.protection\.outlook\.com$' { "Microsoft (Outlook / 365)" }
        '\.protonmail\.ch$|\.proton\.me$'     { "Proton Mail" }
        '\.mail\.ru$|\.bk\.ru$'               { "Mail.ru" }
        '\.icloud\.com$'                      { "Apple iCloud" }
        '\.zoho\.com$'                        { "Zoho" }
        default                               { "Other / custom ($first)" }
    }

    [pscustomobject]@{
        Domain   = $Domain
        Provider = $provider
        MxHosts  = $hosts
    }
}

$all = @($Addresses) + @($Address) | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }
if ($all.Count -eq 1 -and $all[0] -match ',') {
    $all = $all[0] -split ',' | ForEach-Object { $_.Trim() } | Where-Object { $_ }
}

if ($all.Count -eq 0) {
    Write-Host ""
    Write-Host "Check where email addresses receive mail (MX DNS)." -ForegroundColor Cyan
    Write-Host ""
    Write-Host "Usage:" -ForegroundColor Yellow
    Write-Host "  .\scripts\Check-EmailMx.ps1 ad@minaevlive.info"
    Write-Host "  .\scripts\Check-EmailMx.ps1 user@gmail.com boss@company.ru"
    Write-Host ""
    $typed = Read-Host "Email address (or Enter to exit)"
    if ([string]::IsNullOrWhiteSpace($typed)) { exit 0 }
    $all = @($typed.Trim())
}

Write-Host ""
Write-Host "MX lookup (domain = part after @)" -ForegroundColor Cyan
Write-Host ("-" * 72)

$seenDomains = @{}
foreach ($addr in $all) {
    try {
        $domain = Get-DomainFromEmail -Email $addr
        if ($seenDomains.ContainsKey($domain)) {
            Write-Host ""
            Write-Host "Address: $addr" -ForegroundColor White
            Write-Host "  (same domain as above: $domain)" -ForegroundColor DarkGray
            continue
        }
        $seenDomains[$domain] = $true

        $info = Get-MxSummary -Domain $domain
        Write-Host ""
        Write-Host "Address:  $addr" -ForegroundColor White
        Write-Host "Domain:   $($info.Domain)" -ForegroundColor Gray
        Write-Host "Provider: $($info.Provider)" -ForegroundColor Green
        Write-Host "MX:" -ForegroundColor Gray
        foreach ($part in ($info.MxHosts -split '; ')) {
            Write-Host "  $part"
        }
    } catch {
        Write-Host ""
        Write-Host "Address: $addr" -ForegroundColor White
        Write-Host "  ERROR: $($_.Exception.Message)" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "Done. Provider = who filters inbox/spam for that domain." -ForegroundColor DarkGray
Write-Host ""
