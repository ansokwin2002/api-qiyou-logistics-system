<?php

namespace App\Http\Controllers\Traits;

use Illuminate\Http\JsonResponse;

/**
 * Response shape expected by the legacy admin panel (manageapi/*).
 * Business errors stay on HTTP 200 with code '0' so the panel can render the
 * message instead of the generic network error toast.
 */
trait FrontendResponse
{
    protected function frontendOk($data = null, string $message = 'Success'): JsonResponse
    {
        return response()->json([
            'code' => '1',
            'message' => $message,
            'data' => $data,
        ], 200);
    }

    protected function frontendError(string $message = 'Error', $data = null): JsonResponse
    {
        return response()->json([
            'code' => '0',
            'message' => $message,
            'data' => $data,
        ], 200);
    }

    /**
     * Extract parameters from both the query string and the nested JSON body.
     * The panel posts `{ data: { key: value } }`.
     */
    protected function getParams(\Illuminate\Http\Request $request): array
    {
        $bodyData = $request->input('data', []);
        if (! is_array($bodyData)) {
            $bodyData = [];
        }

        return array_merge($request->all(), $bodyData);
    }
}
