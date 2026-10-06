import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { http, toast, type ExtensionSetupContext, type RouteSlotData } from '@pterodactyl/sdk';
import { createExtensionTestHost, type ExtensionTestHost } from '@pterodactyl/sdk/testing';
import extension from './index';
import LoginButtons from './LoginButtons';
import ConnectionsScreen from './ConnectionsScreen';
import type { SsoConnection } from './api';
import { brandOf } from './brand';

let host: ExtensionTestHost | undefined;
beforeEach(() => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
});
afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    vi.restoreAllMocks();
});

/**
 * @param {ReactNode} children
 * @param {string} path
 * @returns {ReturnType<typeof render>}
 */
function renderInHost(children: ReactNode, path: string) {
    host = createExtensionTestHost({ extensionId: 'sso', path, prefix: 'sso' });
    return render(children, { wrapper: host.Wrapper });
}

/**
 * @param {Record<string, unknown>} responses
 */
function respond(responses: Record<string, unknown>) {
    vi.spyOn(http, 'get').mockImplementation(async (url: string) => {
        if (!(url in responses)) throw new Error(`Unexpected request to ${url}`);
        return { data: { data: responses[url] } };
    });
}

/**
 * @param {Record<string, string>} search
 * @returns {RouteSlotData}
 */
function route(search: Record<string, string> = {}): RouteSlotData {
    return { pathname: '/auth/login', search, params: {} };
}

const discord: SsoConnection = {
    id: 'discord',
    name: 'Discord',
    enabled: true,
    link_url: '/extensions/sso/discord/link',
    account: null,
};

it('adds sign-in buttons to the login form and a connections page to the account area', () => {
    const context: ExtensionSetupContext = {
        meta: { id: 'sso' },
        config: {},
        slots: { register: vi.fn() },
        screens: { register: vi.fn() },
        columns: { register: vi.fn() },
        components: { replace: vi.fn() },
    };

    extension.setup(context);

    expect(context.slots.register).toHaveBeenCalledWith('auth.login.form.after', LoginButtons);
    expect(context.screens.register).toHaveBeenCalledWith('connections', expect.any(Function));
});

it('styles each provider from the brand in its own directory and others with a neutral button', () => {
    expect(brandOf('discord').id).toBe('discord');
    expect(brandOf('discord').className).toContain('sso:bg-[#5865F2]');
    expect(brandOf('unknown').id).toBe('');
});

it('offers every enabled provider as a link that starts its sign-in', async () => {
    respond({
        '/extensions/sso/providers': [{ id: 'discord', name: 'Discord', login_url: '/extensions/sso/discord/redirect' }],
    });
    renderInHost(<LoginButtons data={route()} />, '/auth/login');

    expect((await screen.findByRole('link', { name: 'Continue with Discord' })).getAttribute('href')).toBe(
        '/extensions/sso/discord/redirect'
    );
});

it('renders nothing on the login form when no provider is enabled', async () => {
    respond({ '/extensions/sso/providers': [] });
    const { container } = renderInHost(<LoginButtons data={route()} />, '/auth/login');

    await waitFor(() => expect(http.get).toHaveBeenCalled());
    expect(container.textContent).toBe('');
});

it('explains why a sign-in was sent back to the login page', async () => {
    respond({ '/extensions/sso/providers': [] });
    renderInHost(<LoginButtons data={route({ sso_error: 'unlinked' })} />, '/auth/login?sso_error=unlinked');

    expect(await screen.findByText(/No panel account is connected to that login/)).toBeTruthy();
});

it('continues a two factor sign-in on the native checkpoint screen', async () => {
    respond({
        '/extensions/sso/providers': [],
        '/extensions/sso/checkpoint': { confirmation_token: 'checkpoint-token' },
    });
    renderInHost(<LoginButtons data={route({ sso: 'checkpoint' })} />, '/auth/login?sso=checkpoint');

    await waitFor(() => expect(host!.location().pathname).toBe('/auth/login/checkpoint'));
    expect(host!.location().state).toMatchObject({ token: 'checkpoint-token' });
});

it('lists connections and lets the user connect or disconnect them', async () => {
    respond({
        '/api/client/extensions/sso/connections': [
            {
                ...discord,
                account: { name: 'Nelly', email: 'nelly@discord.test', avatar_url: null, linked_at: null },
            },
        ],
    });
    const remove = vi.spyOn(http, 'delete').mockResolvedValue({ data: '' });
    const success = vi.spyOn(toast, 'success');
    renderInHost(
        <ConnectionsScreen data={{ pathname: '/account/connections', search: { sso_linked: 'discord' }, params: {} }} />,
        '/account/connections'
    );

    expect(await screen.findByText('Nelly · nelly@discord.test')).toBeTruthy();
    expect(screen.getByText('Your Discord account is now connected.')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Disconnect' }));

    await waitFor(() => expect(success).toHaveBeenCalledWith('Discord has been disconnected.'));
    expect(remove).toHaveBeenCalledWith('/api/client/extensions/sso/connections/discord');
});

it('links to the provider for accounts that are not connected yet', async () => {
    respond({ '/api/client/extensions/sso/connections': [discord] });
    renderInHost(
        <ConnectionsScreen data={{ pathname: '/account/connections', search: {}, params: {} }} />,
        '/account/connections'
    );

    expect((await screen.findByRole('link', { name: 'Connect' })).getAttribute('href')).toBe(
        '/extensions/sso/discord/link'
    );
    expect(screen.getByText('Not connected')).toBeTruthy();
});
