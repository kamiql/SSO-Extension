<?php

declare(strict_types=1);

namespace Sso\Services;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
        Log::debug('SSO identity resolution started', [
            ...$this->identityContext($identity),
            'link_by_email_enabled' => $this->config->boolean(SsoSettings::LINK_BY_EMAIL),
            'auto_create_enabled' => $this->config->boolean(SsoSettings::AUTO_CREATE_BY_VERIFIED_EMAIL),
        ]);

        $linked = $this->find($identity);
        if ($linked instanceof Identity) {
            Log::debug('SSO identity matched an existing connection', [
                'provider' => $identity->provider,
            ]);
            $linked->fill([...$identity->details(), 'last_login_at' => now()])->save();

            return $linked->user;
        }

        Log::debug('SSO identity has no existing connection', ['provider' => $identity->provider]);

        $user = $this->userByVerifiedEmail($identity);
        $created = false;
        if (! $user instanceof User) {
            Log::debug('SSO email linking did not resolve an account; checking account creation', [
                'provider' => $identity->provider,
            ]);
            $user = $this->createByVerifiedEmail($identity);
            $created = $user instanceof User;
        }

        if (! $user instanceof User) {
            Log::warning('SSO identity rejected because no panel account was resolved', [
                ...$this->identityContext($identity),
                'link_by_email_enabled' => $this->config->boolean(SsoSettings::LINK_BY_EMAIL),
                'auto_create_enabled' => $this->config->boolean(SsoSettings::AUTO_CREATE_BY_VERIFIED_EMAIL),
            ]);
            throw new SsoException(SsoException::UNLINKED);
        }

        if (! $created && Identity::query()->where('user_id', $user->id)->where('provider', $identity->provider)->exists()) {
            Log::warning('SSO identity rejected because this panel account already has a connection for the provider', [
                'provider' => $identity->provider,
                'resolution' => 'verified_email_match',
            ]);
            throw new SsoException(SsoException::UNLINKED);
        }

        Identity::query()->create([
            ...$identity->details(),
            'user_id' => $user->id,
            'provider' => $identity->provider,
            'provider_user_id' => $identity->id,
            'last_login_at' => now(),
        ]);

        Log::info('SSO identity connected to a panel account', [
            'provider' => $identity->provider,
            'account_created' => $created,
            'resolution' => $created ? 'new_account' : 'verified_email_match',
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
        Log::debug('SSO account connection requested', [
            ...$this->identityContext($identity),
            'external_identity_already_connected' => $owner instanceof Identity,
            'connected_to_current_account' => $owner instanceof Identity && $owner->user_id === $user->id,
        ]);

        if ($owner instanceof Identity && $owner->user_id !== $user->id) {
            Log::warning('SSO account connection rejected because the external identity belongs to another panel account', [
                'provider' => $identity->provider,
            ]);
            throw new SsoException(SsoException::TAKEN);
        }

        $connection = Identity::query()->updateOrCreate(
            ['user_id' => $user->id, 'provider' => $identity->provider],
            [...$identity->details(), 'provider_user_id' => $identity->id],
        );

        Log::info('SSO account connection saved', ['provider' => $identity->provider]);

        return $connection;
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
        $emailValid = $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        $reason = match (true) {
            ! $this->config->boolean(SsoSettings::AUTO_CREATE_BY_VERIFIED_EMAIL) => 'auto_create_disabled',
            ! $identity->emailVerified => 'email_not_verified',
            $email === null => 'email_missing',
            ! $emailValid => 'email_invalid',
            User::query()->where('email', $email)->exists() => 'email_already_in_use',
            default => null,
        };

        if ($reason !== null) {
            Log::debug('SSO automatic account creation skipped', [
                ...$this->identityContext($identity),
                'reason' => $reason,
                'auto_create_enabled' => $this->config->boolean(SsoSettings::AUTO_CREATE_BY_VERIFIED_EMAIL),
            ]);

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

        Log::info('SSO panel account created from a verified email', [
            'provider' => $identity->provider,
            'email_verified' => $identity->emailVerified,
        ]);

        return $user;
    }

    /**
     * @param ExternalIdentity
     * @return User|null
     */
    private function userByVerifiedEmail(ExternalIdentity $identity): ?User
    {
        $reason = match (true) {
            ! $this->config->boolean(SsoSettings::LINK_BY_EMAIL) => 'link_by_email_disabled',
            ! $identity->emailVerified => 'email_not_verified',
            $identity->email === null => 'email_missing',
            default => null,
        };

        if ($reason !== null) {
            Log::debug('SSO verified-email account linking skipped', [
                ...$this->identityContext($identity),
                'reason' => $reason,
                'link_by_email_enabled' => $this->config->boolean(SsoSettings::LINK_BY_EMAIL),
            ]);

            return null;
        }

        $user = User::query()->where('email', $identity->email)->first();
        Log::debug('SSO verified-email account lookup completed', [
            ...$this->identityContext($identity),
            'panel_account_found' => $user instanceof User,
        ]);

        return $user;
    }

    /**
     * Log identity properties useful for troubleshooting without writing personal
     * values, OAuth credentials, access tokens, or provider subject IDs to logs.
     *
     * @param ExternalIdentity
     * @return array<string, bool|string>
     */
    private function identityContext(ExternalIdentity $identity): array
    {
        return [
            'provider' => $identity->provider,
            'subject_present' => $identity->id !== '',
            'email_present' => $identity->email !== null && $identity->email !== '',
            'email_verified' => $identity->emailVerified,
            'email_valid' => $identity->email !== null && filter_var($identity->email, FILTER_VALIDATE_EMAIL) !== false,
        ];
    }
}
