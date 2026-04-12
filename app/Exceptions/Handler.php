<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Standardize error responses for API requests.
     */
    public function render($request, Throwable $e)
    {
        if ($request->is('api/*') || $request->wantsJson()) {
            return $this->handleApiExceptions($e);
        }

        return parent::render($request, $e);
    }

    protected function handleApiExceptions(Throwable $e)
    {
        $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 400;
        $message = $e->getMessage() ?: 'An error occurred';
        $data = null;

        if ($e instanceof \Illuminate\Validation\ValidationException) {
            $status = 422;
            $message = 'The given data was invalid.';
            $data = $e->errors();
        } elseif ($e instanceof \Illuminate\Auth\AuthenticationException) {
            $status = 411;
            $message = 'Unauthenticated';
        } elseif ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            $status = 404;
            $message = 'Resource not found';
        } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            $status = 404;
            $message = 'Endpoint not found';
        }

        return response()->json([
            'success' => 0,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }
}
