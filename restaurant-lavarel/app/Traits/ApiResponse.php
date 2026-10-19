<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * Send a standardized success JSON response.
     */
    protected function successResponse(mixed $data = null, string $message = 'Success', int $code = 200): JsonResponse
    {
        $payload = [
            'success' => true,
            'code' => $code,
            'message' => $message,
        ];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $code);
    }

    /**
     * Send a standardized error JSON response.
     */
    protected function errorResponse(string $message = 'Error', int $code = 400, mixed $errors = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }
}
