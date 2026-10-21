<?php

namespace App\Providers;

use App\Http\Middleware\SetLocale;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class LocaleServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        $router->pushMiddlewareToGroup('web', SetLocale::class);
        $router->pushMiddlewareToGroup('api', SetLocale::class . ':sanctum');
    }
}
