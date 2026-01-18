/** @type {import('tailwindcss').Config} */
const withAlpha = (variable) => `rgb(var(${variable}) / <alpha-value>)`;

export default {
  content: ['./index.html', './src/**/*.{js,jsx,ts,tsx}'],
  theme: {
    extend: {
      fontFamily: {
        sans: [
          'system-ui',
          '-apple-system',
          '"Segoe UI"',
          '"Helvetica Neue"',
          'Arial',
          'sans-serif',
        ],
        display: [
          'system-ui',
          '-apple-system',
          '"Segoe UI"',
          '"Helvetica Neue"',
          'Arial',
          'sans-serif',
        ],
      },
      colors: {
        white: withAlpha('--awp-white'),
        black: withAlpha('--awp-black'),
        slate: {
          100: withAlpha('--awp-slate-100'),
          200: withAlpha('--awp-slate-200'),
          300: withAlpha('--awp-slate-300'),
          400: withAlpha('--awp-slate-400'),
          500: withAlpha('--awp-slate-500'),
          600: withAlpha('--awp-slate-600'),
          700: withAlpha('--awp-slate-700'),
          800: withAlpha('--awp-slate-800'),
          900: withAlpha('--awp-slate-900'),
          950: withAlpha('--awp-slate-950'),
        },
        sky: {
          100: withAlpha('--awp-sky-100'),
          300: withAlpha('--awp-sky-300'),
          400: withAlpha('--awp-sky-400'),
          500: withAlpha('--awp-sky-500'),
          700: withAlpha('--awp-sky-700'),
        },
        emerald: {
          100: withAlpha('--awp-emerald-100'),
          300: withAlpha('--awp-emerald-300'),
          400: withAlpha('--awp-emerald-400'),
          500: withAlpha('--awp-emerald-500'),
        },
        amber: {
          200: withAlpha('--awp-amber-200'),
          300: withAlpha('--awp-amber-300'),
          400: withAlpha('--awp-amber-400'),
          500: withAlpha('--awp-amber-500'),
        },
        rose: {
          300: withAlpha('--awp-rose-300'),
          400: withAlpha('--awp-rose-400'),
        },
        'deck-surface': withAlpha('--awp-deck-surface'),
        'deck-border': withAlpha('--awp-deck-border'),
      },
      boxShadow: {
        deck: 'var(--awp-shadow-deck)',
      },
      keyframes: {
        'deck-in': {
          '0%': { opacity: '0', transform: 'translateY(12px) scale(0.98)' },
          '100%': { opacity: '1', transform: 'translateY(0) scale(1)' },
        },
        'fade-in': {
          '0%': { opacity: '0' },
          '100%': { opacity: '1' },
        },
      },
      animation: {
        'deck-in': 'deck-in 180ms ease-out',
        'fade-in': 'fade-in 200ms ease-out',
      },
    },
  },
  plugins: [],
};
