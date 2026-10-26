<?php

declare(strict_types=1);

namespace Sso\Tests\SsoTest;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Pterodactyl\Contracts\Users\CompletesLogins;
use Pterodactyl\Events\ActivityLogged;
use Pterodactyl\Events\Auth\DirectLogin;
use Pterodactyl\Models\Extension;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Extensions\ExtensionManager;
use Pterodactyl\Services\Extensions\ExtensionProviderLoader;
use Pterodactyl\Services\Extensions\ExtensionRepository;
use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use RuntimeException;
use Sso\Contracts\IdentityProvider;
use Sso\Data\ExternalIdentity;
use Sso\Models\Identity;
use Sso\Pkce;
use Sso\ProviderRegistry;
use Symfony\Component\HttpFoundation\HeaderUtils;

use function pterodactylTestCase;

uses(IntegrationTestCase::class);

beforeEach(function (): void {
    config([
        'extensions.enabled' => true,
        'extensions.directory' => dirname(__DIR__, 2),
        'session.driver' => 'array',
    ]);
    Event::fake([DirectLogin::class, ActivityLogged::class]);
    Extension::query()->where('identifier', 'sso')->delete();
    Extension::query()->create(['identifier' => 'sso', 'version' => '1.1.0', 'enabled' => true]);
    $this->app->forgetInstance(ExtensionRepository::class);
    $this->app->forgetInstance(ExtensionProviderLoader::class);
    $this->app->forgetInstance(ExtensionManager::class);
    $this->app->make(ExtensionProviderLoader::class)->registerProviders($this->app->make(ExtensionRepository::class)->enabled());
    Route::getRoutes()->refreshNameLookups();
    if (! Schema::hasTable('sso_identities')) {
        (require dirname(__DIR__).'/database/migrations/2026_10_05_000000_create_sso_identities_table.php')->up();
    }

    $this->users = [];
    configure(['discord_enabled' => true, 'discord_client_id' => 'client-id', 'discord_client_secret' => 'client-secret']);
});

afterEach(function (): void {
    Identity::query()->delete();
    User::query()->whereIn('id', $this->users)->delete();
    $this->app->make(ExtensionRepository::class)->settings('sso')->forgetByPrefix('');
    Extension::query()->where('identifier', 'sso')->delete();
});

test('only enabled and configured providers are offered on the login page', function (): void {
    $this->getJson('/extensions/sso/providers')->assertOk()->assertExactJson(['data' => [
        ['id' => 'discord', 'name' => 'Discord', 'login_url' => '/extensions/sso/discord/redirect'],
    ]]);

    configure(['discord_client_id' => '']);
    $this->getJson('/extensions/sso/providers')->assertOk()->assertExactJson(['data' => []]);
    $this->get('/extensions/sso/discord/redirect')->assertRedirect('/auth/login?sso_error=unavailable');
});

test('starting a login sends the browser to discord with a state bound to the session', function (): void {
    $response = $this->get('/extensions/sso/discord/redirect');

    $location = $response->assertRedirect()->headers->get('Location');
    expect($location)->toStartWith('https://discord.com/oauth2/authorize?');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect($query)->toMatchArray([
        'response_type' => 'code',
        'client_id' => 'client-id',
        'redirect_uri' => url('/extensions/sso/discord/callback'),
        'scope' => 'identify email',
        'prompt' => 'none',
        'code_challenge_method' => 'S256',
    ]);
    expect($query['state'])->toBe(session('sso.flow.state'));
    expect(Pkce::isValidVerifier(session('sso.flow.code_verifier')))->toBeTrue();
    expect($query['code_challenge'])->toBe(Pkce::challenge(session('sso.flow.code_verifier')));
    expect(session('sso.flow'))->toMatchArray(['provider' => 'discord', 'intent' => 'login']);
});

test('a callback whose state does not match the session is refused', function (): void {
    fakeDiscord();
    $this->get('/extensions/sso/discord/redirect');

    $this->get('/extensions/sso/discord/callback?code=abc&state=forged')->assertRedirect('/auth/login?sso_error=state');
    $this->get('/extensions/sso/discord/callback?code=abc&state=forged')->assertRedirect('/auth/login?sso_error=state');
    Http::assertNothingSent();
    $this->assertGuest();
});

test('a linked discord account signs its user in and refreshes the stored profile', function (): void {
    $user = user();
    Identity::query()->create(['user_id' => $user->id, 'provider' => 'discord', 'provider_user_id' => '80351110224678912', 'name' => 'Old name']);
    fakeDiscord();

    callback($this)->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    $identity = Identity::query()->sole();
    expect($identity->name)->toBe('Nelly');
    expect($identity->avatar_url)->toBe('https://cdn.discordapp.com/avatars/80351110224678912/8342729096ea3675442027381ff50dfe.png');
    expect($identity->last_login_at)->not->toBeNull();
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://discord.com/api/oauth2/token'
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'abc'
        && $request['client_secret'] === 'client-secret'
        && $request['redirect_uri'] === url('/extensions/sso/discord/callback'));
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://discord.com/api/users/@me' && $request->hasHeader('Authorization', 'Bearer discord-token'));
});

test('an unlinked discord account is refused unless email linking is enabled and the email is verified', function (): void {
    $user = user(['email' => 'nelly@discord.test']);
    fakeDiscord();

    callback($this)->assertRedirect('/auth/login?sso_error=unlinked');
    $this->assertGuest();

    configure(['link_by_email' => true]);
    fakeDiscord(['verified' => false]);
    callback($this)->assertRedirect('/auth/login?sso_error=unlinked');
    $this->assertGuest();

    fakeDiscord();
    callback($this)->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
    expect(Identity::query()->sole()->only(['user_id', 'provider', 'provider_user_id', 'email']))->toBe([
        'user_id' => $user->id,
        'provider' => 'discord',
        'provider_user_id' => '80351110224678912',
        'email' => 'nelly@discord.test',
    ]);
});

test('auto-create only creates a panel account for a verified email', function (): void {
    configure(['auto_create_by_verified_email' => true]);
    fakeDiscord(['verified' => false]);

    callback($this)->assertRedirect('/auth/login?sso_error=unlinked');
    $this->assertGuest();

    fakeDiscord();
    callback($this)->assertRedirect('/');
    $this->assertAuthenticated();
    $this->users[] = auth()->user()->id;
    expect(auth()->user()->email)->toBe('nelly@discord.test');
    expect(Identity::query()->sole()->provider)->toBe('discord');
});

test('custom OIDC discovers endpoints from the issuer well-known document', function (): void {
    configure([
        'custom_enabled' => true,
        'custom_name' => 'Authentik',
        'custom_client_id' => 'custom-client',
        'custom_client_secret' => 'custom-secret',
        'custom_auto_fetch' => true,
        'custom_issuer_url' => 'https://auth.example.test/application/o/panel/',
    ]);
    Http::fake([
        'https://auth.example.test/application/o/panel/.well-known/openid-configuration' => Http::response([
            'issuer' => 'https://auth.example.test/application/o/panel/',
            'authorization_endpoint' => 'https://auth.example.test/application/o/authorize/',
            'token_endpoint' => 'https://auth.example.test/application/o/token/',
            'userinfo_endpoint' => 'https://auth.example.test/application/o/userinfo/',
        ]),
    ]);

    $this->getJson('/extensions/sso/providers')->assertOk()->assertJsonFragment([
        'id' => 'custom',
        'name' => 'Authentik',
        'login_url' => '/extensions/sso/custom/redirect',
    ]);

    $response = $this->get('/extensions/sso/custom/redirect')->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('https://auth.example.test/application/o/authorize/?');
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://auth.example.test/application/o/panel/.well-known/openid-configuration');
});

test('accounts with two factor authentication are handed to the native checkpoint', function (): void {
    $user = user(['use_totp' => true, 'totp_secret' => encrypt(str_repeat('a', 16))]);
    Identity::query()->create(['user_id' => $user->id, 'provider' => 'discord', 'provider_user_id' => '80351110224678912']);
    fakeDiscord();

    callback($this)->assertRedirect('/auth/login?sso=checkpoint');

    $this->assertGuest();
    $token = $this->getJson('/extensions/sso/checkpoint')->assertOk()->json('data.confirmation_token');
    expect($token)->toBe($this->app->make(CompletesLogins::class)->pendingCheckpoint()?->token);
});

test('the checkpoint token is only revealed for a sign-in this extension started', function (): void {
    $this->app->make(CompletesLogins::class)->complete(user(['use_totp' => true, 'totp_secret' => encrypt(str_repeat('a', 16))]));

    $this->getJson('/extensions/sso/checkpoint')->assertNotFound();
});

test('provider failures and cancelled consent return to the login page with a reason', function (): void {
    Http::fake(['discord.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
    callback($this)->assertRedirect('/auth/login?sso_error=provider');

    $this->get('/extensions/sso/discord/redirect');
    $this->get('/extensions/sso/discord/callback?error=access_denied&state='.session('sso.flow.state'))->assertRedirect('/auth/login?sso_error=denied');
    $this->assertGuest();
});

test('a signed in user can link a discord account that nobody else has linked', function (): void {
    $user = user();
    $other = user();
    fakeDiscord();

    $this->actingAs($user);
    callback($this, 'link')->assertRedirect('/account/connections?sso_linked=discord');
    expect(Identity::query()->sole()->user_id)->toBe($user->id);

    $this->actingAs($other);
    callback($this, 'link')->assertRedirect('/account/connections?sso_error=taken');
    expect(Identity::query()->count())->toBe(1);
});

test('guests cannot start linking an account', function (): void {
    $this->withHeader('Accept', 'text/html')->get('/extensions/sso/discord/link')->assertRedirect('/auth/login');
    $this->getJson('/extensions/sso/discord/link')->assertUnauthorized();
    expect(session()->has('sso.flow'))->toBeFalse();
});

test('users can list and remove their connections', function (): void {
    $user = user();
    Identity::query()->create(['user_id' => $user->id, 'provider' => 'discord', 'provider_user_id' => '1', 'name' => 'Nelly', 'email' => 'nelly@discord.test']);
    Identity::query()->create(['user_id' => user()->id, 'provider' => 'discord', 'provider_user_id' => '2']);

    $this->actingAs($user)->getJson('/api/client/extensions/sso/connections')->assertOk()->assertJsonPath('data.0', [
        'id' => 'discord',
        'name' => 'Discord',
        'enabled' => true,
        'link_url' => '/extensions/sso/discord/link',
        'account' => [
            'name' => 'Nelly',
            'email' => 'nelly@discord.test',
            'avatar_url' => null,
            'linked_at' => Identity::query()->where('user_id', $user->id)->sole()->created_at->toAtomString(),
        ],
    ]);

    $this->actingAs($user)->deleteJson('/api/client/extensions/sso/connections/discord')->assertNoContent();
    expect(Identity::query()->where('user_id', $user->id)->exists())->toBeFalse();
    expect(Identity::query()->count())->toBe(1);
});

test('PKCE S256 matches the RFC 7636 example', function (): void {
    expect(Pkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'))->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
});

test('the token request carries the PKCE verifier stored in the session', function (): void {
    $user = user();
    Identity::query()->create(['user_id' => $user->id, 'provider' => 'discord', 'provider_user_id' => '80351110224678912']);
    fakeDiscord();

    $this->get('/extensions/sso/discord/redirect');
    $verifier = session('sso.flow.code_verifier');
    $this->get('/extensions/sso/discord/callback?code=abc&state='.session('sso.flow.state'))->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://discord.com/api/oauth2/token' && $request['code_verifier'] === $verifier);
});

test('a login started before PKCE was enabled completes without a verifier', function (): void {
    $user = user();
    Identity::query()->create(['user_id' => $user->id, 'provider' => 'discord', 'provider_user_id' => '80351110224678912']);
    fakeDiscord();

    $this->get('/extensions/sso/discord/redirect');
    session()->forget('sso.flow.code_verifier');
    $this->get('/extensions/sso/discord/callback?code=abc&state='.session('sso.flow.state'))->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://discord.com/api/oauth2/token' && ! isset($request['code_verifier']));
});

test('a malformed PKCE verifier in the session is refused before contacting the provider', function (): void {
    fakeDiscord();

    $this->get('/extensions/sso/discord/redirect');
    session()->put('sso.flow.code_verifier', 'too-short');
    $this->get('/extensions/sso/discord/callback?code=abc&state='.session('sso.flow.state'))->assertRedirect('/auth/login?sso_error=state');

    Http::assertNothingSent();
    $this->assertGuest();
});

test('a provider that cannot build its authorization url returns to the login page without starting a flow', function (): void {
    app(ProviderRegistry::class)->register(BrokenProvider::class);

    $this->get('/extensions/sso/broken/redirect')->assertRedirect('/auth/login?sso_error=provider');

    expect(session()->has('sso.flow'))->toBeFalse();
});

test('starting a steam login sends the browser to steam with the state in the return url', function (): void {
    configure(['steam_enabled' => true]);

    $location = $this->get('/extensions/sso/steam/redirect')->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith('https://steamcommunity.com/openid/login?');
    $query = HeaderUtils::parseQuery((string) parse_url($location, PHP_URL_QUERY));
    expect($query)->toMatchArray([
        'openid.ns' => 'http://specs.openid.net/auth/2.0',
        'openid.mode' => 'checkid_setup',
        'openid.return_to' => url('/extensions/sso/steam/callback').'?state='.session('sso.flow.state'),
        'openid.realm' => url('/').'/',
        'openid.identity' => 'http://specs.openid.net/auth/2.0/identifier_select',
        'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
    ]);
    expect(session('sso.flow'))->toMatchArray(['provider' => 'steam', 'intent' => 'login', 'code_verifier' => null]);
});

test('a verified steam assertion signs in the linked user and stores the steam profile', function (): void {
    configure(['steam_enabled' => true, 'steam_api_key' => 'steam-key']);
    $user = user();
    Identity::query()->create(['user_id' => $user->id, 'provider' => 'steam', 'provider_user_id' => '76561197960435530']);
    fakeSteam();

    steamCallback($this)->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect(Identity::query()->sole()->only(['name', 'email', 'avatar_url']))->toBe([
        'name' => 'Robin',
        'email' => null,
        'avatar_url' => 'https://avatars.steamstatic.com/robin_full.jpg',
    ]);
    Http::assertSent(function (HttpRequest $request): bool {
        $body = HeaderUtils::parseQuery($request->body());

        return $request->url() === 'https://steamcommunity.com/openid/login'
            && $body['openid.mode'] === 'check_authentication'
            && $body['openid.sig'] === 'c2lnbmF0dXJl'
            && $body['openid.claimed_id'] === 'https://steamcommunity.com/openid/id/76561197960435530';
    });
    Http::assertSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/')
        && $request['key'] === 'steam-key'
        && $request['steamids'] === '76561197960435530');
});

test('a steam account can be linked without a web api key', function (): void {
    configure(['steam_enabled' => true]);
    $user = user();
    fakeSteam();

    $this->actingAs($user);
    steamCallback($this, intent: 'link')->assertRedirect('/account/connections?sso_linked=steam');

    expect(Identity::query()->sole()->only(['user_id', 'provider_user_id', 'name']))->toBe([
        'user_id' => $user->id,
        'provider_user_id' => '76561197960435530',
        'name' => '76561197960435530',
    ]);
    Http::assertSentCount(1);
});

test('an unlinked steam account is refused because steam shares no email', function (): void {
    configure(['steam_enabled' => true, 'link_by_email' => true]);
    fakeSteam();

    steamCallback($this)->assertRedirect('/auth/login?sso_error=unlinked');

    $this->assertGuest();
});

test('a steam assertion that steam does not confirm is refused', function (): void {
    configure(['steam_enabled' => true]);
    Identity::query()->create(['user_id' => user()->id, 'provider' => 'steam', 'provider_user_id' => '76561197960435530']);
    fakeSteam(false);

    steamCallback($this)->assertRedirect('/auth/login?sso_error=provider');

    $this->assertGuest();
});

test('a cancelled steam login returns to the login page', function (): void {
    configure(['steam_enabled' => true]);
    fakeSteam();

    $this->get('/extensions/sso/steam/redirect');
    $state = session('sso.flow.state');
    $this->get('/extensions/sso/steam/callback?'.http_build_query(['state' => $state, 'openid.ns' => 'http://specs.openid.net/auth/2.0', 'openid.mode' => 'cancel']))
        ->assertRedirect('/auth/login?sso_error=denied');

    Http::assertNothingSent();
});

test('a steam assertion issued for another login attempt is refused', function (): void {
    configure(['steam_enabled' => true]);
    fakeSteam();

    steamCallback($this, ['openid.return_to' => url('/extensions/sso/steam/callback').'?state=stolen'])->assertRedirect('/auth/login?sso_error=state');

    Http::assertNothingSent();
});

test('a steam assertion that is tampered with is refused before asking steam', function (array $overrides): void {
    configure(['steam_enabled' => true]);
    fakeSteam();

    steamCallback($this, $overrides)->assertRedirect('/auth/login?sso_error=provider');

    Http::assertNothingSent();
    $this->assertGuest();
})->with([
    'unsigned claimed id' => [['openid.signed' => 'signed,op_endpoint,identity,return_to,response_nonce,assoc_handle']],
    'foreign endpoint' => [['openid.op_endpoint' => 'https://steam.example/openid/login']],
    'foreign return url' => [['openid.return_to' => 'https://panel.example/extensions/sso/steam/callback?state=STATE']],
    'non steam identity' => [['openid.claimed_id' => 'https://openid.example/id/76561197960435530', 'openid.identity' => 'https://openid.example/id/76561197960435530']],
    'mismatched identity' => [['openid.identity' => 'https://steamcommunity.com/openid/id/76561197960435531']],
]);

/**
 * @param array
 */
function configure(array $values): void
{
    app(ExtensionSettingsRegistry::class)->get('sso')?->update($values);
}

/**
 * @param array
 * @return User
 */
function user(array $attributes = []): User
{
    return (function () use ($attributes): User {
        $user = User::factory()->create($attributes);
        $this->users[] = $user->id;

        return $user;
    })->call(pterodactylTestCase());
}

/**
 * @param array
 */
function fakeDiscord(array $user = []): void
{
    Http::swap(new Factory);
    Http::fake([
        'discord.com/api/oauth2/token' => Http::response(['access_token' => 'discord-token', 'token_type' => 'Bearer']),
        'discord.com/api/users/@me' => Http::response([
            'id' => '80351110224678912',
            'username' => 'nelly',
            'global_name' => 'Nelly',
            'avatar' => '8342729096ea3675442027381ff50dfe',
            'email' => 'nelly@discord.test',
            'verified' => true,
            ...$user,
        ]),
    ]);
}

/**
 * @param IntegrationTestCase
 * @param string
 * @return TestResponse
 */
function callback(IntegrationTestCase $test, string $intent = 'login'): TestResponse
{
    $test->get($intent === 'link' ? '/extensions/sso/discord/link' : '/extensions/sso/discord/redirect')->assertRedirect();

    return $test->get('/extensions/sso/discord/callback?code=abc&state='.session('sso.flow.state'));
}

/**
 * @param bool
 */
function fakeSteam(bool $valid = true): void
{
    Http::swap(new Factory);
    Http::fake([
        'steamcommunity.com/openid/login' => Http::response("ns:http://specs.openid.net/auth/2.0\nis_valid:".($valid ? 'true' : 'false')."\n"),
        'api.steampowered.com/*' => Http::response(['response' => ['players' => [[
            'steamid' => '76561197960435530',
            'personaname' => 'Robin',
            'avatarfull' => 'https://avatars.steamstatic.com/robin_full.jpg',
        ]]]]),
    ]);
}

/**
 * @param IntegrationTestCase
 * @param array
 * @param string
 * @return TestResponse
 */
function steamCallback(IntegrationTestCase $test, array $overrides = [], string $intent = 'login'): TestResponse
{
    $test->get($intent === 'link' ? '/extensions/sso/steam/link' : '/extensions/sso/steam/redirect')->assertRedirect();
    $state = session('sso.flow.state');
    $claimedId = 'https://steamcommunity.com/openid/id/76561197960435530';
    $assertion = array_map(fn (string $value): string => str_replace('STATE', $state, $value), [
        'openid.ns' => 'http://specs.openid.net/auth/2.0',
        'openid.mode' => 'id_res',
        'openid.op_endpoint' => 'https://steamcommunity.com/openid/login',
        'openid.claimed_id' => $claimedId,
        'openid.identity' => $claimedId,
        'openid.return_to' => url('/extensions/sso/steam/callback').'?state='.$state,
        'openid.response_nonce' => '2026-10-19T12:00:00Zr4nd0m',
        'openid.assoc_handle' => '1234567890',
        'openid.signed' => 'signed,op_endpoint,claimed_id,identity,return_to,response_nonce,assoc_handle',
        'openid.sig' => 'c2lnbmF0dXJl',
        ...$overrides,
    ]);

    return $test->get('/extensions/sso/steam/callback?'.http_build_query(['state' => $state, ...$assertion], '', '&', PHP_QUERY_RFC3986));
}

final class BrokenProvider implements IdentityProvider
{
    /**
     * @inheritdoc
     */
    public function id(): string
    {
        return 'broken';
    }

    /**
     * @inheritdoc
     */
    public function name(): string
    {
        return 'Broken';
    }

    /**
     * @inheritdoc
     */
    public function settings(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function enabled(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function authorizationUrl(string $state, string $redirectUri): string
    {
        throw new RuntimeException('Authorization endpoint is not configured.');
    }

    /**
     * @inheritdoc
     */
    public function identify(string $code, string $redirectUri): ExternalIdentity
    {
        throw new RuntimeException('Token endpoint is not configured.');
    }
}
