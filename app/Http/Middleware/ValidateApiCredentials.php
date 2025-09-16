<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiCredentials
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $appKey = $request->header('X-App-Key');
        $appSecret = $request->header('X-App-Secret');

        // Get configured app credentials from environment
        $validAppKey = config('app.api_key');
        $validAppSecret = config('app.api_secret');

        // Check if credentials are provided
        if (empty($appKey) || empty($appSecret)) {
            return response()->json([
                'success' => false,
                'message' => 'API credentials are required',
                'error' => 'Missing X-App-Key or X-App-Secret header'
            ], 401);
        }

        // Validate credentials
        if ($appKey !== $validAppKey || $appSecret !== $validAppSecret) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid API credentials',
                'error' => 'Invalid X-App-Key or X-App-Secret'
            ], 401);
        }

        return $next($request);
    }
}