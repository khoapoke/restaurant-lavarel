<?php

use App\Http\Middleware\CheckAccountActive;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 1. Never redirect guests to web login in API mode
        $middleware->redirectGuestsTo(fn () => null);

        // 2. Prepend ForceJsonResponse to ensure API requests always expect and receive JSON
        $middleware->prependToGroup('api', ForceJsonResponse::class);

        // 3. Register Custom Route Middleware Aliases
        $middleware->alias([
            'account.active' => CheckAccountActive::class,
            'role' => CheckRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always render JSON when path starts with api/ or expectsJson
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Handle 422: Form Validation Errors
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'code' => 422,
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        // Handle 401: Unauthenticated / Missing Token
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'code' => 401,
                    'message' => 'Unauthenticated or token is missing/invalid',
                    'errors' => null,
                ], 401);
            }
        });

        // Handle RouteNotFoundException (when route('login') is invoked erroneously)
        $exceptions->render(function (RouteNotFoundException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'code' => 401,
                    'message' => 'Unauthenticated or token is missing/invalid',
                    'errors' => null,
                ], 401);
            }
        });

        // Handle 404: Model Not Found
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'code' => 404,
                    'message' => 'Resource not found',
                    'errors' => null,
                ], 404);
            }
        });

        // Handle Standard HttpExceptions (400, 403, 409, etc.)
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'code' => $e->getStatusCode(),
                    'message' => $e->getMessage(),
                    'errors' => null,
                ], $e->getStatusCode());
            }
        });

        // Handle 500: Fallback generic errors (never leak stack trace to API clients)
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $message = config('app.debug') ? $e->getMessage() : 'An unexpected server error occurred';
                return response()->json([
                    'success' => false,
                    'code' => 500,
                    'message' => $message,
                    'errors' => null,
                ], 500);
            }
        });
    })->create();
