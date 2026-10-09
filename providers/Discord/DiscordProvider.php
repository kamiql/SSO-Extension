<?php

declare(strict_types=1);

namespace Sso\Providers\Discord;

use Sso\Data\ExternalIdentity;
use Sso\Exceptions\SsoException;
use Sso\OAuth2Provider;

final class DiscordProvider extends OAuth2Provider
{
    /**
     * @inheritdoc
     */
    public function id(): string
    {
        return 'discord';
    }

    /**
     * @inheritdoc
     */
    public function name(): string
    {
        return 'Discord';
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
        return 'https://discord.com/oauth2/authorize';
    }

    /**
     * @inheritdoc
     */
    protected function tokenEndpoint(): string
    {
        return 'https://discord.com/api/oauth2/token';
    }

    /**
     * @inheritdoc
     */
    protected function userEndpoint(): string
    {
        return 'https://discord.com/api/users/@me';
    }

    /**
     * @inheritdoc
     */
    protected function scopes(): array
    {
        return ['identify', 'email'];
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

        $username = is_string($user['username'] ?? null) ? $user['username'] : $id;
        $globalName = $user['global_name'] ?? null;
        $avatar = $user['avatar'] ?? null;
        $email = $user['email'] ?? null;

        return new ExternalIdentity(
            $this->id(),
            $id,
            is_string($globalName) && $globalName !== '' ? $globalName : $username,
            is_string($email) && $email !== '' ? $email : null,
            ($user['verified'] ?? false) === true,
            is_string($avatar) && $avatar !== '' ? sprintf('https://cdn.discordapp.com/avatars/%s/%s.png', $id, $avatar) : null,
        );
    }
}
