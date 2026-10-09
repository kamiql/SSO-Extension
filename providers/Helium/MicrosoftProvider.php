<?php

declare(strict_types=1);

namespace Sso\Providers\Microsoft;

use Sso\Data\ExternalIdentity;
use Sso\OAuth2Provider;

final class MicrosoftProvider extends OAuth2Provider
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
     * Microsoft does not guarantee that the email it returns is verified, so it is
     * never marked verified and cannot be used to link accounts by email.
     *
     * @inheritdoc
     */
    protected function mapIdentity(array $user): ExternalIdentity
    {
        $id = $this->stringId($user['sub'] ?? null);
        $name = $user['preferred_username'] ?? null;
        $email = $user['email'] ?? null;
        $verified = $user['email_verified'] ?? false;

        return new ExternalIdentity(
            $this->id(),
            $id,
            is_string($name) && $name !== '' ? $name : $id,
            is_string($email) && $email !== '' ? $email : null,
            $verified,
            null,
        );
    }
}
