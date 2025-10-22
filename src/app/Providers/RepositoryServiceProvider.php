<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Contracts\FailedEnrollmentRepositoryInterface;
use App\Repositories\FailedEnrollmentRepository;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            FailedEnrollmentRepositoryInterface::class,
            FailedEnrollmentRepository::class
        );
    }
}
