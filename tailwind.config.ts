import type { Config } from 'tailwindcss';

// Values come from the supplied Stitch HTML; functional colors extend that palette.
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
                    navy: '#001428', 'navy-soft': '#0f2942', teal: '#006a61',
                    'teal-hover': '#005049', mint: '#86f2e4', canvas: '#f8f9ff',
                    panel: '#eff4ff', 'panel-strong': '#dce9ff', ink: '#0d1c2e',
                    muted: '#43474d', border: '#c3c6ce', control: '#74777e', gold: '#f3c969',
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
