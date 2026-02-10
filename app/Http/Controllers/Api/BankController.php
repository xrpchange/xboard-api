<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *     name="Banks",
 *     description="API endpoints for working with banks"
 * )
 */

class BankController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $banks = Bank::active()
            ->with(['country', 'fiatCurrencies'])
            ->when($request->country_id, fn($q, $id) => $q->where('country_id', $id))
            ->when($request->for_individuals === 'true', fn($q) => $q->forIndividuals())
            ->when($request->for_legal_entities === 'true', fn($q) => $q->forLegalEntities())
            ->when($request->search, fn($q, $search) => 
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('swift_code', 'like', "%{$search}%")
            )
            ->orderBy('name')
            ->get();

        $data = $banks->map(function (Bank $bank) {
            $arr = $bank->toArray();
            $arr['supported_fiat_codes'] = $bank->fiatCurrencies->pluck('code')->values()->all();
            return $arr;
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function show(Bank $bank): JsonResponse
    {
        $bank->load(['country', 'fiatCurrencies']);
        $arr = $bank->toArray();
        $arr['supported_fiat_codes'] = $bank->fiatCurrencies->pluck('code')->values()->all();
        return response()->json(['success' => true, 'data' => $arr]);
    }

    public function byCountry(Country $country, Request $request): JsonResponse
    {
        $banks = $country->banks()
            ->active()
            ->with('fiatCurrencies')
            ->when($request->for_individuals === 'true', fn($q) => $q->forIndividuals())
            ->when($request->for_legal_entities === 'true', fn($q) => $q->forLegalEntities())
            ->orderBy('name')
            ->get();

        $data = $banks->map(function (Bank $bank) {
            $arr = $bank->toArray();
            $arr['supported_fiat_codes'] = $bank->fiatCurrencies->pluck('code')->values()->all();
            return $arr;
        });

        return response()->json(['success' => true, 'data' => $data]);
    }
} 