<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Base API Controller
 * 
 * Provides common functionality for all API controllers including
 * standardized response formatting and version information.
 */
abstract class BaseApiController extends Controller
{
    /**
     * API version constant
     */
    protected const API_VERSION = '1.0';

    /**
     * Return a successful JSON response
     */
    protected function successResponse($data = null, string $message = null, int $status = 200): JsonResponse
    {
        $response = [
            'success' => true,
            'version' => self::API_VERSION,
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        if ($message !== null) {
            $response['message'] = $message;
        }

        return response()->json($response, $status);
    }

    /**
     * Return an error JSON response
     */
    protected function errorResponse(string $message, int $status = 400, array $errors = null): JsonResponse
    {
        $response = [
            'success' => false,
            'version' => self::API_VERSION,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $status);
    }

    /**
     * Return a validation error response
     */
    protected function validationErrorResponse(array $errors, int $status = 422): JsonResponse
    {
        return $this->errorResponse('Validation failed', $status, $errors);
    }

    /**
     * Return a not found response
     */
    protected function notFoundResponse(string $message = 'Resource not found'): JsonResponse
    {
        return $this->errorResponse($message, 404);
    }

    /**
     * Return an unauthorized response
     */
    protected function unauthorizedResponse(string $message = 'Unauthorized'): JsonResponse
    {
        return $this->errorResponse($message, 401);
    }

    /**
     * Return a forbidden response
     */
    protected function forbiddenResponse(string $message = 'Forbidden'): JsonResponse
    {
        return $this->errorResponse($message, 403);
    }

    /**
     * Return a server error response
     */
    protected function serverErrorResponse(string $message = 'Internal server error'): JsonResponse
    {
        return $this->errorResponse($message, 500);
    }
}
