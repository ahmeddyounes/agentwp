import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  build: {
    rollupOptions: {
      output: {
        manualChunks: {
          charts: ['chart.js', 'react-chartjs-2'],
        },
      },
    },
  },
  server: {
    host: true,
    port: Number.parseInt(process.env.REACT_DEV_PORT || '5173', 10),
    strictPort: true,
  },
});
