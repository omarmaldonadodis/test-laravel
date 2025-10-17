<?php

namespace App\Providers;

use App\Contracts\MoodleServiceInterface;
use App\Services\MoodleService;
use App\Services\Webhook\WebhookIdempotencyService;
use Illuminate\Support\ServiceProvider;

/**
 * Service Provider principal
 * Bindings de interfaces (Dependency Inversion Principle)
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind de interfaces a implementaciones concretas
        // Principio: Dependency Inversion - los jobs dependen de interfaces
        $this->app->singleton(MoodleServiceInterface::class, MoodleService::class);

        // Bind de servicios concretos que no tienen interfaz
        $this->app->singleton(WebhookIdempotencyService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
