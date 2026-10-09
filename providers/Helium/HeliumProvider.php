<?php

declare(strict_types=1);

namespace Sso\Providers\Helium;

use Sso\Data\ExternalIdentity;
use Sso\Exceptions\SsoException;
use Sso\OAuth2Provider;

final class HeliumProvider extends OAuth2Provider
{
    /**
     * @inheritdoc
     */
    public function id(): string
    {
        return 'helium';
    }

    /**
     * @inheritdoc
     */
    public function name(): string
    {
        return 'Helium';
    }

    /**
     * @inheritdoc
     */
    protected function authorizeEndpoint(): string
    {
        return 'https://id.kamiql.de/oauth2/authorize';
    }

    /**
     * @inheritdoc
     */
    protected function tokenEndpoint(): string
    {
        return 'https://id.kamiql.de/oauth2/token';
    }

    /**
     * @inheritdoc
     */
    protected function userEndpoint(): string
    {
        return 'https://id.kamiql.de/userinfo';
    }

    /**
     * @inheritdoc
     */
    protected function scopes(): array
    {
        return ['openid', 'email', 'profile', 'offline_access'];
    }

    /**
     * @inheritdoc
     */
    protected function authorizationParameters(): array
    {
        return ['prompt' => 'none'];
    }

    /**
     * @inheritdoc
     */
    protected function mapIdentity(array $user): ExternalIdentity
    {
        $id = $user['id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new SsoException(SsoException::PROVIDER);
        }

        $username = is_string($user['preferred_username'] ?? null) ? $user['preferred_username'] : $id;
        $email = $user['email'] ?? null;

        return new ExternalIdentity(
            $this->id(),
            $id,
            $username,
            is_string($email) && $email !== '' ? $email : null,
            ($user['email_verified'] ?? false) === true,
            null
        );
    }
}
