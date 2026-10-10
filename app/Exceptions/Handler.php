<?php

namespace App\Exceptions;

use Throwable;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    /**
     * Report or log an exception.
     *
     * @param  \Throwable  $exception
     * @return void
     *
     * @throws \Throwable
     */
    public function report(Throwable $exception)
    {
        parent::report($this->normalizeThrowable($exception));
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Throwable
     */
    public function render($request, Throwable $exception)
    {
        $exception = $this->normalizeThrowable($exception);
        if ($exception instanceof AppException) {
            $layout = dujiaoka_config_get('template', 'unicorn');
            $tplPath = $layout . '/errors/error';
            return response()->view($tplPath, ['title' => __('dujiaoka.error_title'), 'content' => $exception->getMessage(), 'url' => ""]);
        }
        return parent::render($request, $exception);
    }

    private function normalizeThrowable(Throwable $exception): Throwable
    {
        // Laravel 6 delegates to an Exception-only handler; modern versions accept Throwable.
        if (!$exception instanceof \Exception && class_exists(\Symfony\Component\Debug\Exception\FatalThrowableError::class)) {
            return new \Symfony\Component\Debug\Exception\FatalThrowableError($exception);
        }
        return $exception;
    }
}
