<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * @OA\Info(
 *      version="1.0.0",
 *      title="NOYA WEB API Documentation",
 *      description="API documentation for NOYA WEB application",
 *      @OA\Contact(
 *          email="admin@noyaweb.com"
 *      ),
 *      @OA\License(
 *          name="Apache 2.0",
 *          url="http://www.apache.org/licenses/LICENSE-2.0.html"
 *      )
 * )
 * 
 * @OA\Server(
 *      url=L5_SWAGGER_CONST_HOST,
 *      description="NOYA WEB API Server"
 * )
 * 
 * @OA\SecurityScheme(
 *      securityScheme="bearerAuth",
 *      type="http",
 *      scheme="bearer",
 *      bearerFormat="JWT",
 *      description="Enter token in format (Bearer <token>)"
 * )
 */
class BaseController extends Controller
{
    /**
     * Success response method.
     *
     * @param mixed $result
     * @param string $message
     * @param int $code
     * @return JsonResponse
     */
    public function sendResponse($result, string $message = 'Operation successful', int $code = 200): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
        ];

        if ($result instanceof JsonResource || $result instanceof ResourceCollection) {
            $response['data'] = $result->response()->getData(true)['data'] ?? $result->toArray(request());
            
            // Add pagination meta if it exists
            if (isset($result->response()->getData(true)['meta'])) {
                $response['meta'] = $result->response()->getData(true)['meta'];
            }
            
            // Add pagination links if they exist
            if (isset($result->response()->getData(true)['links'])) {
                $response['links'] = $result->response()->getData(true)['links'];
            }
        } else {
            $response['data'] = $result;
        }

        return response()->json($response, $code);
    }

    /**
     * Error response method.
     *
     * @param string $error
     * @param array $errorMessages
     * @param int $code
     * @return JsonResponse
     */
    public function sendError(string $error, array $errorMessages = [], int $code = 404): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $error,
        ];

        if (!empty($errorMessages)) {
            $response['errors'] = $errorMessages;
        }

        return response()->json($response, $code);
    }

    /**
     * Validation error response method.
     *
     * @param array $errors
     * @param string $message
     * @return JsonResponse
     */
    public function sendValidationError(array $errors, string $message = 'Validation Error'): JsonResponse
    {
        return $this->sendError($message, $errors, 422);
    }

    /**
     * Unauthorized response method.
     *
     * @param string $message
     * @return JsonResponse
     */
    public function sendUnauthorized(string $message = 'Unauthorized'): JsonResponse
    {
        return $this->sendError($message, [], 401);
    }

    /**
     * Forbidden response method.
     *
     * @param string $message
     * @return JsonResponse
     */
    public function sendForbidden(string $message = 'Forbidden'): JsonResponse
    {
        return $this->sendError($message, [], 403);
    }

    /**
     * Not found response method.
     *
     * @param string $message
     * @return JsonResponse
     */
    public function sendNotFound(string $message = 'Resource not found'): JsonResponse
    {
        return $this->sendError($message, [], 404);
    }

    /**
     * Server error response method.
     *
     * @param string $message
     * @return JsonResponse
     */
    public function sendServerError(string $message = 'Internal server error'): JsonResponse
    {
        return $this->sendError($message, [], 500);
    }

    /**
     * Created response method.
     *
     * @param mixed $result
     * @param string $message
     * @return JsonResponse
     */
    public function sendCreated($result, string $message = 'Resource created successfully'): JsonResponse
    {
        return $this->sendResponse($result, $message, 201);
    }

    /**
     * Updated response method.
     *
     * @param mixed $result
     * @param string $message
     * @return JsonResponse
     */
    public function sendUpdated($result, string $message = 'Resource updated successfully'): JsonResponse
    {
        return $this->sendResponse($result, $message, 200);
    }

    /**
     * Deleted response method.
     *
     * @param string $message
     * @return JsonResponse
     */
    public function sendDeleted(string $message = 'Resource deleted successfully'): JsonResponse
    {
        return $this->sendResponse(null, $message, 200);
    }

    /**
     * No content response method.
     *
     * @return JsonResponse
     */
    public function sendNoContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * Paginated response method.
     *
     * @param mixed $result
     * @param string $message
     * @return JsonResponse
     */
    public function sendPaginated($result, string $message = 'Data retrieved successfully'): JsonResponse
    {
        return $this->sendResponse($result, $message);
    }
}
