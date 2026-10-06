# Single Sign-On

This is an extension only compatible with Pterodactyl 2.0.

Lets users sign in to the panel with an external account. Built as a module that can be expanded to add support for other third-parties.

**Included Providers:**
- Discord

## Install

Download [sso.pteroext](https://github.com/pterodactyl/SSO-Extension/releases/latest/download/sso.pteroext)
from the latest release, then on the panel host, either run:

```sh
php artisan p:extension:install /path/to/sso.zip --enable
```

Or install the extension using the admin panel under Admin > Extensions.

You must then reload the panel, then you can find the configuration under Admin > Extensions > Single Sign-On.

## Adding a provider

Each provider lives in its own directory under `providers/`. The extension discovers them; there is no list to edit.

```
providers/GitHub/
  GitHubProvider.php   # class Sso\Providers\GitHub\GitHubProvider
  brand.tsx            # optional: button colours and logo
```

Most providers use OAuth 2. For those, extend `Sso\OAuth2Provider` and fill in the endpoints, scopes and mapping:

```php
namespace Sso\Providers\GitHub;

final class GitHubProvider extends OAuth2Provider
{
    public function id(): string { return 'github'; }
    public function name(): string { return 'GitHub'; }
    protected function authorizeEndpoint(): string { return 'https://github.com/login/oauth/authorize'; }
    protected function tokenEndpoint(): string { return 'https://github.com/login/oauth/access_token'; }
    protected function userEndpoint(): string { return 'https://api.github.com/user'; }
    protected function scopes(): array { return ['read:user', 'user:email']; }
    protected function mapIdentity(array $user): ExternalIdentity { /* ... */ }
}
```

The directory name, the namespace segment and the class prefix must match (`providers/GitHub` → `Sso\Providers\GitHub\GitHubProvider`). The enable, client ID and client secret settings, the routes, the login button and the Connections row all come from that class.

A provider that does not use OAuth 2 implements `Sso\Contracts\IdentityProvider` directly.

Without a `brand.tsx`, the provider gets a neutral button. With one, it exports its colours and logo, keyed by the provider's `id` (see `providers/Discord/brand.tsx`). Rebuild the frontend (`npm run build`) after adding or changing a brand.