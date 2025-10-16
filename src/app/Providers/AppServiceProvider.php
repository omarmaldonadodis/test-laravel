<?php

namespace App\Providers;

use App\Contracts\MoodleServiceInterface;
use App\Services\MoodleService;
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
        // Bind de MoodleServiceInterface a MoodleService
        // Principio: Dependency Inversion - los jobs dependen de la interfaz
        $this->app->singleton(MoodleServiceInterface::class, MoodleService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}