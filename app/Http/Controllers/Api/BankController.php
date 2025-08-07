<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BankController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $banks = Bank::active()
            ->with('country')
            ->when($request->country_id, fn($q, $id) => $q->where('country_id', $id))
            ->when($request->for_individuals === 'true', fn($q) => $q->forIndividuals())
            ->when($request->for_legal_entities === 'true', fn($q) => $q->forLegalEntities())
            ->when($request->search, fn($q, $search) => 
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('swift_code', 'like', "%{$search}%")
            )
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $banks]);
    }

    public function show(Bank $bank): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $bank->load('country')]);
    }

    public function byCountry(Country $country, Request $request): JsonResponse
    {
        $banks = $country->banks()
            ->active()
            ->when($request->for_individuals === 'true', fn($q) => $q->forIndividuals())
            ->when($request->for_legal_entities === 'true', fn($q) => $q->forLegalEntities())
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $banks]);
    }
} 