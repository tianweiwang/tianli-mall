import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue2';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
  base: './',
  plugins: [vue()],
  resolve: {
    alias: [
      {
        find: /^vue$/,
        replacement: fileURLToPath(new URL('./node_modules/vue/dist/vue.runtime.esm.js', import.meta.url)),
      },
    ],
  },
  server: { port: 4173 },
  preview: { port: 4174 },
  test: {
    environment: 'jsdom',
    exclude: ['tests/e2e/**', 'node_modules/**'],
    globals: true,
    restoreMocks: true,
    setupFiles: ['./tests/setup.js'],
  },
});
