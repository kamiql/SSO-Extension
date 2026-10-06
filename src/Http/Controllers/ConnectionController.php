<?php

declare(strict_types=1);

namespace Sso\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Sso\Models\Identity;
use Sso\ProviderRegistry;
use Sso\SsoRoutes;

final class ConnectionController
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
     * @param Request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $linked = Identity::query()->where('user_id', $request->user()?->id)->get()->keyBy('provider');

        $data = [];
        foreach ($this->providers->all() as $provider) {
            $identity = $linked->get($provider->id());
            $enabled = $provider->enabled();
            if (! $enabled && $identity === null) {
                continue;
            }

            $data[] = [
                'id' => $provider->id(),
                'name' => $provider->name(),
                'enabled' => $enabled,
                'link_url' => SsoRoutes::link($provider->id()),
                'account' => $identity === null ? null : [
                    'name' => $identity->name,
                    'email' => $identity->email,
                    'avatar_url' => $identity->avatar_url,
                    'linked_at' => $identity->created_at?->toAtomString(),
                ],
            ];
        }

        return new JsonResponse(['data' => $data]);
    }

    /**
     * @param Request
     * @param string
     * @return Response
     */
    public function destroy(Request $request, string $provider): Response
    {
        Identity::query()->where('user_id', $request->user()?->id)->where('provider', $provider)->delete();

        return new Response(status: 204);
    }
}
