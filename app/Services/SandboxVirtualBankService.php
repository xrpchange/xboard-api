<?php

namespace App\Services;

use App\Models\Bank;
use App\Models\Country;
use App\Models\ExchangeLot;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class SandboxVirtualBankService
{
    public static function isSandboxApiHost(?string $host): bool
    {
        $host = strtolower(trim((string) $host));

        return $host !== '' && preg_match('/^(sandbox\.|dev\.)/', $host) === 1;
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
        if ($country === null || strtoupper((string) $country->code) !== strtoupper((string) config('sandbox_virtual_bank.country_code', 'RU'))) {
            return null;
        }

        $ru = Country::query()->whereRaw('UPPER(code) = ?', ['RU'])->first(['id']);
        $countryId = $ru ? (int) $ru->id : null;
        if ($countryId === null) {
            return null;
        }

        return [
            'id' => self::virtualBankId(),
            'country_id' => $countryId,
            'name' => config('sandbox_virtual_bank.name_en'),
            'swift_code' => config('sandbox_virtual_bank.swift_code'),
            'supports_individuals' => true,
            'supports_legal_entities' => true,
            'is_active' => true,
            'allowed_domain' => null,
            'website_host' => config('sandbox_virtual_bank.website_host'),
            'is_sandbox_virtual' => true,
            'supported_fiat_codes' => [],
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    /**
     * @param  Collection<int, Bank>  $mappedRows  Already mapped to arrays
     * @return Collection<int, array<string, mixed>>
     */
    public static function prependVirtualRuForSandbox(Request $request, Country $country, Collection $mappedRows): Collection
    {
        if (! self::isSandboxApiHost($request->getHost())) {
            return $mappedRows;
        }
        $virtual = self::virtualBankArrayForCountry($country);
        if ($virtual === null) {
            return $mappedRows;
        }

        return $mappedRows->prepend($virtual)->values();
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
