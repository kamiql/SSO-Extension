import type { ReactElement } from 'react';
import { NamedIcon } from '@pterodactyl/sdk';

export interface ProviderBrand {
    id: string;
    className: string;
    icon: ReactElement;
}

const brands = new Map(
    Object.values(import.meta.glob<{ default: ProviderBrand }>('../../providers/*/brand.tsx', { eager: true })).map(
        (module) => [module.default.id, module.default]
    )
);

const fallback: ProviderBrand = {
    id: '',
    className: 'sso:border-border sso:bg-card sso:text-foreground sso:hover:bg-accent/20',
    icon: <NamedIcon name={'log-in'} className={'sso:size-5'} />,
};

/**
 * @param {string} provider
 * @returns {ProviderBrand}
 */
export function brandOf(provider: string): ProviderBrand {
    return brands.get(provider) ?? fallback;
}
