<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Applies is_training scope for GET /exchange-lots (public search).
 */
final class ExchangeLotTrainingSearchFilter
{
    /**
     * @param  Builder<\App\Models\ExchangeLot>  $query
     * @return bool Whether stale training lot release should run before listing
     */
    public static function applyFromRequest(Builder $query, Request $request): bool
    {
        if ($request->has('is_training')) {
            $trainingOnly = filter_var($request->is_training, FILTER_VALIDATE_BOOL);
            $query->where('is_training', $trainingOnly);

            return $trainingOnly;
        }

        $query->where('is_training', false);

        return false;
    }
}
