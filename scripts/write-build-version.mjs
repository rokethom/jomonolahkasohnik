import { execSync } from 'node:child_process'
import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const app = process.argv[2]
const validApps = new Set(['customer', 'admin', 'driver'])

if (!validApps.has(app)) {
  console.error('Usage: node scripts/write-build-version.mjs <customer|admin|driver>')
  process.exit(1)
}

function git(command, fallback) {
  try {
    return execSync(command, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim() || fallback
  } catch {
    return fallback
  }
}

const sha = git('git rev-parse --short HEAD', 'local')
const fullSha = git('git rev-parse HEAD', sha)
const message = git('git log -1 --pretty=%s', 'Update aplikasi terbaru')
const committedAt = git('git log -1 --pretty=%cI', '')
const builtAt = new Date().toISOString()
const version = {
  app,
  sha,
  full_sha: fullSha,
  message,
  committed_at: committedAt,
  built_at: builtAt,
}

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const appRoot = resolve(repoRoot, 'apps', app)
const publicFile = resolve(appRoot, 'public', 'version.json')

mkdirSync(dirname(publicFile), { recursive: true })
writeFileSync(publicFile, `${JSON.stringify(version, null, 2)}\n`)

console.log(`Wrote ${app} build version ${sha}`)
