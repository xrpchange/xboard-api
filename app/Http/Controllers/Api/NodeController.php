<?php

namespace App\Http\Controllers\Api;

use App\Models\Node;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NodeController extends BaseApiController
{
    /**
     * @OA\Get(
     *     path="/nodes",
     *     summary="Get all active nodes",
     *     tags={"Nodes"},
     *     @OA\Parameter(
     *         name="country",
     *         in="query",
     *         required=false,
     *         description="Filter nodes by country code (ISO 3166-1 alpha-3, e.g., 'USA', 'RUS', 'GBR')",
     *         @OA\Schema(type="string", example="USA")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of active nodes",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="name", type="string"),
     *                     @OA\Property(property="domain", type="string"),
     *                     @OA\Property(property="type", type="string", enum={"xboard", "core"}),
     *                     @OA\Property(property="environment", type="string", enum={"production", "sandbox", "development"}),
     *                     @OA\Property(property="api_url", type="string"),
     *                     @OA\Property(property="country_code", type="string"),
     *                     @OA\Property(property="is_default", type="boolean"),
     *                     @OA\Property(property="is_required", type="boolean"),
     *                     @OA\Property(property="priority", type="integer"),
     *                     @OA\Property(property="description", type="string"),
     *                     @OA\Property(property="supported_currencies", type="array", @OA\Items(type="string")),
     *                     @OA\Property(property="features", type="array", @OA\Items(type="string")),
     *                     @OA\Property(property="health_status", type="string")
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $countryCode = $request->query('country');
            
            if ($countryCode) {
                $nodes = Node::getActiveByCountry($countryCode);
            } else {
                $nodes = Node::getActive();
            }

            // Возвращаем все endpoints без фильтрации
            // Клиент сам решит какой endpoint использовать на основе своих настроек
            // TODO: Remove default_environment in future app versions
            // This is a temporary solution for first launch with testers - should be removed later
            // Добавляем default_environment для первого запуска приложения
            // Если данных нет в приложении - работаем в production (настройки по умолчанию)
            // Но первичный запрос от сервера возвращает sandbox для тестировщиков
            // Если сервер возвращает sandbox в default_environment - в приложении автоматически включается Developer Mode
            // default_environment читается из конфигурации
            $defaultEnvironment = config('app.default_environment', 'production');
            
            return $this->successResponse($nodes, null, 200, [
                'default_environment' => $defaultEnvironment // TODO: Remove in future versions
            ]);
        } catch (\Throwable $e) {
            \Log::error('Failed to load nodes', [
                'exception' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            return $this->errorResponse(
                'Failed to load nodes',
                500,
                [
                    'error_code' => $e->getCode(),
                    'error_message' => $e->getMessage(),
                ]
            );
        }
    }

    /**
     * @OA\Get(
     *     path="/nodes/required",
     *     summary="Get required nodes (always connected)",
     *     tags={"Nodes"},
     *     @OA\Response(
     *         response=200,
     *         description="List of required nodes",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Node"))
     *         )
     *     )
     * )
     */
    public function required(Request $request): JsonResponse
    {
        $nodes = Node::getRequired();
        
        // Определяем роль пользователя
        $userRole = $request->header('X-User-Role', 'public');
        
        // Фильтруем endpoints по правам доступа
        $filteredNodes = $nodes->map(function ($node) use ($userRole) {
            $nodeArray = $node->toArray();
            $nodeArray['endpoints'] = $node->getAccessibleEndpoints($userRole);
            return $nodeArray;
        });
        
        return $this->successResponse($filteredNodes);
    }


    /**
     * @OA\Get(
     *     path="/nodes/{id}",
     *     summary="Get node by ID",
     *     tags={"Nodes"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Node ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Node details",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Node")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Node not found")
     * )
     */
    public function show(int $id): JsonResponse
    {
        $node = Node::find($id);
        
        if (!$node) {
            return $this->errorResponse('Node not found', 404);
        }
        
        return $this->successResponse($node);
    }

    /**
     * @OA\Post(
     *     path="/nodes/{id}/health-check",
     *     summary="Check node health",
     *     tags={"Nodes"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Node ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Health check result",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="node_id", type="integer"),
     *                 @OA\Property(property="status", type="string"),
     *                 @OA\Property(property="checked_at", type="string", format="date-time")
     *             )
     *         )
     *     )
     * )
     */
    public function healthCheck(int $id): JsonResponse
    {
        $node = Node::find($id);
        
        if (!$node) {
            return $this->errorResponse('Node not found', 404);
        }
        
        $isOnline = $node->checkHealth();
        
        return $this->successResponse([
            'node_id' => $node->id,
            'status' => $node->health_status,
            'checked_at' => $node->last_health_check,
            'is_online' => $isOnline
        ]);
    }
}

