<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoreStatusController extends Controller
{
    public function __construct(private CoreClient $coreClient) {}

    /**
     * @OA\Get(
     *     path="/core/health",
     *     summary="Core nodes health status",
     *     tags={"System"},
     *     @OA\Parameter(
     *         name="env",
     *         in="query",
     *         description="Override environment (development|sandbox|production)",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of nodes with health status",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(type="object",
     *                     @OA\Property(property="name", type="string"),
     *                     @OA\Property(property="url", type="string"),
     *                     @OA\Property(property="status", type="integer", nullable=true),
     *                     @OA\Property(property="ok", type="boolean")
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function health(Request $request): JsonResponse
    {
        $env = $request->query('env');
        $statuses = $this->coreClient->healthCheck($env ?: null);

        return response()->json([
            'success' => true,
            'data' => $statuses,
        ]);
    }
}


