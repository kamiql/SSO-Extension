import type { ProviderBrand } from '../../src/client/brand';

export default {
    id: 'helium',
    className: 'sso:border-[#f3f4f6] sso:bg-[#f3f4f6] sso:text-[#5e5e5e] sso:hover:border-[#e5e7eb] sso:hover:bg-[#e5e7eb]',
    icon: (
        <svg viewBox={'0 0 23 23'} aria-hidden={'true'} className={'sso:size-5'}>
            <rect width="512" height="512" rx="112" fill="#0B5FA5"/>
            <text x="90" y="145"
                    font-family="Arial, Helvetica, sans-serif"
                    font-size="64" font-weight="700" fill="#FFFFFF">2</text>
            <text x="256" y="340" text-anchor="middle"
                    font-family="Arial, Helvetica, sans-serif"
                    font-size="220" font-weight="700" letter-spacing="-12"
                    fill="#FFFFFF">He</text>
        </svg>
    ),
} satisfies ProviderBrand;
