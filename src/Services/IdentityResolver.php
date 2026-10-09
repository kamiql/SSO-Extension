<?php

declare(strict_types=1);

namespace Sso\Services;

use Pterodactyl\Contracts\Users\CreatesUsers;
use Pterodactyl\Models\User;
use Sso\Data\ExternalIdentity;
use Sso\Exceptions\SsoException;
use Sso\Models\Identity;
use Sso\SsoSettings;

final class IdentityResolver
{
    protected SsoSettings $config;

    protected CreatesUsers $users;

    public function __construct(SsoSettings $config, CreatesUsers $users)
    {
        $this->config = $config;
        $this->users = $users;
    }

    public function userFor(ExternalIdentity $identity): User
    {
        // Bereits verknüpfte Identität: bestehenden Account verwenden.
        $linked = $this->find($identity);

        if ($linked instanceof Identity) {
            $linked->fill([
                ...$identity->details(),
                'last_login_at' => now(),
            ])->save();

            return $linked->user;
        }

        $email = mb_strtolower(trim($identity->email ?? ''));

        if (
            ! $identity->emailVerified
            || $email === ''
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new SsoException(SsoException::UNLINKED);
        }

        // Immer erst nach einem bestehenden Account suchen, damit kein Duplikat
        // erzeugt wird, wenn LINK_BY_EMAIL deaktiviert ist.
        $user = User::query()->where('email', $email)->first();

        if ($user instanceof User) {
            if (! $this->config->boolean(SsoSettings::LINK_BY_EMAIL)) {
                throw new SsoException(SsoException::UNLINKED);
            }
        } else {
            if (! $this->config->boolean(SsoSettings::AUTO_CREATE_USERS)) {
                throw new SsoException(SsoException::UNLINKED);
            }

            $user = $this->createPanelUser($identity, $email);
        }

        // Sowohl den per E-Mail gefundenen als auch den neu angelegten User
        // mit der externen Provider-Identität verknüpfen.
        Identity::query()->create([
            ...$identity->details(),
            'email' => $email,
            'user_id' => $user->id,
            'provider' => $identity->provider,
            'provider_user_id' => $identity->id,
            'last_login_at' => now(),
        ]);

        return $user;
    }

    public function link(User $user, ExternalIdentity $identity): Identity
    {
        $owner = $this->find($identity);

        if ($owner instanceof Identity && $owner->user_id !== $user->id) {
            throw new SsoException(SsoException::TAKEN);
        }

        return Identity::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'provider' => $identity->provider,
            ],
            [
                ...$identity->details(),
                'provider_user_id' => $identity->id,
            ],
        );
    }

    private function find(ExternalIdentity $identity): ?Identity
    {
        return Identity::query()
            ->where('provider', $identity->provider)
            ->where('provider_user_id', $identity->id)
            ->first();
    }

    private function createPanelUser(ExternalIdentity $identity, string $email): User
    {
        $fullName = trim($identity->name ?? '');
        $nameParts = preg_split('/\s+/u', $fullName, 2) ?: [];

        $firstName = trim($identity->firstName ?? '');
        if ($firstName === '') {
            $firstName = trim($nameParts[0] ?? '');
        }
        if ($firstName === '') {
            $firstName = 'SSO';
        }

        $lastName = trim($identity->lastName ?? '');
        if ($lastName === '') {
            $lastName = trim($nameParts[1] ?? '');
        }
        if ($lastName === '') {
            $lastName = 'User';
        }

        $localPart = explode('@', $email, 2)[0];
        $baseUsername = preg_replace('/[^a-z0-9_]/i', '_', $localPart) ?? '';
        $baseUsername = trim(substr($baseUsername, 0, 191), '_');

        if ($baseUsername === '') {
            $baseUsername = 'sso_user';
        }

        $username = $baseUsername;
        $suffix = 2;

        while (User::query()->where('username', $username)->exists()) {
            $suffixText = '_' . $suffix++;
            $username = substr($baseUsername, 0, 191 - strlen($suffixText)) . $suffixText;
        }

        return $this->users->create([
            'email' => $email,
            'username' => $username,
            'name_first' => mb_substr($firstName, 0, 191),
            'name_last' => mb_substr($lastName, 0, 191),
            'root_admin' => false,
            // Kein Passwort übergeben: Pterodactyl generiert ein zufälliges.
        ]);
    }
}