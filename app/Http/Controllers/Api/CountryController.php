<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CountryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $countries = Country::active()
            ->when($request->search, fn($q, $search) => 
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
            )
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $countries]);
    }

    public function show(Country $country): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $country->load('banks')]);
    }

    public function banks(Country $country): JsonResponse
    {
        $banks = $country->banks()->active()->with('country')->get();
        return response()->json(['success' => true, 'data' => $banks]);
    }
} 