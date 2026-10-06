<?php

declare(strict_types=1);

namespace Sso\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Sso\Contracts\IdentityProvider;
use Sso\ProviderRegistry;
use Sso\SsoRoutes;

final class ProviderController
{
    /**
     * @var ProviderRegistry
     */
    protected ProviderRegistry $providers;

    /**
     * @param ProviderRegistry
     */
    public function __construct(ProviderRegistry $providers)
    {
        $this->providers = $providers;
    }

    /**
     * @return JsonResponse
     */
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map(fn (IdentityProvider $provider): array => [
                'id' => $provider->id(),
                'name' => $provider->name(),
                'login_url' => SsoRoutes::login($provider->id()),
            ], $this->providers->enabled()),
        ]);
    }
}
