/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,jsx}'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['"Space Grotesk"', '"Manrope"', 'system-ui', 'sans-serif'],
        display: ['"Space Grotesk"', '"Manrope"', 'system-ui', 'sans-serif'],
      },
      colors: {
        'deck-surface': '#0c111c',
        'deck-border': 'rgba(148, 163, 184, 0.2)',
      },
      boxShadow: {
        deck: '0 30px 80px rgba(15, 23, 42, 0.55)',
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
