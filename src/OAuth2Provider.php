<?php

declare(strict_types=1);

namespace Sso;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Sso\Contracts\SupportsPkce;
use Sso\Data\ExternalIdentity;
use Sso\Exceptions\SsoException;
use Throwable;

abstract class OAuth2Provider implements SupportsPkce
{
    /**
     * @var SsoSettings
     */
    protected SsoSettings $config;

    /**
     * @param SsoSettings
     */
    public function __construct(SsoSettings $config)
    {
        $this->config = $config;
    }

    /**
     * @inheritdoc
     */
    public function settings(): array
    {
        return [
            ExtensionSettingDefinition::make($this->key('enabled'), $this->key('enabled'), false, ['boolean'])
                ->label('Enabled')
                ->tab($this->name())
                ->field('toggle')
                ->normalizeUsing(fn (mixed $value): bool => $value === true),
            ExtensionSettingDefinition::make($this->key('client_id'), $this->key('client_id'), '', ['nullable', 'string', 'max:255'])
                ->label('Client ID')
                ->tab($this->name())
                ->help('Set the redirect URL of the '.$this->name().' application to '.SsoRoutes::callback($this->id())),
            ExtensionSettingDefinition::make($this->key('client_secret'), $this->key('client_secret'), '', ['nullable', 'string', 'max:255'])
                ->label('Client secret')
                ->tab($this->name())
                ->secret(),
        ];
    }

    /**
     * @inheritdoc
     */
    public function enabled(): bool
    {
        return $this->config->boolean($this->key('enabled'))
            && $this->config->string($this->key('client_id')) !== ''
            && $this->config->string($this->key('client_secret')) !== '';
    }

    /**
     * @inheritdoc
     */
    public function usesPkce(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function authorizationUrl(string $state, string $redirectUri, ?string $codeChallenge = null): string
    {
        return $this->authorizeEndpoint().'?'.http_build_query([
            ...$this->authorizationParameters(),
            'response_type' => 'code',
            'client_id' => $this->config->string($this->key('client_id')),
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
            ...($codeChallenge === null ? [] : ['code_challenge' => $codeChallenge, 'code_challenge_method' => 'S256']),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @inheritdoc
     */
    public function identify(string $code, string $redirectUri, ?string $codeVerifier = null): ExternalIdentity
    {
        if ($codeVerifier !== null && ! Pkce::isValidVerifier($codeVerifier)) {
            Log::warning('SSO OAuth PKCE verifier was invalid', ['provider' => $this->id()]);
            throw new SsoException(SsoException::STATE);
        }

        Log::debug('SSO OAuth token exchange started', [
            'provider' => $this->id(),
            'pkce_verifier_present' => $codeVerifier !== null,
        ]);

        try {
            $tokenResponse = Http::asForm()->acceptJson()->timeout(10)->post($this->tokenEndpoint(), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'client_id' => $this->config->string($this->key('client_id')),
                'client_secret' => $this->config->string($this->key('client_secret')),
                ...($codeVerifier === null ? [] : ['code_verifier' => $codeVerifier]),
            ])->throw();
        } catch (Throwable $throwable) {
            Log::warning('SSO OAuth token exchange failed', [
                'provider' => $this->id(),
                'exception_class' => $throwable::class,
                'http_status' => $throwable instanceof RequestException ? $throwable->response->status() : null,
            ]);
            throw $throwable;
        }

        $token = $tokenResponse->json('access_token');
        Log::debug('SSO OAuth token response received', [
            'provider' => $this->id(),
            'http_status' => $tokenResponse->status(),
            'access_token_present' => is_string($token) && $token !== '',
        ]);

        if (! is_string($token) || $token === '') {
            Log::warning('SSO OAuth token response did not contain an access token', ['provider' => $this->id()]);
            throw new SsoException(SsoException::PROVIDER);
        }

        try {
            $userResponse = Http::acceptJson()->timeout(10)->withToken($token)->get($this->userEndpoint())->throw();
        } catch (Throwable $throwable) {
            Log::warning('SSO OAuth user-info request failed', [
                'provider' => $this->id(),
                'exception_class' => $throwable::class,
                'http_status' => $throwable instanceof RequestException ? $throwable->response->status() : null,
            ]);
            throw $throwable;
        }

        $user = $userResponse->json();
        if (! is_array($user)) {
            Log::warning('SSO OAuth user-info response was not a JSON object', [
                'provider' => $this->id(),
                'http_status' => $userResponse->status(),
            ]);
            throw new SsoException(SsoException::PROVIDER);
        }

        Log::debug('SSO OAuth user-info claims received', [
            'provider' => $this->id(),
            'http_status' => $userResponse->status(),
            'subject_present' => is_string($user['sub'] ?? null) || is_int($user['sub'] ?? null) || is_string($user['id'] ?? null) || is_int($user['id'] ?? null),
            'email_present' => is_string($user['email'] ?? null) && trim($user['email']) !== '',
            'email_verified_present' => array_key_exists('email_verified', $user),
            'email_verified_type' => get_debug_type($user['email_verified'] ?? null),
            'email_verified_strict_true' => ($user['email_verified'] ?? false) === true,
            'display_name_present' => collect(['name', 'preferred_username', 'nickname'])->contains(fn (string $key): bool => is_string($user[$key] ?? null) && trim($user[$key]) !== ''),
        ]);

        try {
            $identity = $this->mapIdentity($this->enrich($token, $user));
        } catch (Throwable $throwable) {
            Log::warning('SSO OAuth user-info claims could not be mapped to an identity', [
                'provider' => $this->id(),
                'exception_class' => $throwable::class,
            ]);
            throw $throwable;
        }

        Log::debug('SSO OAuth identity mapped', [
            'provider' => $identity->provider,
            'subject_present' => $identity->id !== '',
            'email_present' => $identity->email !== null && $identity->email !== '',
            'email_verified' => $identity->emailVerified,
        ]);

        return $identity;
    }

    /**
     * Add anything the profile endpoint does not return, such as a verified email
     * that lives on a separate endpoint. Runs before mapIdentity().
     *
     * @param string
     * @param array
     * @return array
     */
    protected function enrich(string $token, array $user): array
    {
        return $user;
    }

    /**
     * @param mixed
     * @return string
     */
    protected function stringId(mixed $value): string
    {
        $id = is_int($value) ? (string) $value : $value;
        if (! is_string($id) || $id === '') {
            throw new SsoException(SsoException::PROVIDER);
        }

        return $id;
    }

    /**
     * @return string
     */
    abstract protected function authorizeEndpoint(): string;

    /**
     * @return string
     */
    abstract protected function tokenEndpoint(): string;

    /**
     * @return string
     */
    abstract protected function userEndpoint(): string;

    /**
     * @return array
     */
    abstract protected function scopes(): array;

    /**
     * @param array
     * @return ExternalIdentity
     */
    abstract protected function mapIdentity(array $user): ExternalIdentity;

    /**
     * @return array
     */
    protected function authorizationParameters(): array
    {
        return [];
    }

    /**
     * @param string
     * @return string
     */
    protected function key(string $name): string
    {
        return $this->id().'_'.$name;
    }
}
