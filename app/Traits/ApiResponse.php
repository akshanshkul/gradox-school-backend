<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * Return a standardized success JSON response.
     *
     * @param mixed $data
     * @param string|null $message
     * @param int $code
     * @return JsonResponse
     */
    protected function successResponse($data = null, string $message = null, int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => 1,
            'message' => $message ?? 'Operation successful',
            'data'    => $data,
        ], $code);
    }

    /**
     * Return a standardized error JSON response.
     *
     * @param string $message
     * @param int $code
     * @param mixed|null $data
     * @return JsonResponse
     */
    protected function errorResponse(string $message = 'An error occurred', int $code = 400, $data = null): JsonResponse
    {
        return response()->json([
            'success' => 0,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }
}
