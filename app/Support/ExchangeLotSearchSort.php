<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Parses sort and page size for GET /exchange-lots (public search).
 */
final class ExchangeLotSearchSort
{
    public const SORT_UPDATED_AT = 'updated_at';

    public const SORT_CREATED_AT = 'created_at';

    public const SORT_COMPLETED_DEALS_COUNT = 'completed_deals_count';

    public const DIR_ASC = 'asc';

    public const DIR_DESC = 'desc';

    /**
     * @return array{0:string,1:string} [column, direction]
     */
    public static function resolveFromRequest(Request $request): array
    {
        $sortBy = strtolower(trim((string) $request->query('sort_by', self::SORT_UPDATED_AT)));
        $sortDir = strtolower(trim((string) $request->query('sort_dir', self::DIR_DESC)));

        if (! in_array($sortBy, self::allowedColumns(), true)) {
            $sortBy = self::SORT_UPDATED_AT;
        }
        if (! in_array($sortDir, [self::DIR_ASC, self::DIR_DESC], true)) {
            $sortDir = self::DIR_DESC;
        }

        return [$sortBy, $sortDir];
    }

    /**
     * @return list<string>
     */
    public static function allowedColumns(): array
    {
        return [
            self::SORT_UPDATED_AT,
            self::SORT_CREATED_AT,
            self::SORT_COMPLETED_DEALS_COUNT,
        ];
    }

    public static function perPageFromRequest(Request $request): int
    {
        $default = max(1, (int) config('lot.search_page_size', 50));
        $max = max($default, (int) config('lot.search_page_size_max', 100));
        $requested = $request->query('per_page');
        if ($requested === null || $requested === '') {
            return $default;
        }
        $perPage = (int) $requested;

        return min(max(1, $perPage), $max);
    }
}
