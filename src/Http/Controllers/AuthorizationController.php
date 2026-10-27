<?php

declare(strict_types=1);

namespace Sso\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Pterodactyl\Contracts\Users\CompletesLogins;
use Pterodactyl\Models\User;
use Sso\Contracts\ReadsCallback;
use Sso\Contracts\SupportsPkce;
use Sso\Data\ExternalIdentity;
use Sso\Exceptions\SsoException;
use Sso\Pkce;
use Sso\ProviderRegistry;
use Sso\Services\IdentityResolver;
use Sso\SsoRoutes;
use Throwable;

final class AuthorizationController
{
    public const string FLOW = 'sso.flow';

    public const string CHECKPOINT = 'sso.checkpoint';

    private const string LOGIN = 'login';

    private const string LINK = 'link';

    /**
     * @var ProviderRegistry
     */
    protected ProviderRegistry $providers;

    /**
     * @var IdentityResolver
     */
    protected IdentityResolver $resolver;

    /**
     * @var CompletesLogins
     */
    protected CompletesLogins $logins;

    /**
     * @param ProviderRegistry
     * @param IdentityResolver
     * @param CompletesLogins
     */
    public function __construct(ProviderRegistry $providers, IdentityResolver $resolver, CompletesLogins $logins)
    {
        $this->providers = $providers;
        $this->resolver = $resolver;
        $this->logins = $logins;
    }

    /**
     * @param Request
     * @param string
     * @return RedirectResponse
     */
    public function login(Request $request, string $provider): RedirectResponse
    {
        if ($request->user() !== null) {
            Log::debug('SSO login start skipped because a panel user is already authenticated', ['provider' => $provider]);

            return redirect()->to('/');
        }

        return $this->start($request, $provider, self::LOGIN);
    }

    /**
     * @param Request
     * @param string
     * @return RedirectResponse
     */
    public function link(Request $request, string $provider): RedirectResponse
    {
        return $this->start($request, $provider, self::LINK);
    }

    /**
     * @param Request
     * @param string
     * @return RedirectResponse
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $flow = $request->session()->pull(self::FLOW);
        $intent = is_array($flow) && ($flow['intent'] ?? null) === self::LINK ? self::LINK : self::LOGIN;

        Log::debug('SSO callback received', [
            'provider' => $provider,
            'intent' => $intent,
            'flow_session_present' => is_array($flow),
            'flow_provider_matches' => is_array($flow) && ($flow['provider'] ?? null) === $provider,
            'state_parameter_present' => is_string($request->query('state')) && $request->query('state') !== '',
            'provider_error_present' => $request->query('error') !== null,
            'authorization_code_present' => is_string($request->query('code')) && $request->query('code') !== '',
        ]);

        try {
            $identity = $this->identify($request, $provider, $flow);

            Log::debug('SSO provider identity received', [
                'provider' => $identity->provider,
                'intent' => $intent,
                'subject_present' => $identity->id !== '',
                'email_present' => $identity->email !== null && $identity->email !== '',
                'email_verified' => $identity->emailVerified,
            ]);

            return $intent === self::LINK ? $this->completeLink($request, $identity) : $this->completeLogin($request, $identity);
        } catch (SsoException $exception) {
            Log::warning('SSO callback rejected', [
                'provider' => $provider,
                'intent' => $intent,
                'reason' => $exception->reason,
            ]);

            return $this->failed($intent, $exception->reason);
        } catch (Throwable $throwable) {
            Log::error('SSO callback failed with an unexpected exception', [
                'provider' => $provider,
                'intent' => $intent,
                'exception_class' => $throwable::class,
            ]);

            throw $throwable;
        }
    }

    /**
     * @param Request
     * @param string
     * @param string
     * @return RedirectResponse
     */
    private function start(Request $request, string $id, string $intent): RedirectResponse
    {
        $provider = $this->providers->find($id);
        $available = $provider !== null && $provider->enabled();
        if (! $available) {
            Log::warning('SSO flow could not start because the provider is missing or disabled', [
                'provider' => $id,
                'intent' => $intent,
                'provider_registered' => $provider !== null,
                'provider_enabled' => $provider?->enabled() ?? false,
            ]);

            return $this->failed($intent, SsoException::UNAVAILABLE);
        }

        Log::debug('SSO flow started', ['provider' => $id, 'intent' => $intent]);

        $state = Str::random(40);
        $verifier = $provider instanceof SupportsPkce && $provider->usesPkce() ? Pkce::verifier() : null;

        try {
            $url = $provider instanceof SupportsPkce
                ? $provider->authorizationUrl($state, SsoRoutes::callback($id), $verifier === null ? null : Pkce::challenge($verifier))
                : $provider->authorizationUrl($state, SsoRoutes::callback($id));
        } catch (SsoException $exception) {
            Log::warning('SSO provider could not create an authorization URL', [
                'provider' => $id,
                'intent' => $intent,
                'reason' => $exception->reason,
            ]);

            return $this->failed($intent, $exception->reason);
        } catch (Throwable $throwable) {
            Log::error('SSO provider failed while creating an authorization URL', [
                'provider' => $id,
                'intent' => $intent,
                'exception_class' => $throwable::class,
            ]);
            report($throwable);

            return $this->failed($intent, SsoException::PROVIDER);
        }

        $request->session()->put(self::FLOW, ['provider' => $id, 'state' => $state, 'intent' => $intent, 'code_verifier' => $verifier]);

        Log::debug('SSO authorization redirect prepared', [
            'provider' => $id,
            'intent' => $intent,
            'pkce_enabled' => $verifier !== null,
        ]);

        return redirect()->away($url);
    }

    /**
     * @param Request
     * @param string
     * @param mixed
     * @return ExternalIdentity
     */
    private function identify(Request $request, string $id, mixed $flow): ExternalIdentity
    {
        $expected = is_array($flow) && ($flow['provider'] ?? null) === $id ? ($flow['state'] ?? null) : null;
        $state = $request->query('state');
        if (! is_string($expected) || ! is_string($state) || ! hash_equals($expected, $state)) {
            Log::warning('SSO callback state validation failed', [
                'provider' => $id,
                'session_flow_present' => is_array($flow),
                'expected_state_present' => is_string($expected) && $expected !== '',
                'received_state_present' => is_string($state) && $state !== '',
                'flow_provider_matches' => is_array($flow) && ($flow['provider'] ?? null) === $id,
            ]);
            throw new SsoException(SsoException::STATE);
        }

        $provider = $this->providers->find($id);
        if ($provider === null || ! $provider->enabled()) {
            Log::warning('SSO callback rejected because the provider is unavailable', [
                'provider' => $id,
                'provider_registered' => $provider !== null,
            ]);
            throw new SsoException(SsoException::UNAVAILABLE);
        }

        $code = $provider instanceof ReadsCallback ? $provider->callbackCode($request) : $request->query('code');
        if ($request->query('error') !== null || ! is_string($code) || $code === '') {
            Log::warning('SSO callback did not contain a usable authorization response', [
                'provider' => $id,
                'provider_error_present' => $request->query('error') !== null,
                'authorization_code_present' => is_string($code) && $code !== '',
            ]);
            throw new SsoException(SsoException::DENIED);
        }

        $verifier = $flow['code_verifier'] ?? null;

        try {
            $identity = $provider instanceof SupportsPkce
                ? $provider->identify($code, SsoRoutes::callback($id), is_string($verifier) ? $verifier : null)
                : $provider->identify($code, SsoRoutes::callback($id));

            Log::debug('SSO provider identify step completed', [
                'provider' => $id,
                'subject_present' => $identity->id !== '',
                'email_present' => $identity->email !== null && $identity->email !== '',
                'email_verified' => $identity->emailVerified,
            ]);

            return $identity;
        } catch (SsoException $exception) {
            Log::warning('SSO provider identity lookup returned an SSO error', [
                'provider' => $id,
                'reason' => $exception->reason,
            ]);
            throw $exception;
        } catch (Throwable $throwable) {
            Log::error('SSO provider identity lookup failed', [
                'provider' => $id,
                'exception_class' => $throwable::class,
            ]);
            report($throwable);

            throw new SsoException(SsoException::PROVIDER);
        }
    }

    /**
     * @param Request
     * @param ExternalIdentity
     * @return RedirectResponse
     */
    private function completeLogin(Request $request, ExternalIdentity $identity): RedirectResponse
    {
        Log::debug('SSO panel login completion started', ['provider' => $identity->provider]);
        $result = $this->logins->complete($this->resolver->userFor($identity));
        if ($result->complete) {
            Log::info('SSO panel login completed', ['provider' => $identity->provider]);

            return redirect()->to($result->intended);
        }

        Log::debug('SSO panel login requires the panel checkpoint flow', ['provider' => $identity->provider]);
        $request->session()->put(self::CHECKPOINT, true);

        return redirect()->to('/auth/login?sso=checkpoint');
    }

    /**
     * @param Request
     * @param ExternalIdentity
     * @return RedirectResponse
     */
    private function completeLink(Request $request, ExternalIdentity $identity): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            Log::warning('SSO account connection failed because no panel user is authenticated', [
                'provider' => $identity->provider,
            ]);
            throw new SsoException(SsoException::STATE);
        }

        $this->resolver->link($user, $identity);

        return redirect()->to('/account/connections?'.http_build_query(['sso_linked' => $identity->provider]));
    }

    /**
     * @param string
     * @param string
     * @return RedirectResponse
     */
    private function failed(string $intent, string $reason): RedirectResponse
    {
        Log::notice('SSO flow redirected after failure', ['intent' => $intent, 'reason' => $reason]);
        $path = $intent === self::LINK ? '/account/connections' : '/auth/login';

        return redirect()->to($path.'?'.http_build_query(['sso_error' => $reason]));
    }
}
