import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'node:path'

// Overwolf loads the app from an overwolf-extension:// URL, so all asset
// paths must be relative. Each Overwolf window is its own HTML page.
export default defineConfig({
  base: './',
  plugins: [vue()],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      input: {
        background: resolve(__dirname, 'background.html'),
        in_game: resolve(__dirname, 'in_game.html'),
      },
    },
  },
})
