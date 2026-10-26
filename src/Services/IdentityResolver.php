<?php

declare(strict_types=1);

namespace Sso\Services;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
        $created = false;
        if (! $user instanceof User) {
            $user = $this->createByVerifiedEmail($identity);
            $created = $user instanceof User;
        }

        if (! $user instanceof User || (! $created && Identity::query()->where('user_id', $user->id)->where('provider', $identity->provider)->exists())) {
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
     * Create a panel user only when the identity contains a verified, valid email
     * that is not already owned by an existing panel account.
     *
     * @param ExternalIdentity
     * @return User|null
     */
    private function createByVerifiedEmail(ExternalIdentity $identity): ?User
    {
        $email = $identity->email;
        if (! $this->config->boolean(SsoSettings::AUTO_CREATE_BY_VERIFIED_EMAIL)
            || ! $identity->emailVerified
            || $email === null
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || User::query()->where('email', $email)->exists()) {
            return null;
        }

        $local = explode('@', $email, 2)[0];
        $base = Str::lower((string) preg_replace('/[^a-z0-9_-]+/i', '', $local));
        $base = trim(substr($base, 0, 40), '-_');
        if ($base === '') {
            $base = 'user';
        }

        $username = $base;
        for ($suffix = 1; User::query()->where('username', $username)->exists(); $suffix++) {
            $username = substr($base, 0, 40).'-'.$suffix;
        }

        $user = new User();
        $attributes = [
            'username' => $username,
            'email' => $email,
            'password' => Hash::make(Str::random(64)),
            'language' => config('app.locale', 'en'),
            'root_admin' => false,
            'use_totp' => false,
        ];

        $displayName = preg_split('/\s+/', trim($identity->name), 2) ?: [];
        $schema = $user->getConnection()->getSchemaBuilder();
        if ($schema->hasColumn($user->getTable(), 'email_verified_at')) {
            $attributes['email_verified_at'] = now();
        }

        foreach ([['name_first', $displayName[0] ?? null], ['name_last', $displayName[1] ?? null], ['first_name', $displayName[0] ?? null], ['last_name', $displayName[1] ?? null]] as [$column, $value]) {
            if ($value !== null && $schema->hasColumn($user->getTable(), $column)) {
                $attributes[$column] = $value;
            }
        }

        $user->forceFill($attributes)->save();

        return $user;
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
