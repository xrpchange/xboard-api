<?php

namespace App\Http\Controllers\Api;

use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * @OA\Tag(
 *     name="Countries",
 *     description="API endpoints for countries"
 * )
 */

class CountryController extends BaseApiController
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
        Log::info('RefData: GET /countries called', [
            'host' => $request->getHost(),
            'url' => $request->fullUrl(),
        ]);

        $countries = Country::active()
            ->when($request->search, fn($q, $search) => 
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
            )
            ->orderBy('name')
            ->get();

        Log::info('RefData: GET /countries response', ['count' => $countries->count()]);

        return $this->successResponse($countries);
    }

    public function show(Country $country): JsonResponse
    {
        return $this->successResponse($country->load('banks'));
    }

    public function banks(Country $country): JsonResponse
    {
        $banks = $country->banks()->active()->with('country')->get();
        return $this->successResponse($banks);
    }
} 