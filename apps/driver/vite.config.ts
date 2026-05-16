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
    port: 5173,
    strictPort: true,
     allowedHosts: [
    '.situapps.tech'
  ]
  },
  plugins: [
    react(),
    babel({ presets: [reactCompilerPreset()] })
  ],
})
