import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
  plugins: [vue()],
  base: '/assets/admin/',
  build: {
    outDir: '../public/assets/admin',
    emptyOutDir: true,
    manifest: 'manifest.json',
    target: 'es2020',
    cssCodeSplit: false,
    rollupOptions: {
      input: fileURLToPath(new URL('./src/admin/main.ts', import.meta.url)),
      output: { format: 'iife', name: 'DujiaokaWorkbench', inlineDynamicImports: true, entryFileNames: 'assets/workbench-[hash].js' },
    },
  },
  test: { environment: 'jsdom', include: ['src/admin/**/*.test.ts'], clearMocks: true },
});
