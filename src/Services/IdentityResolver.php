<?php

declare(strict_types=1);

namespace Sso\Services;

use Pterodactyl\Models\User;
use Sso\Data\ExternalIdentity;
use Sso\Exceptions\SsoException;
use Sso\Models\Identity;
use Sso\SsoSettings;

final class IdentityResolver
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
     * @param ExternalIdentity
     * @return User
     */
    public function userFor(ExternalIdentity $identity): User
    {
        $linked = $this->find($identity);
        if ($linked instanceof Identity) {
            $linked->fill([...$identity->details(), 'last_login_at' => now()])->save();

            return $linked->user;
        }

        $user = $this->userByVerifiedEmail($identity);
        if (! $user instanceof User || Identity::query()->where('user_id', $user->id)->where('provider', $identity->provider)->exists()) {
            throw new SsoException(SsoException::UNLINKED);
        }

        Identity::query()->create([
            ...$identity->details(),
            'user_id' => $user->id,
            'provider' => $identity->provider,
            'provider_user_id' => $identity->id,
            'last_login_at' => now(),
        ]);

        return $user;
    }

    /**
     * @param User
     * @param ExternalIdentity
     * @return Identity
     */
    public function link(User $user, ExternalIdentity $identity): Identity
    {
        $owner = $this->find($identity);
        if ($owner instanceof Identity && $owner->user_id !== $user->id) {
            throw new SsoException(SsoException::TAKEN);
        }

        return Identity::query()->updateOrCreate(
            ['user_id' => $user->id, 'provider' => $identity->provider],
            [...$identity->details(), 'provider_user_id' => $identity->id],
        );
    }

    /**
     * @param ExternalIdentity
     * @return Identity|null
     */
    private function find(ExternalIdentity $identity): ?Identity
    {
        return Identity::query()
            ->where('provider', $identity->provider)
            ->where('provider_user_id', $identity->id)
            ->first();
    }

    /**
     * @param ExternalIdentity
     * @return User|null
     */
    private function userByVerifiedEmail(ExternalIdentity $identity): ?User
    {
        if (! $this->config->boolean(SsoSettings::LINK_BY_EMAIL) || ! $identity->emailVerified || $identity->email === null) {
            return null;
        }

        return User::query()->where('email', $identity->email)->first();
    }
}
