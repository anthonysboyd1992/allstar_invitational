<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (array_keys(User::Areas) as $area) {
            Gate::define("access-{$area}", fn (User $user): bool => $user->canAccessArea($area));
        }

        Gate::define('access-live-timing', fn (User $user): bool => $user->canAccessArea('timing') || $user->canAccessArea('results'));
        Gate::define('access-pill-draw', fn (User $user): bool => $user->canAccessArea('competitors') || $user->canAccessArea('lineups'));
        Gate::define('access-tv', fn (User $user): bool => $user->can('access-pill-draw'));
    }
}
