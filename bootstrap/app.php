<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Отключаем CSRF верификацию для вебхука Telegram, так как запросы идут от внешнего сервера Telegram
        $middleware->validateCsrfTokens(except: [
            'webhook/telegram',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
