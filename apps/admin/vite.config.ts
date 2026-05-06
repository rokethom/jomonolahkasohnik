import { defineConfig } from 'vite'
import react, { reactCompilerPreset } from '@vitejs/plugin-react'
import babel from '@rolldown/plugin-babel'

// https://vite.dev/config/
export default defineConfig({
  server: {
    port: 5171,
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
