<?php

namespace App\Providers;

use App\Contracts\MoodleServiceInterface;
use App\Contracts\OrderRepositoryInterface;
use App\Services\MoodleService;
use App\Repositories\OrderRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MoodleServiceInterface::class, MoodleService::class);
        $this->app->singleton(OrderRepositoryInterface::class, OrderRepository::class); // ← NUEVO
    }

    public function boot(): void
    {
        //
    }
}