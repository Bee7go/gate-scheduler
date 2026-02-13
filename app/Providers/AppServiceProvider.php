<?php

namespace App\Providers;

use App\Services\GateSelectionStrategyFactory;
use App\Services\GateSelectionStrategyInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GateSelectionStrategyFactory::class);

        $this->app->bind(
            GateSelectionStrategyInterface::class,
            function ($app) {
                /** @var GateSelectionStrategyFactory $factory */
                $factory = $app->make(GateSelectionStrategyFactory::class);

                $strategyKey = config('services.gates.allocation_strategy', 'greedy');

                return $factory->make($strategyKey);
            }
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
