import type { ProviderBrand } from '../../src/client/brand';

export default {
    id: 'gitlab',
    className: 'sso:border-[#fc6d26] sso:bg-[#fc6d26] sso:text-white sso:hover:bg-[#e24329]',
    icon: (
        <svg viewBox={'0 0 24 24'} aria-hidden={'true'} className={'sso:size-5 sso:fill-current'}>
            <path d={'M12 22.5 1.6 14.9 4 7.4l2.3 7.1h11.4L20 7.4l2.4 7.5z'} />
        </svg>
    ),
} satisfies ProviderBrand;
