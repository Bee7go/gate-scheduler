<?php

namespace App\Providers;

use App\Services\GateAllocation\Strategies\GateSelectionStrategyFactory;
use App\Services\GateAllocation\Strategies\GateSelectionStrategyInterface;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

                $strategyKey = config('services.gates.allocation_strategy');

                return $factory->make($strategyKey);
            }
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('sync-now', function ($request) {
            return Limit::perMinutes(2, 1)->by($request->header('X-Api-Key'));
        });

        RateLimiter::for('auth', function ($request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        $this->configureApiDocs();
    }

    /**
     * Configure the generated OpenAPI documentation (served at /docs/api).
     */
    private function configureApiDocs(): void
    {
        // The interactive docs are part of the public product surface.
        Gate::define('viewApiDocs', fn ($user = null) => true);

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                // Default security: the X-Api-Key header used by all data endpoints.
                $openApi->secure(
                    SecurityScheme::apiKey('header', 'X-Api-Key')
                        ->setDescription('API key created via `POST /api-keys`. Shown once at creation; expires after 1 year.')
                );

                $openApi->components->addSecurityScheme(
                    'bearerToken',
                    SecurityScheme::http('bearer')
                        ->as('bearerToken')
                        ->setDescription('Bearer token obtained via `POST /login`. Expires after 1 hour; used for API-key management.')
                );
            })
            ->withOperationTransformers(function (Operation $operation, RouteInfo $routeInfo) {
                $middleware = $routeInfo->route->gatherMiddleware();

                if (in_array('auth.bearer', $middleware, true)) {
                    $operation->security = [new SecurityRequirement(['bearerToken' => []])];
                } elseif (! in_array('auth.apikey', $middleware, true)) {
                    $operation->security = []; // Public endpoint (register, login).
                }

                // Responses produced by middleware are invisible to static
                // analysis of the controllers, so document them here.
                if (in_array('auth.apikey', $middleware, true)) {
                    $this->addMessageResponse($operation, 401, 'Authentication failed', 'API key missing, invalid, or expired.');
                    $this->addMessageResponse($operation, 503, 'Service unavailable', 'The credential store is unreachable; authentication fails closed.');
                }

                if (in_array('auth.bearer', $middleware, true)) {
                    $this->addMessageResponse($operation, 401, 'Authentication failed', 'Bearer token missing, invalid, or expired.');
                    $this->addMessageResponse($operation, 503, 'Service unavailable', 'The credential store is unreachable; authentication fails closed.');
                }

                if (collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'))) {
                    $this->addMessageResponse($operation, 429, 'Too many requests', 'The rate limit for this endpoint was exceeded.');
                }
            });
    }

    /**
     * Document a `{"message": "..."}` error response, unless the operation
     * already documents a (usually richer, controller-derived) response for
     * that status code.
     */
    private function addMessageResponse(Operation $operation, int $status, string $summary, string $description): void
    {
        foreach ($operation->responses ?? [] as $existing) {
            if (($existing->code ?? null) == $status) {
                return;
            }
        }

        $body = new ObjectType;
        $body->addProperty('message', new StringType);
        $body->setRequired(['message']);

        $operation->addResponse(
            Response::make($status)
                ->setSummary($summary)
                ->setDescription($description)
                ->setContent('application/json', Schema::fromType($body))
        );
    }
}
