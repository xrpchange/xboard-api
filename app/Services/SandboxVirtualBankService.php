<?php

namespace App\Services;

use App\Models\Bank;
use App\Models\Country;
use App\Models\ExchangeLot;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final class SandboxVirtualBankService
{
    public static function isSandboxApiHost(?string $host): bool
    {
        $host = strtolower(trim((string) $host));
        if ($host === '') {
            return false;
        }
        if (preg_match('/^(sandbox\.|dev\.)/', $host) === 1) {
            return true;
        }
        $extras = config('sandbox_virtual_bank.extra_prepend_hosts', []);

        return is_array($extras) && in_array($host, $extras, true);
    }

    public static function virtualBankId(): int
    {
        return (int) config('sandbox_virtual_bank.virtual_bank_id', 900000001);
    }

    public static function bankCode(): string
    {
        return (string) config('sandbox_virtual_bank.code', 'sandbox_ru_receipt');
    }

    public static function isVirtualBankId(int $id): bool
    {
        return $id === self::virtualBankId();
    }

    public static function virtualSwiftNormalized(): string
    {
        return strtoupper(trim((string) config('sandbox_virtual_bank.swift_code')));
    }

    /** Uppercase `countries.code` for the synthetic receipt bank (from `SANDBOX_VIRTUAL_BANK_COUNTRY_CODE`). */
    public static function receiptCountryCode(): string
    {
        return strtoupper(trim((string) config('sandbox_virtual_bank.country_code')));
    }

    /** True when [swift] equals configured sandbox virtual bank SWIFT (no DB row). */
    public static function matchesVirtualSwift(?string $swift): bool
    {
        $swift = strtoupper(trim((string) $swift));
        $expected = self::virtualSwiftNormalized();

        return $expected !== '' && $swift !== '' && $swift === $expected;
    }

    public static function mapBankModelToArray(Bank $bank): array
    {
        $arr = $bank->toArray();
        $arr['supported_fiat_codes'] = $bank->relationLoaded('fiatCurrencies')
            ? $bank->fiatCurrencies->pluck('code')->values()->all()
            : [];

        return $arr;
    }

    public static function virtualBankArrayForCountry(?Country $country): ?array
    {
        $expected = self::receiptCountryCode();
        if ($country === null || strtoupper(trim((string) $country->code)) !== $expected) {
            return null;
        }

        return [
            'id' => self::virtualBankId(),
            'country_id' => (int) $country->id,
            'country_code' => $expected,
            'country' => ['code' => $expected],
            'name' => config('sandbox_virtual_bank.name_en'),
            'swift_code' => config('sandbox_virtual_bank.swift_code'),
            'supports_individuals' => true,
            'supports_legal_entities' => true,
            'is_active' => true,
            'allowed_domain' => null,
            'website_host' => config('sandbox_virtual_bank.website_host'),
            'is_sandbox_virtual' => true,
            'supported_fiat_codes' => array_values(array_map(
                static fn ($c): string => strtoupper(trim((string) $c)),
                config('sandbox_virtual_bank.supported_fiat_codes', [])
            )),
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    /**
     * @param  Collection<int, Bank>  $mappedRows  Already mapped to arrays
     * @return Collection<int, array<string, mixed>>
     */
    public static function appendVirtualReceiptBankForSandbox(Request $request, Country $country, Collection $mappedRows): Collection
    {
        $host = $request->getHost();
        if (! self::isSandboxApiHost($host)) {
            Log::info('RefData: sandbox_virtual_bank skipped (byCountry)', [
                'reason' => 'host_not_sandbox_or_dev_subdomain',
                'host' => $host,
                'country_code' => $country->code,
                'hint' => 'HTTP Host must match ^(sandbox.|dev.) e.g. sandbox.api.example.com',
            ]);

            return $mappedRows;
        }
        $virtual = self::virtualBankArrayForCountry($country);
        if ($virtual === null) {
            Log::info('RefData: sandbox_virtual_bank skipped (byCountry)', [
                'reason' => 'virtual_only_for_configured_country',
                'host' => $host,
                'country_code' => $country->code,
                'expected_country' => self::receiptCountryCode(),
            ]);

            return $mappedRows;
        }

        Log::info('RefData: sandbox_virtual_bank appended (byCountry)', [
            'host' => $host,
            'country_code' => $country->code,
            'virtual_bank_id' => self::virtualBankId(),
            'swift' => config('sandbox_virtual_bank.swift_code'),
        ]);

        return $mappedRows->push($virtual)->values();
    }

    public static function lotUsesVirtualSandboxBank(ExchangeLot $lot): bool
    {
        return $lot->virtual_sandbox_bank_code === self::bankCode();
    }

    public static function primaryPaymentVerificationEmail(): ?string
    {
        $raw = config('mail.verification_email');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $parts = preg_split('/\s*[,;]\s*/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) && $parts !== [] ? strtolower(trim($parts[0])) : null;
    }

    public static function mailFromDomain(): string
    {
        $addr = (string) config('mail.from.address', 'noreply@xdexboard.com');
        $at = strrchr($addr, '@');

        return $at ? strtolower(substr($at, 1)) : '';
    }

    public static function senderDomainAllowedForVirtualBank(string $senderDomain): bool
    {
        $senderDomain = strtolower(trim($senderDomain));
        $allowed = self::mailFromDomain();
        if ($allowed === '' || $senderDomain === '') {
            return false;
        }

        return $senderDomain === $allowed || str_ends_with($senderDomain, '.' . $allowed);
    }

    public static function rawEmailHasSandboxReceiptHeader(string $rawEmail): bool
    {
        $name = (string) config('sandbox_virtual_bank.receipt_mime_header_name');
        $val = (string) config('sandbox_virtual_bank.receipt_mime_header_value');

        return $name !== '' && $val !== ''
            && preg_match('/^' . preg_quote($name, '/') . '\s*:\s*' . preg_quote($val, '/') . '\s*$/mi', $rawEmail) === 1;
    }
}
