<?php

declare(strict_types=1);

namespace Sso\Providers\Google;

use Sso\Data\ExternalIdentity;
use Sso\OAuth2Provider;

final class GoogleProvider extends OAuth2Provider
{
    /**
     * @inheritdoc
     */
    public function id(): string
    {
        return 'google';
    }

    /**
     * @inheritdoc
     */
    public function name(): string
    {
        return 'Google';
    }

    /**
     * @inheritdoc
     */
    protected function authorizeEndpoint(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    /**
     * @inheritdoc
     */
    protected function tokenEndpoint(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    /**
     * @inheritdoc
     */
    protected function userEndpoint(): string
    {
        return 'https://openidconnect.googleapis.com/v1/userinfo';
    }

    /**
     * @inheritdoc
     */
    protected function scopes(): array
    {
        return ['openid', 'email', 'profile'];
    }

    /**
     * @inheritdoc
     */
    protected function authorizationParameters(): array
    {
        return ['prompt' => 'select_account'];
    }

    /**
     * @inheritdoc
     */
    protected function mapIdentity(array $user): ExternalIdentity
    {
        $id = $this->stringId($user['sub'] ?? null);
        $name = $user['name'] ?? null;
        $email = $user['email'] ?? null;
        $picture = $user['picture'] ?? null;

        return new ExternalIdentity(
            $this->id(),
            $id,
            is_string($name) && $name !== '' ? $name : $id,
            is_string($email) && $email !== '' ? $email : null,
            ($user['email_verified'] ?? false) === true,
            is_string($picture) && $picture !== '' ? $picture : null,
        );
    }
}
