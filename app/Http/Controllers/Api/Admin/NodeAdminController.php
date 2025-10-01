<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Node;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

/**
 * Admin endpoints для управления узлами
 * Требуют роль 'admin'
 */
class NodeAdminController extends BaseApiController
{
    /**
     * @OA\Post(
     *     path="/admin/nodes",
     *     summary="Create a new node (Admin only)",
     *     tags={"Admin - Nodes"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "domain", "api_url"},
     *             @OA\Property(property="name", type="string", example="AnotherExchange"),
     *             @OA\Property(property="domain", type="string", example="anotherexchange.com"),
     *             @OA\Property(property="api_url", type="string", example="https://api.anotherexchange.com"),
     *             @OA\Property(property="country_code", type="string", example="USA"),
     *             @OA\Property(property="owner", type="string", example="Another Exchange Inc."),
     *             @OA\Property(property="is_default", type="boolean", example=false),
     *             @OA\Property(property="is_required", type="boolean", example=false),
     *             @OA\Property(property="priority", type="integer", example=50),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="endpoints", type="object"),
     *             @OA\Property(property="endpoint_permissions", type="object"),
     *             @OA\Property(property="features", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Node created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Node")
     *         )
     *     ),
     *     @OA\Response(response=403, description="Forbidden - Admin access required")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'domain' => 'required|string|max:255|unique:nodes',
            'api_url' => 'required|url|max:255',
            'country_code' => 'nullable|string|size:3',
            'owner' => 'nullable|string|max:255',
            'is_default' => 'boolean',
            'is_required' => 'boolean',
            'priority' => 'integer',
            'description' => 'nullable|string',
            'endpoints' => 'nullable|array',
            'endpoint_permissions' => 'nullable|array',
            'features' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $node = Node::create($request->all());

        return $this->successResponse($node, 201);
    }

    /**
     * @OA\Put(
     *     path="/admin/nodes/{id}",
     *     summary="Update a node (Admin only)",
     *     tags={"Admin - Nodes"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Node ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="api_url", type="string"),
     *             @OA\Property(property="is_active", type="boolean"),
     *             @OA\Property(property="priority", type="integer"),
     *             @OA\Property(property="endpoints", type="object"),
     *             @OA\Property(property="endpoint_permissions", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Node updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Node")
     *         )
     *     ),
     *     @OA\Response(response=403, description="Forbidden - Admin access required"),
     *     @OA\Response(response=404, description="Node not found")
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $node = Node::find($id);

        if (!$node) {
            return $this->errorResponse('Node not found', 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'string|max:100',
            'domain' => 'string|max:255|unique:nodes,domain,' . $id,
            'api_url' => 'url|max:255',
            'country_code' => 'nullable|string|size:3',
            'owner' => 'nullable|string|max:255',
            'is_default' => 'boolean',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'description' => 'nullable|string',
            'endpoints' => 'nullable|array',
            'endpoint_permissions' => 'nullable|array',
            'features' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $node->update($request->all());

        return $this->successResponse($node);
    }

    /**
     * @OA\Delete(
     *     path="/admin/nodes/{id}",
     *     summary="Delete a node (Admin only)",
     *     tags={"Admin - Nodes"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Node ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Node deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Node deleted successfully")
     *         )
     *     ),
     *     @OA\Response(response=403, description="Forbidden - Admin access required"),
     *     @OA\Response(response=404, description="Node not found")
     * )
     */
    public function destroy(int $id): JsonResponse
    {
        $node = Node::find($id);

        if (!$node) {
            return $this->errorResponse('Node not found', 404);
        }

        // Проверяем, что это не обязательный узел
        if ($node->is_required) {
            return $this->errorResponse('Cannot delete required node', 403);
        }

        $node->delete();

        return $this->successResponse(['message' => 'Node deleted successfully']);
    }

    /**
     * @OA\Put(
     *     path="/admin/nodes/{id}/toggle",
     *     summary="Toggle node active status (Admin only)",
     *     tags={"Admin - Nodes"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Node ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Node status toggled",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Node")
     *         )
     *     )
     * )
     */
    public function toggle(int $id): JsonResponse
    {
        $node = Node::find($id);

        if (!$node) {
            return $this->errorResponse('Node not found', 404);
        }

        $node->is_active = !$node->is_active;
        $node->save();

        return $this->successResponse($node);
    }

    /**
     * @OA\Post(
     *     path="/admin/nodes/{id}/set-default",
     *     summary="Set node as default (Admin only)",
     *     tags={"Admin - Nodes"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Node ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Default node updated",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Node")
     *         )
     *     )
     * )
     */
    public function setDefault(int $id): JsonResponse
    {
        $node = Node::find($id);

        if (!$node) {
            return $this->errorResponse('Node not found', 404);
        }

        // Убираем флаг default со всех узлов
        Node::where('is_default', true)->update(['is_default' => false]);

        // Устанавливаем новый default
        $node->is_default = true;
        $node->save();

        return $this->successResponse($node);
    }

    /**
     * @OA\Put(
     *     path="/admin/nodes/{id}/endpoints",
     *     summary="Update node endpoints (Admin only)",
     *     tags={"Admin - Nodes"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Node ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="endpoints", type="object", example={
     *                 "production_api": "https://api.example.com",
     *                 "sandbox_api": "https://sandbox.api.example.com",
     *                 "dev_api": "https://dev.api.example.com"
     *             }),
     *             @OA\Property(property="endpoint_permissions", type="object", example={
     *                 "production_api": "public",
     *                 "sandbox_api": "public",
     *                 "dev_api": "developer"
     *             })
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Endpoints updated successfully"
     *     )
     * )
     */
    public function updateEndpoints(Request $request, int $id): JsonResponse
    {
        $node = Node::find($id);

        if (!$node) {
            return $this->errorResponse('Node not found', 404);
        }

        $validator = Validator::make($request->all(), [
            'endpoints' => 'required|array',
            'endpoint_permissions' => 'required|array'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $node->endpoints = $request->endpoints;
        $node->endpoint_permissions = $request->endpoint_permissions;
        $node->save();

        return $this->successResponse($node);
    }
}






