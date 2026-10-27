# Single Sign-On

This is an extension only compatible with Pterodactyl 2.0.

Lets users sign in to the panel with an external account. Built as a module that can be expanded to add support for other third-parties.

**Included Providers:**
- Discord
- GitHub
- Google
- GitLab
- Microsoft
- Steam
- Custom OpenID Connect (for Authentik and other self-hosted OIDC providers)

## Account creation and email linking

Under **Admin > Extensions > Single Sign-On**, configure:

- **Link accounts by verified email** to link an external identity to an existing panel account with the same verified email address.
- **Auto-create accounts by verified email** to create a new panel account when a provider returns a verified email address that does not already belong to a panel account. New accounts receive a random password and a generated username; only enable this for trusted providers that reliably verify email addresses.

Both settings are disabled by default. Auto-creation does not silently attach an external identity to an existing account; use the separate linking setting for that behavior.

### Troubleshooting with debug logs

The extension writes structured SSO diagnostics to the Laravel application log. Temporarily set `LOG_LEVEL=debug` in the panel `.env`, clear cached configuration, and retry the login/link flow:

```sh
cd /var/www/pterodactyl
php artisan config:clear
tail -f storage/logs/laravel.log
```

Look for entries beginning with `SSO` or `Custom OIDC`. Logs report flow stages, whether the UserInfo response contains `sub`, `email`, and a strictly boolean `email_verified: true`, which account-linking/creation setting is active, and why account creation or linking was skipped. OAuth codes, tokens, secrets, raw claims, email addresses, and external subject IDs are not written by these diagnostics. Set `LOG_LEVEL` back to the previous value after troubleshooting and clear the configuration cache again.

## Custom OpenID Connect provider

The **Custom OIDC** settings tab supports one configurable self-hosted OIDC provider. Set its display name (for example, `Authentik`), client ID and secret, and choose one of these endpoint modes:

- **Fetch endpoints from .well-known** (default): enter the issuer URL. SSO reads its authorization, token and user-info endpoints from `<issuer>/.well-known/openid-configuration` and caches the document for 15 minutes.
- **Manual endpoints**: turn off discovery and enter the authorization, token and user-info endpoint URLs yourself.

Register the full panel callback URL (`https://<panel-origin>/extensions/sso/custom/callback`) at the identity provider. The provider requests the configured space-separated scopes and always includes `openid`. It expects standard OIDC user-info claims (`sub`, `email`, `email_verified`, `name` or `preferred_username`, and optionally `picture`).

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

If the user endpoint does not return everything `mapIdentity()` needs, override `enrich()`. It runs after the profile is fetched and before `mapIdentity()`. It receives the access token and the profile array, and returns the array that gets passed to `mapIdentity()`. By default it returns the profile unchanged.

GitHub uses it to fetch a verified email, because the email on the profile is optional and not verified:

```php
protected function enrich(string $token, array $user): array
{
    try {
        $emails = Http::acceptJson()->timeout(10)->withToken($token)->get('https://api.github.com/user/emails')->throw()->json();
    } catch (Throwable) {
        return $user;
    }

    foreach (is_array($emails) ? $emails : [] as $entry) {
        if (is_array($entry) && ($entry['primary'] ?? false) === true && ($entry['verified'] ?? false) === true && is_string($entry['email'] ?? null)) {
            return [...$user, 'sso_email' => $entry['email']];
        }
    }

    return $user;
}
```

Any exception thrown in `enrich()` fails the sign-in. If the extra data is optional, catch the exception and return `$user` as it is, like GitHub does.

OAuth 2 providers use PKCE (S256) by default: the authorization request carries a `code_challenge`, and the token request carries the matching `code_verifier`. If a provider rejects these parameters, override `usesPkce()` to return `false`.

A provider that does not extend `Sso\OAuth2Provider` implements `Sso\Contracts\IdentityProvider` directly. If it uses OAuth 2 and supports PKCE, implement `Sso\Contracts\SupportsPkce` instead, and the extension will generate the verifier and pass it in.

If the callback does not carry an OAuth 2 `code` query parameter, implement `Sso\Contracts\ReadsCallback` as well. Its `callbackCode()` receives the callback request after the state has been checked, and returns the string that is passed to `identify()` as `$code`, or `null` when the user cancelled. Steam uses it to pass on its OpenID 2.0 assertion, see `providers/Steam/SteamProvider.php`.

## Steam

Steam only offers OpenID 2.0, so it needs no client ID or secret: enabling it is enough. The optional Web API key (from https://steamcommunity.com/dev/apikey) is used to fetch the Steam display name and avatar; without one, the Steam ID is shown instead.

Steam does not share an email address, so "Link accounts by verified email" never applies to it. Users have to connect Steam from Account > Connections before they can sign in with it.

Without a `brand.tsx`, the provider gets a neutral button. With one, it exports its colours and logo, keyed by the provider's `id` (see `providers/Discord/brand.tsx`). Rebuild the frontend (`npm run build`) after adding or changing a brand.