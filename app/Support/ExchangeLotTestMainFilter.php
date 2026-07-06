<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Parses is_test / test_group / main_group for exchange-lots list (GET query or POST JSON body).
 */
final class ExchangeLotTestMainFilter
{
    /**
     * @return bool|null null when is_test was not sent
     */
    public static function isTestRequested(Request $request): ?bool
    {
        if (! $request->has('is_test')) {
            return null;
        }

        return filter_var($request->input('is_test'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  Builder<\App\Models\ExchangeLot>  $query
     */
    public static function applyFromRequest(Builder $query, Request $request, bool $sellerWalletHashQuery = false): void
    {
        if ($sellerWalletHashQuery) {
            return;
        }
        $isTest = self::isTestRequested($request);
        if ($isTest === null) {
            return;
        }
        if ($isTest) {
            $testGroupHash = $request->filled('test_group') ? hash('sha256', trim((string) $request->test_group)) : null;
            $query->test($testGroupHash);
        } else {
            $mainGroupHash = $request->filled('main_group') ? hash('sha256', trim((string) $request->main_group)) : null;
            $query->main($mainGroupHash);
        }
    }
}
