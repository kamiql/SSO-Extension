<?php

declare(strict_types=1);

namespace Sso;

use Illuminate\Support\Facades\File;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

final class SsoServiceProvider extends ExtensionProvider
{
    /**
     * @inheritdoc
     */
    public function register(): void
    {
        $this->app->singleton(ProviderRegistry::class);

        $registry = $this->app->make(ProviderRegistry::class);
        foreach ($this->identityProviders() as $provider) {
            $registry->register($provider);
        }

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ...$registry->settings(),
            ExtensionSettingDefinition::make(SsoSettings::LINK_BY_EMAIL, SsoSettings::LINK_BY_EMAIL, false, ['boolean'])
                ->label('Link accounts by verified email')
                ->help('Sign in a user whose panel email matches the verified email of an external account that is not linked yet, and link it. Only enable this for providers that verify email addresses.')
                ->field('toggle')
                ->normalizeUsing(fn (mixed $value): bool => $value === true),
            ExtensionSettingDefinition::make(SsoSettings::AUTO_CREATE_BY_VERIFIED_EMAIL, SsoSettings::AUTO_CREATE_BY_VERIFIED_EMAIL, false, ['boolean'])
                ->label('Auto-create accounts by verified email')
                ->help('Create a new panel account when a provider returns a verified email address that does not belong to an existing panel account. Only enable this for trusted providers that reliably verify email addresses.')
                ->field('toggle')
                ->normalizeUsing(fn (mixed $value): bool => $value === true),
        ]));
    }

    /**
     * @inheritdoc
     */
    public function boot(): void
    {
        $this->loadExtensionMigrations();
        $this->registerWebRoutes($this->extensionPath('routes', 'web.php'));
        $this->registerAuthenticatedWebRoutes($this->extensionPath('routes', 'account.php'));
        $this->registerApiRoutes();
    }

    /**
     * @return array
     */
    private function identityProviders(): array
    {
        $names = array_map('basename', File::directories($this->extensionPath('providers')));
        sort($names);

        return array_map(fn (string $name): string => 'Sso\\Providers\\'.$name.'\\'.$name.'Provider', $names);
    }
}
