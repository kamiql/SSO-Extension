import type { ProviderBrand } from '../../src/client/brand';

export default {
    id: 'google',
    className: 'sso:border-[#dadce0] sso:bg-white sso:text-[#3c4043] sso:hover:bg-[#f1f3f4]',
    icon: (
        <svg viewBox={'0 0 24 24'} aria-hidden={'true'} className={'sso:size-5'}>
            <text
                x={'12'}
                y={'18'}
                textAnchor={'middle'}
                fontSize={'20'}
                fontWeight={'700'}
                fontFamily={'Arial, sans-serif'}
                fill={'#4285F4'}
            >
                G
            </text>
        </svg>
    ),
} satisfies ProviderBrand;
