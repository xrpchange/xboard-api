<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FiatCurrency;
use App\Models\CryptoCurrency;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *     name="Currencies",
 *     description="API эндпоинты для работы с валютами"
 * )
 */

class CurrencyController extends Controller
{
    /**
     * @OA\Get(
     *     path="/currencies/fiat",
     *     summary="Get fiat currencies list",
     *     tags={"Currencies"},
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search by currency name or code",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Fiat currencies list",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/FiatCurrency"))
     *         )
     *     )
     * )
     */
    public function fiat(Request $request): JsonResponse
    {
        $currencies = FiatCurrency::active()
            ->when($request->search, fn($q, $search) => 
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
            )
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $currencies]);
    }

    public function crypto(Request $request): JsonResponse
    {
        $currencies = CryptoCurrency::active()
            ->when($request->search, fn($q, $search) => 
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
            )
            ->when($request->stablecoins === 'true', fn($q) => $q->stablecoins())
            ->when($request->stablecoins === 'false', fn($q) => $q->where('is_stablecoin', false))
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $currencies]);
    }

    public function all(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'fiat' => FiatCurrency::active()->get(),
                'crypto' => CryptoCurrency::active()->get()
            ]
        ]);
    }
} 