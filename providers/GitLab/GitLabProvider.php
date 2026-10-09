<?php

declare(strict_types=1);

namespace Sso\Providers\GitLab;

use Sso\Data\ExternalIdentity;
use Sso\OAuth2Provider;

final class GitLabProvider extends OAuth2Provider
{
    /**
     * @inheritdoc
     */
    public function id(): string
    {
        return 'gitlab';
    }

    /**
     * @inheritdoc
     */
    public function name(): string
    {
        return 'GitLab';
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
        return 'https://gitlab.com/oauth/authorize';
    }

    /**
     * @inheritdoc
     */
    protected function tokenEndpoint(): string
    {
        return 'https://gitlab.com/oauth/token';
    }

    /**
     * @inheritdoc
     */
    protected function userEndpoint(): string
    {
        return 'https://gitlab.com/api/v4/user';
    }

    /**
     * @inheritdoc
     */
    protected function scopes(): array
    {
        return ['read_user'];
    }

    /**
     * @inheritdoc
     */
    protected function mapIdentity(array $user): ExternalIdentity
    {
        $username = is_string($user['username'] ?? null) ? $user['username'] : '';
        $name = $user['name'] ?? null;
        $email = $user['email'] ?? null;
        $avatar = $user['avatar_url'] ?? null;

        return new ExternalIdentity(
            $this->id(),
            $this->stringId($user['id'] ?? null),
            is_string($name) && $name !== '' ? $name : $username,
            is_string($email) && $email !== '' ? $email : null,
            is_string($user['confirmed_at'] ?? null) && $user['confirmed_at'] !== '',
            is_string($avatar) && $avatar !== '' ? $avatar : null,
        );
    }
}
