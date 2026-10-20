<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always answer API calls with JSON errors (Reliability: "displays appropriate error messages").
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*'));

        // Plain-language messages instead of technical ones (no model names, routes or stack traces).
        $json = fn (string $message, int $status) => response()->json(['message' => $message], $status);
        $isApi = fn (Request $request) => $request->is('api/*');

        $exceptions->render(fn (AuthenticationException $e, Request $request) => $isApi($request)
            ? $json('Your session has expired. Please log in again.', 401) : null);

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($json, $isApi) {
            if (! $isApi($request)) return null;
            $message = $e->getMessage();
            // Keep messages written for the user (abort(404, '...')); replace Laravel's technical defaults.
            $technical = $message === '' || str_starts_with($message, 'No query results') || str_starts_with($message, 'The route');
            return $json($technical ? 'The record you are looking for was not found. It may have been removed.' : $message, 404);
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) use ($json, $isApi) {
            if (! $isApi($request)) return null;
            $message = $e->getMessage();
            return $json($message === '' || $message === 'This action is unauthorized.' ? 'You do not have permission to do this.' : $message, 403);
        });

        $exceptions->render(fn (ThrottleRequestsException $e, Request $request) => $isApi($request)
            ? response()->json(['message' => 'Too many attempts. Please wait a minute and try again.'], 429, $e->getHeaders()) : null);

        $exceptions->render(fn (MethodNotAllowedHttpException $e, Request $request) => $isApi($request)
            ? $json('This action is not available.', 405) : null);

        $exceptions->render(fn (PostTooLargeException $e, Request $request) => $isApi($request)
            ? $json('The file is too large. Please upload a smaller file.', 413) : null);

        // Unexpected server errors: never show technical details to users (they are still logged).
        $exceptions->render(function (Throwable $e, Request $request) use ($json, $isApi) {
            if (! $isApi($request) || config('app.debug') || $e instanceof HttpExceptionInterface || $e instanceof ValidationException) {
                return null;
            }
            return $json('Something went wrong on our side. Please try again in a few minutes.', 500);
        });
    })->create();
