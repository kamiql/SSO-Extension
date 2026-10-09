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
        return 'microsoft';
    }

    /**
     * @inheritdoc
     */
    public function name(): string
    {
        return 'Microsoft';
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
        return 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    }

    /**
     * @inheritdoc
     */
    protected function tokenEndpoint(): string
    {
        return 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    }

    /**
     * @inheritdoc
     */
    protected function userEndpoint(): string
    {
        return 'https://graph.microsoft.com/oidc/userinfo';
    }

    /**
     * @inheritdoc
     */
    protected function scopes(): array
    {
        return ['openid', 'email', 'profile'];
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
        $name = $user['name'] ?? null;
        $email = $user['email'] ?? null;

        return new ExternalIdentity(
            $this->id(),
            $id,
            is_string($name) && $name !== '' ? $name : $id,
            is_string($email) && $email !== '' ? $email : null,
            false,
            null,
        );
    }
}
