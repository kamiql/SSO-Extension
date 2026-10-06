import { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Alert, Spinner, useOpenLoginCheckpoint, type RouteSlotData } from '@pterodactyl/sdk';
import { brandOf } from './brand';
import { SSO_ERRORS, checkpointQueryOptions, providersQueryOptions, searchParam } from './api';

/**
 * @param {{ data: RouteSlotData }} props
 * @returns {JSX.Element | null}
 */
export default function LoginButtons({ data }: { data: RouteSlotData }) {
    const awaitingCheckpoint = searchParam(data.search, 'sso') === 'checkpoint';
    const error = searchParam(data.search, 'sso_error');
    const providers = useQuery(providersQueryOptions());
    const checkpoint = useQuery({ ...checkpointQueryOptions(), enabled: awaitingCheckpoint });
    const openCheckpoint = useOpenLoginCheckpoint();

    useEffect(() => {
        if (checkpoint.data) void openCheckpoint(checkpoint.data);
    }, [checkpoint.data, openCheckpoint]);

    const message = checkpoint.isError ? SSO_ERRORS.state : error ? (SSO_ERRORS[error] ?? SSO_ERRORS.provider) : null;
    const available = providers.data ?? [];

    if (awaitingCheckpoint && !checkpoint.isError) {
        return (
            <div className={'sso:mt-6 sso:flex sso:justify-center'}>
                <Spinner size={'small'} />
            </div>
        );
    }

    if (available.length === 0 && !message) return null;

    return (
        <div className={'sso:mt-6 sso:flex sso:flex-col sso:gap-3'}>
            {message && <Alert type={'danger'}>{message}</Alert>}
            {available.length > 0 && (
                <div
                    className={
                        'sso:flex sso:items-center sso:gap-3 sso:text-xs sso:uppercase sso:tracking-wide sso:text-muted-foreground'
                    }
                >
                    <span className={'sso:h-px sso:flex-1 sso:bg-border'} />
                    or
                    <span className={'sso:h-px sso:flex-1 sso:bg-border'} />
                </div>
            )}
            {available.map((provider) => {
                const brand = brandOf(provider.id);
                return (
                    <a
                        key={provider.id}
                        href={provider.login_url}
                        className={`sso:flex sso:w-full sso:items-center sso:justify-center sso:gap-2 sso:rounded-sm sso:border sso:p-4 sso:text-sm sso:uppercase sso:tracking-wide sso:no-underline sso:transition-colors ${brand.className}`}
                    >
                        {brand.icon}
                        Continue with {provider.name}
                    </a>
                );
            })}
        </div>
    );
}
