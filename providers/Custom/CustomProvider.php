<?php

declare(strict_types=1);

namespace Sso\Providers\Custom;

use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Sso\Data\ExternalIdentity;
use Sso\Exceptions\SsoException;
use Sso\OAuth2Provider;
use Throwable;

/**
 * Generic OpenID Connect provider for self-hosted identity services.
 */
final class CustomProvider extends OAuth2Provider
{
    /**
     * @inheritdoc
     */
    public function id(): string
    {
        return 'custom';
    }

    /**
     * @inheritdoc
     */
    public function name(): string
    {
        return $this->config->string($this->key('name')) ?: 'Custom OIDC';
    }

    /**
     * @inheritdoc
     */
    public function settings(): array
    {
        $tab = 'Custom OIDC';

        return [
            ExtensionSettingDefinition::make($this->key('enabled'), $this->key('enabled'), false, ['boolean'])
                ->label('Enabled')
                ->tab($tab)
                ->field('toggle')
                ->normalizeUsing(fn (mixed $value): bool => $value === true),
            ExtensionSettingDefinition::make($this->key('name'), $this->key('name'), 'Custom OIDC', ['required', 'string', 'max:64'])
                ->label('Provider name')
                ->tab($tab)
                ->help('The label shown on the sign-in button, for example Authentik.'),
            ExtensionSettingDefinition::make($this->key('client_id'), $this->key('client_id'), '', ['nullable', 'string', 'max:255'])
                ->label('Client ID')
                ->tab($tab)
                ->help('Set this provider application’s redirect URL to '.\Sso\SsoRoutes::callback($this->id()).'.'),
            ExtensionSettingDefinition::make($this->key('client_secret'), $this->key('client_secret'), '', ['nullable', 'string', 'max:255'])
                ->label('Client secret')
                ->tab($tab)
                ->secret(),
            ExtensionSettingDefinition::make($this->key('auto_fetch'), $this->key('auto_fetch'), true, ['boolean'])
                ->label('Fetch endpoints from .well-known')
                ->tab($tab)
                ->help('When enabled, authorization, token and user-info endpoints are discovered from the issuer’s OpenID Connect configuration.')
                ->field('toggle')
                ->normalizeUsing(fn (mixed $value): bool => $value === true),
            ExtensionSettingDefinition::make($this->key('issuer_url'), $this->key('issuer_url'), '', ['nullable', 'string', 'max:2048'])
                ->label('Issuer URL')
                ->tab($tab)
                ->help('Issuer base URL. The discovery document is fetched from <issuer>/.well-known/openid-configuration.'),
            ExtensionSettingDefinition::make($this->key('authorize_endpoint'), $this->key('authorize_endpoint'), '', ['nullable', 'string', 'max:2048'])
                ->label('Authorization endpoint')
                ->tab($tab),
            ExtensionSettingDefinition::make($this->key('token_endpoint'), $this->key('token_endpoint'), '', ['nullable', 'string', 'max:2048'])
                ->label('Token endpoint')
                ->tab($tab),
            ExtensionSettingDefinition::make($this->key('user_endpoint'), $this->key('user_endpoint'), '', ['nullable', 'string', 'max:2048'])
                ->label('User-info endpoint')
                ->tab($tab),
            ExtensionSettingDefinition::make($this->key('scopes'), $this->key('scopes'), 'openid profile email', ['nullable', 'string', 'max:512'])
                ->label('Scopes')
                ->tab($tab)
                ->help('Space-separated OpenID Connect scopes. The openid scope is always included.'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function enabled(): bool
    {
        if (! parent::enabled()) {
            return false;
        }

        if ($this->config->boolean($this->key('auto_fetch'))) {
            return $this->isHttpUrl($this->config->string($this->key('issuer_url')));
        }

        return $this->isHttpUrl($this->config->string($this->key('authorize_endpoint')))
            && $this->isHttpUrl($this->config->string($this->key('token_endpoint')))
            && $this->isHttpUrl($this->config->string($this->key('user_endpoint')));
    }

    /**
     * @inheritdoc
     */
    protected function authorizeEndpoint(): string
    {
        return $this->endpoint('authorization_endpoint', 'authorize_endpoint');
    }

    /**
     * @inheritdoc
     */
    protected function tokenEndpoint(): string
    {
        return $this->endpoint('token_endpoint', 'token_endpoint');
    }

    /**
     * @inheritdoc
     */
    protected function userEndpoint(): string
    {
        return $this->endpoint('userinfo_endpoint', 'user_endpoint');
    }

    /**
     * @inheritdoc
     */
    protected function scopes(): array
    {
        $configured = preg_split('/\s+/', trim($this->config->string($this->key('scopes')))) ?: [];
        $scopes = array_values(array_unique(array_filter($configured, fn (string $scope): bool => $scope !== '')));

        return array_values(array_unique(['openid', ...$scopes]));
    }

    /**
     * @inheritdoc
     */
    protected function mapIdentity(array $user): ExternalIdentity
    {
        $id = $this->stringId($user['sub'] ?? $user['id'] ?? null);
        $email = is_string($user['email'] ?? null) ? trim($user['email']) : '';
        $email = $email !== '' ? $email : null;
        $name = '';
        foreach (['name', 'preferred_username', 'nickname'] as $field) {
            if (is_string($user[$field] ?? null) && trim($user[$field]) !== '') {
                $name = trim($user[$field]);
                break;
            }
        }
        $name = $name !== '' ? $name : ($email ?? $id);
        $avatar = is_string($user['picture'] ?? null) && $this->isHttpUrl($user['picture']) ? $user['picture'] : null;

        return new ExternalIdentity(
            $this->id(),
            $id,
            $name,
            $email,
            ($user['email_verified'] ?? false) === true,
            $avatar,
        );
    }

    /**
     * Return either the discovered endpoint or the configured manual endpoint.
     *
     * @param string
     * @param string
     * @return string
     */
    private function endpoint(string $metadataKey, string $settingKey): string
    {
        if (! $this->config->boolean($this->key('auto_fetch'))) {
            return $this->validatedUrl($this->config->string($this->key($settingKey)));
        }

        $metadata = $this->discovery();
        $endpoint = $metadata[$metadataKey] ?? null;
        if (! is_string($endpoint)) {
            Log::warning('Custom OIDC discovery metadata is missing a required endpoint', [
                'provider' => $this->id(),
                'endpoint' => $metadataKey,
            ]);
            throw new SsoException(SsoException::PROVIDER);
        }

        try {
            return $this->validatedUrl($endpoint);
        } catch (SsoException $exception) {
            Log::warning('Custom OIDC discovery endpoint is not a valid HTTP URL', [
                'provider' => $this->id(),
                'endpoint' => $metadataKey,
            ]);
            throw $exception;
        }
    }

    /**
     * Fetch and cache the provider's OpenID Connect discovery document.
     *
     * @return array
     */
    private function discovery(): array
    {
        try {
            $issuer = $this->validatedUrl($this->config->string($this->key('issuer_url')));
        } catch (SsoException $exception) {
            Log::warning('Custom OIDC issuer setting is not a valid HTTP URL', ['provider' => $this->id()]);
            throw $exception;
        }

        if (parse_url($issuer, PHP_URL_QUERY) !== null || parse_url($issuer, PHP_URL_FRAGMENT) !== null) {
            Log::warning('Custom OIDC issuer URL must not contain a query or fragment', ['provider' => $this->id()]);
            throw new SsoException(SsoException::PROVIDER);
        }

        $cacheKey = 'sso.custom.oidc.'.hash('sha256', $issuer);
        $cacheHit = Cache::has($cacheKey);
        $issuerHost = parse_url($issuer, PHP_URL_HOST);

        Log::debug('Custom OIDC discovery lookup started', [
            'provider' => $this->id(),
            'issuer_host' => is_string($issuerHost) ? $issuerHost : null,
            'cache_hit' => $cacheHit,
        ]);

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($issuer, $issuerHost): array {
            $url = rtrim($issuer, '/').'/.well-known/openid-configuration';

            try {
                $response = Http::acceptJson()->timeout(10)->get($url)->throw();
            } catch (Throwable $throwable) {
                Log::warning('Custom OIDC discovery request failed', [
                    'provider' => $this->id(),
                    'issuer_host' => is_string($issuerHost) ? $issuerHost : null,
                    'exception_class' => $throwable::class,
                    'http_status' => $throwable instanceof RequestException ? $throwable->response->status() : null,
                ]);
                throw $throwable;
            }

            $metadata = $response->json();
            if (! is_array($metadata)) {
                Log::warning('Custom OIDC discovery response was not a JSON object', [
                    'provider' => $this->id(),
                    'http_status' => $response->status(),
                ]);
                throw new SsoException(SsoException::PROVIDER);
            }

            $issuerMatches = is_string($metadata['issuer'] ?? null)
                && rtrim($metadata['issuer'], '/') === rtrim($issuer, '/');
            $hasAuthorizationEndpoint = is_string($metadata['authorization_endpoint'] ?? null);
            $hasTokenEndpoint = is_string($metadata['token_endpoint'] ?? null);
            $hasUserInfoEndpoint = is_string($metadata['userinfo_endpoint'] ?? null);

            Log::debug('Custom OIDC discovery metadata received', [
                'provider' => $this->id(),
                'http_status' => $response->status(),
                'issuer_matches_config' => $issuerMatches,
                'authorization_endpoint_present' => $hasAuthorizationEndpoint,
                'token_endpoint_present' => $hasTokenEndpoint,
                'userinfo_endpoint_present' => $hasUserInfoEndpoint,
            ]);

            if (! $issuerMatches || ! $hasAuthorizationEndpoint || ! $hasTokenEndpoint || ! $hasUserInfoEndpoint) {
                throw new SsoException(SsoException::PROVIDER);
            }

            foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'] as $key) {
                try {
                    $this->validatedUrl($metadata[$key]);
                } catch (SsoException $exception) {
                    Log::warning('Custom OIDC discovery returned an invalid endpoint URL', [
                        'provider' => $this->id(),
                        'endpoint' => $key,
                    ]);
                    throw $exception;
                }
            }

            Log::debug('Custom OIDC discovery metadata validated', ['provider' => $this->id()]);

            return $metadata;
        });
    }

    /**
     * @param string
     * @return bool
     */
    private function isHttpUrl(string $value): bool
    {
        $parts = parse_url($value);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * @param string
     * @return string
     */
    private function validatedUrl(string $value): string
    {
        if (! $this->isHttpUrl($value)) {
            throw new SsoException(SsoException::PROVIDER);
        }

        return $value;
    }
}
