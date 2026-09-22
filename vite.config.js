import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  base: './',
  define: { __BUILD_REVISION__: JSON.stringify(process.env.GITHUB_SHA?.slice(0, 7) ?? 'local') },
});
