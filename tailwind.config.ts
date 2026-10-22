import type { Config } from 'tailwindcss';

// Shared navy, amber, blue-gray and cream palette supplied for IskoReach.
export default {
    theme: {
        extend: {
            keyframes: {
                'landing-reveal': {
                    from: { opacity: '0', transform: 'translateY(16px)' },
                    to: { opacity: '1', transform: 'translateY(0)' },
                },
            },
            animation: {
                'landing-reveal': 'landing-reveal 600ms cubic-bezier(0.22, 1, 0.36, 1) both',
            },
            colors: {
                civic: {
                    navy: '#123563', 'navy-soft': '#566e87', teal: '#123563',
                    'teal-hover': '#234b77', mint: '#ffe7ad', canvas: '#f5f8f8',
                    panel: '#edf2f5', 'panel-strong': '#a3b6c9', ink: '#123563',
                    muted: '#4d6278', border: '#a3b6c9', control: '#566e87',
                    gold: '#fbad23', 'gold-soft': '#e5bd69', cream: '#ffe7ad',
                    'blue-gray': '#a3b6c9', slate: '#566e87',
                    success: '#065f46', warning: '#92400e', danger: '#93000a',
                },
                neutral: {
                    50: '#f8fafc', 100: '#f1f5f9', 200: '#e2e8f0',
                    400: '#94a3b8', 600: '#475569', 900: '#0f172a',
                },
            },
            fontFamily: {
                stitch: ['"Plus Jakarta Sans"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
            },
            fontSize: {
                'civic-display': ['48px', { lineHeight: '56px', letterSpacing: '-0.02em', fontWeight: '800' }],
                'civic-display-mobile': ['36px', { lineHeight: '44px', letterSpacing: '-0.02em', fontWeight: '800' }],
                'civic-heading': ['32px', { lineHeight: '40px', letterSpacing: '-0.015em', fontWeight: '700' }],
                'civic-heading-mobile': ['26px', { lineHeight: '34px', letterSpacing: '-0.01em', fontWeight: '700' }],
                'civic-body': ['16px', { lineHeight: '26px' }],
                'civic-small': ['14px', { lineHeight: '22px' }],
            },
            boxShadow: {
                'civic-card': '0 1px 3px rgb(15 23 42 / 0.05), 0 1px 2px rgb(15 23 42 / 0.03)',
                'civic-raised': '0 10px 20px -5px rgb(15 23 42 / 0.10)',
                'civic-overlay': '0 20px 35px -10px rgb(15 23 42 / 0.18)',
            },
        },
    },
} satisfies Config;
