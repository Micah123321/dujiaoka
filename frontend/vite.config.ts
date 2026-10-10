import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';
export default defineConfig({
  plugins: [vue()],
  base: '/assets/modern/',
  build: { outDir: '../public/assets/modern', emptyOutDir: true, manifest: 'manifest.json', rollupOptions: { input: fileURLToPath(new URL('./src/main.ts', import.meta.url)) } },
  test: { environment: 'jsdom', include: ['src/**/*.test.ts'], clearMocks: true }
});
