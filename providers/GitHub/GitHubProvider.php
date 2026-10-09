<?php

declare(strict_types=1);

namespace Sso\Providers\GitHub;

use Illuminate\Support\Facades\Http;
use Sso\Data\ExternalIdentity;
use Sso\OAuth2Provider;
use Throwable;

final class GitHubProvider extends OAuth2Provider
{
    /**
     * @inheritdoc
     */
    public function id(): string
    {
        return 'github';
    }

    /**
     * @inheritdoc
     */
    public function name(): string
    {
        return 'GitHub';
    }

    /**
     * @inheritdoc
     */
    public function usesPkce(): bool {
        return false;
    }

    /**
     * @inheritdoc
     */
    protected function authorizeEndpoint(): string
    {
        return 'https://github.com/login/oauth/authorize';
    }

    /**
     * @inheritdoc
     */
    protected function tokenEndpoint(): string
    {
        return 'https://github.com/login/oauth/access_token';
    }

    /**
     * @inheritdoc
     */
    protected function userEndpoint(): string
    {
        return 'https://api.github.com/user';
    }

    /**
     * @inheritdoc
     */
    protected function scopes(): array
    {
        return ['read:user', 'user:email'];
    }

    /**
     * The public profile email is optional and unverified, so ask GitHub for the
     * primary email and only trust it when GitHub says it is verified.
     *
     * @inheritdoc
     */
    protected function enrich(string $token, array $user): array
    {
        try {
            $emails = Http::acceptJson()->timeout(10)->withToken($token)->get('https://api.github.com/user/emails')->throw()->json();
        } catch (Throwable) {
            return $user;
        }

        foreach (is_array($emails) ? $emails : [] as $entry) {
            if (is_array($entry) && ($entry['primary'] ?? false) === true && ($entry['verified'] ?? false) === true && is_string($entry['email'] ?? null)) {
                return [...$user, 'sso_email' => $entry['email']];
            }
        }

        return $user;
    }

    /**
     * @inheritdoc
     */
    protected function mapIdentity(array $user): ExternalIdentity
    {
        $login = is_string($user['login'] ?? null) ? $user['login'] : '';
        $name = $user['name'] ?? null;
        $avatar = $user['avatar_url'] ?? null;
        $email = $user['sso_email'] ?? null;

        return new ExternalIdentity(
            $this->id(),
            $this->stringId($user['id'] ?? null),
            is_string($name) && $name !== '' ? $name : $login,
            is_string($email) && $email !== '' ? $email : null,
            is_string($email) && $email !== '',
            is_string($avatar) && $avatar !== '' ? $avatar : null,
        );
    }
}
