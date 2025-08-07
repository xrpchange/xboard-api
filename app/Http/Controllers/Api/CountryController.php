<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *     name="Countries",
 *     description="API endpoints for countries"
 * )
 */

class CountryController extends Controller
{
    /**
     * @OA\Get(
     *     path="/countries",
     *     summary="Get countries list",
     *     tags={"Countries"},
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search by country name or code",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Countries list",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Country"))
     *         )
     *     )
     * )
     */
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