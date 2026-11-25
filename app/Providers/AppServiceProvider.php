<?php

namespace App\Providers;

use App\Http\ApiErrorCode;
use App\Models\ApiKey;
use App\Services\GateAllocation\Strategies\GateSelectionStrategyFactory;
use App\Services\GateAllocation\Strategies\GateSelectionStrategyInterface;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\License;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;

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
        // Runs after auth.apikey, which attaches the key model to the request.
        // Keyed by the key's id, never the plaintext secret: limiter keys are
        // persisted in the cache store.
        RateLimiter::for('sync-now', function ($request) {
            $apiKey = $request->attributes->get('apiKey');

            if (! $apiKey instanceof ApiKey) {
                Log::error('throttle:sync-now used on a route without auth.apikey', ['path' => $request->path()]);

                return Limit::perMinute(0); // Fail closed, as with throttle:api-key.
            }

            return Limit::perMinutes(2, 1)->by('sync-now:'.$apiKey->id);
        });

        RateLimiter::for('auth', function ($request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Per-key limit for data endpoints; runs after auth.apikey, which
        // attaches the key model to the request.
        RateLimiter::for('api-key', function ($request) {
            $apiKey = $request->attributes->get('apiKey');

            if (! $apiKey instanceof ApiKey) {
                // Fail closed: this limiter only makes sense after auth.apikey.
                // A route wired with throttle:api-key but no auth would
                // otherwise silently become unlimited.
                Log::error('throttle:api-key used on a route without auth.apikey', ['path' => $request->path()]);

                return Limit::perMinute(0);
            }

            $perMinute = $apiKey->rate_limit_per_minute
                ?? (int) config('services.auth.key_rate_limit_per_minute');

            return Limit::perMinute($perMinute)->by('api-key:'.$apiKey->id);
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
                $openApi->info->setLicense(new License('MIT', identifier: 'MIT'));

                // Scramble shares one ValidationException response component
                // across operations (keyed by the exception FQCN until
                // serialization); the runtime renderer adds a stable `code`,
                // so document it there too.
                $validation = $openApi->components->responses['\\'.ValidationException::class] ?? null;

                if ($validation instanceof Response && isset($validation->content['application/json'])) {
                    $schema = $validation->getContent('application/json');

                    if ($schema instanceof Schema && $schema->type instanceof ObjectType) {
                        $schema->type->addProperty('code', (new StringType)->enum([ApiErrorCode::ValidationFailed->value]));
                        $schema->type->setRequired(['code', 'message', 'errors']);
                    }
                }

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
                $middleware = collect($routeInfo->route->gatherMiddleware())->filter(fn ($m) => is_string($m));

                $apiKeyEntry = $middleware->first(fn ($m) => str_starts_with($m, 'auth.apikey'));
                $usesBearer = $middleware->contains('auth.bearer');

                if ($usesBearer) {
                    $operation->security = [new SecurityRequirement(['bearerToken' => []])];
                } elseif ($apiKeyEntry === null) {
                    $operation->security = []; // Public endpoint (register, login).
                }

                // Responses produced by middleware are invisible to static
                // analysis of the controllers, so document them here.
                if ($apiKeyEntry !== null) {
                    $this->addMessageResponse($operation, 401, 'Authentication failed', 'API key missing, invalid, or expired.', ApiErrorCode::AuthenticationFailed);
                    $this->addMessageResponse($operation, 503, 'Service unavailable', 'The credential store is unreachable; authentication fails closed.', ApiErrorCode::ServiceUnavailable);

                    if (str_contains($apiKeyEntry, ':')) {
                        $scope = substr($apiKeyEntry, strpos($apiKeyEntry, ':') + 1);
                        $this->addMessageResponse($operation, 403, 'Insufficient scope', "The API key does not carry the `{$scope}` scope this endpoint requires.", ApiErrorCode::InsufficientScope);
                    }
                }

                if ($usesBearer) {
                    $this->addMessageResponse($operation, 401, 'Authentication failed', 'Bearer token missing, invalid, or expired.', ApiErrorCode::AuthenticationFailed);
                    $this->addMessageResponse($operation, 503, 'Service unavailable', 'The credential store is unreachable; authentication fails closed.', ApiErrorCode::ServiceUnavailable);
                }

                if ($middleware->contains(fn ($m) => str_starts_with($m, 'throttle:'))) {
                    $this->addMessageResponse($operation, 429, 'Too many requests', 'The rate limit for this endpoint was exceeded.', ApiErrorCode::RateLimited);
                }

                if ($middleware->contains('idempotency')) {
                    $operation->addParameters([
                        Parameter::make('Idempotency-Key', 'header')
                            ->setSchema(Schema::fromType(new StringType))
                            ->description('Optional, 1-255 characters (letters, digits, dashes, underscores; malformed values are rejected with 422). Retrying with the same key within 24 hours replays the recorded response instead of repeating the action. Reusing a key with a different request returns 409.'),
                    ]);
                    $this->addMessageResponse($operation, 409, 'Idempotency conflict', 'The Idempotency-Key was already used with a different request (`idempotency_conflict`), or the original request is still in progress (`idempotency_in_progress`).', ApiErrorCode::IdempotencyConflict, ApiErrorCode::IdempotencyInProgress);
                }

                // Document the response headers middleware adds.
                foreach ($operation->responses ?? [] as $response) {
                    if (! $response instanceof Response) {
                        continue;
                    }

                    $response->addHeader('X-Request-Id', $this->header('Unique id for this request; also attached to the server logs. Echoes a well-formed client-supplied X-Request-Id.'));

                    if ($middleware->contains(fn ($m) => str_starts_with($m, 'throttle:'))) {
                        $response->addHeader('X-RateLimit-Limit', $this->header('The rate limit that applies to this credential.'));
                        $response->addHeader('X-RateLimit-Remaining', $this->header('Requests remaining in the current window.'));

                        if ($response->code == 429) {
                            $response->addHeader('Retry-After', $this->header('Seconds to wait before retrying.'));
                        }
                    }

                    if ($middleware->contains('idempotency') && $response->code >= 200 && $response->code < 300) {
                        $response->addHeader('Idempotency-Replayed', $this->header('Present (true) when this response was replayed from an earlier request with the same Idempotency-Key.'));
                    }
                }
            });
    }

    private function header(string $description): Header
    {
        return (new Header)
            ->setDescription($description)
            ->setSchema(Schema::fromType(new StringType));
    }

    /**
     * Document a `{"code": "...", "message": "..."}` error response, unless
     * the operation already documents a (usually richer, controller-derived)
     * response for that status code.
     */
    private function addMessageResponse(Operation $operation, int $status, string $summary, string $description, ApiErrorCode ...$codes): void
    {
        foreach ($operation->responses ?? [] as $existing) {
            if (($existing->code ?? null) == $status) {
                return;
            }
        }

        $body = new ObjectType;
        $body->addProperty('code', (new StringType)->enum(array_map(fn (ApiErrorCode $code) => $code->value, $codes)));
        $body->addProperty('message', new StringType);
        $body->setRequired(['code', 'message']);

        $operation->addResponse(
            Response::make($status)
                ->setSummary($summary)
                ->setDescription($description)
                ->setContent('application/json', Schema::fromType($body))
        );
    }
}
