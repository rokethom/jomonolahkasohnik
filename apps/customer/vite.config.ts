import { defineConfig } from 'vite'
import react, { reactCompilerPreset } from '@vitejs/plugin-react'
import babel from '@rolldown/plugin-babel'

// https://vite.dev/config/
export default defineConfig({
  build: {
    target: 'es2018',
    cssTarget: 'safari15',
  },
  server: {
    port: 5172,
    strictPort: true,
    allowedHosts: [
      '.aplikasijoker.my.id',
    ],
  },
  plugins: [
    react(),
    babel({ presets: [reactCompilerPreset()] })
  ],
})
