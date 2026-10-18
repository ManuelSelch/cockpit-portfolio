import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

test('addon explicitly registers POST routes and binds included handlers to the app', async () => {
  const source = await readFile(new URL('../bootstrap.php', import.meta.url), 'utf8')
  assert.match(source, /on\('restApi\.config'/)
  assert.match(source, /\['authoring', 'upload'\]/)
  assert.match(source, /addEndPoint\("\/portfolio\/\{\$name\}"/)
  assert.match(source, /'POST'\s*=>/)
  assert.match(source, /include \$file/)
  assert.match(source, /\)->call\(\$app\)/)
})
const php = spawnSync('php', ['--version'])
test('both registered handlers execute with app context and reject missing credentials', { skip: php.error?.code === 'ENOENT' ? 'PHP unavailable; run php tests/routes.php on the host' : false }, () => {
  const result = spawnSync('php', [fileURLToPath(new URL('./routes.php', import.meta.url))], { encoding: 'utf8' })
  assert.equal(result.status, 0, result.stdout + result.stderr)
})
