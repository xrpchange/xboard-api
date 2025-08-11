<?php

namespace App\Http\Controllers\Api;

/**
 * @OA\Info(
 *     title="XBoard API",
 *     version="1.0.0",
 *     description="API for P2P cryptocurrency exchange with escrow guarantee"
 * )
 * @OA\Server(
 *     url="http://sandbox.api.xrpchange.site/api/v1",
 *     description="Sandbox server (for testing)"
 * )
 * @OA\Server(
 *     url="http://api.xrpchange.site/api/v1",
 *     description="Production server"
 * )
 * @OA\Server(
 *     url="http://localhost:8000/api/v1",
 *     description="Local server"
 * )
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer"
 * )
 */
class OpenApiAnnotations
{
    // Этот класс нужен только для OpenAPI аннотаций
}
