// SPDX-License-Identifier: AGPL-3.0-or-later
// Isolated-server acceptance. Config and credentials stay outside the repository.
import fs from 'node:fs'
import assert from 'node:assert/strict'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const config = JSON.parse(fs.readFileSync(process.env.INTEGRATIONS_TEST_CONFIG, 'utf8'))
assert.ok(['localhost', '127.0.0.1'].includes(new URL(config.url).hostname), 'Only loopback test servers are permitted')
const base = config.url + '/apps/workspace_integrations'
const browser = await chromium.launch({ headless: true })
const sessions = {}
const checks = []
const check = (name, value) => { assert.ok(value, name); checks.push(name); console.log('PASS ' + name) }
try {
 for (const [uid, credentials] of Object.entries(config.users)) {
  const context = await browser.newContext()
  const page = await context.newPage()
  await page.goto(config.url + '/login')
  await page.locator('#user').fill(uid)
  await page.locator('#password').fill(credentials.password)
  await page.locator('button[type=submit]').click()
  await page.waitForURL(u => !u.pathname.includes('/login'), { timeout: 60000 })
  sessions[uid] = { context, page, token: await page.locator('head').getAttribute('data-requesttoken') }
 }
 async function api(uid, method, path, data, csrf = true) {
  const s = sessions[uid]
  const r = await s.context.request.fetch(base + path, { method, data, headers: { Accept: 'application/json', ...(csrf ? { requesttoken: s.token } : {}) } })
  let body; try { body = await r.json() } catch { body = {} }
  return { status: r.status(), body }
 }
 async function talk(uid, method, path, data) {
  const s = sessions[uid]
  const r = await s.context.request.fetch(config.url + '/ocs/v2.php/apps/spreed/api/' + path, { method, data, headers: { 'OCS-APIRequest': 'true', Accept: 'application/json', requesttoken: s.token } })
  assert.ok(r.ok(), 'Talk test setup failed: ' + r.status())
  return (await r.json()).ocs.data
 }
 const room = await talk('alice', 'POST', 'v4/room', { roomType: 2, roomName: 'Integration acceptance ' + Date.now() })
 await talk('alice', 'POST', 'v4/room/' + room.token + '/participants', { newParticipant: 'bob' })
 const name = 'Test Worker ' + Date.now()
 const created = await api('alice', 'POST', '/api/integrations', { name, description: 'Isolated notification test' })
 assert.equal(created.status, 200, 'owner create failed: ' + JSON.stringify(created.body))
 check('owner can create', true)
 const integration = created.body.integration || created.body
 assert.ok(integration.id)
 const id = integration.id
 const bobList = await api('bob', 'GET', '/api/integrations')
 check('non-owner cannot list another bot', !bobList.body.integrations.some(x => x.id === id))
 check('non-admin cannot list all', (await api('bob', 'GET', '/api/integrations?scope=all')).status === 403)
 check('non-owner mutation denied', (await api('bob', 'PATCH', '/api/integrations/' + id, { name: 'Changed' })).status === 404)
 const adminList = await api('admin', 'GET', '/api/integrations?scope=all')
 check('admin can list all', adminList.body.integrations.some(x => x.id === id))
 check('CSRF required', (await api('alice', 'POST', '/api/integrations', { name: 'Rejected' }, false)).status === 412 || (await api('alice', 'POST', '/api/integrations', { name: 'Rejected' }, false)).status === 403)
 const connected = await api('alice', 'POST', '/api/integrations/' + id + '/connections', { token: room.token })
 check('room moderator can connect own bot', connected.status === 200)
 const { connection, credential, webhookUrl } = connected.body
 assert.ok(credential && connection.id && webhookUrl)
 check('endpoint bound to test origin', new URL(webhookUrl).origin === new URL(config.url).origin)
 const anon = await browser.newContext()
 const hook = async (key, payload) => {
  const r = await anon.request.post(webhookUrl, { data: payload, headers: { ...(key ? { Authorization: 'Bearer ' + key } : {}), Accept: 'application/json' } })
  let body; try { body = await r.json() } catch { body = {} }
  return { status: r.status(), body }
 }
 check('missing webhook credential rejected', (await hook('', { text: 'rejected' })).status >= 400)
 const eventId = 'test-' + Date.now()
 const sent = await hook(credential, { text: 'Isolated audit delivery', eventId })
 check('webhook delivers audit message', sent.status >= 200 && sent.status < 300)
 check('same event safely repeats', (await hook(credential, { text: 'Isolated audit delivery', eventId })).status < 300)
 check('event payload mismatch rejected', (await hook(credential, { text: 'changed', eventId })).status === 409)
 const simultaneous = await Promise.all(Array.from({ length: 4 }, () => hook(credential, { text: 'Concurrent isolated audit', eventId: eventId + '-parallel' })))
 check('concurrent event produces one delivery', simultaneous.filter(r => r.body.status === 'delivered').length === 1)
 check('concurrent repeats are duplicates or pending', simultaneous.every(r => r.status === 200 || r.status === 409))
 const listed = await api('alice', 'GET', '/api/integrations')
 check('credential not returned by list', !JSON.stringify(listed.body).includes(credential) && !JSON.stringify(listed.body).includes('credential_hash'))
 const rotated = await api('alice', 'POST', '/api/integrations/' + id + '/connections/' + connection.id + '/rotate', {})
 check('owner rotates credential', rotated.status === 200 && rotated.body.credential !== credential)
 check('old credential rejected', (await hook(credential, { text: 'rejected old' })).status >= 400)
 check('new credential works', (await hook(rotated.body.credential, { text: 'Rotated credential delivery', eventId: eventId + '-rotate' })).status < 300)
 check('owner disables bot', (await api('alice', 'PATCH', '/api/integrations/' + id, { enabled: false })).status === 200)
 check('disabled bot cannot post', (await hook(rotated.body.credential, { text: 'rejected disabled' })).status >= 400)
 check('owner re-enables bot', (await api('alice', 'PATCH', '/api/integrations/' + id, { enabled: true })).status === 200)
 check('admin can suspend bot', (await api('admin', 'PATCH', '/api/integrations/' + id, { enabled: false })).status === 200)
 check('owner cannot reverse admin suspension', (await api('alice', 'PATCH', '/api/integrations/' + id, { enabled: true })).status === 403)
 check('admin can restore bot', (await api('admin', 'PATCH', '/api/integrations/' + id, { enabled: true })).status === 200)
 check('non-moderator cannot inspect channel connections', (await api('bob', 'GET', '/api/channels/' + room.token + '/connections')).status === 403)
 const participants = await talk('alice', 'GET', 'v4/room/' + room.token + '/participants')
 const bob = participants.find(x => x.actorId === 'bob')
 assert.ok(bob)
 await talk('alice', 'POST', 'v4/room/' + room.token + '/moderators', { attendeeId: bob.attendeeId })
 check('channel moderator removes active other-owner bot', (await api('bob', 'DELETE', '/api/channels/' + room.token + '/connections/' + connection.id)).status === 200)
 check('moderator removal revokes webhook', (await hook(rotated.body.credential, { text: 'rejected moderator removal' })).status >= 400)
 const reconnected = await api('alice', 'POST', '/api/integrations/' + id + '/connections', { token: room.token })
 check('owner can reconnect managed channel', reconnected.status === 200)
 check('reconnected credential works', (await hook(reconnected.body.credential, { text: 'Reconnected test notification', eventId: eventId + '-reconnect' })).status < 300)
 check('admin can transfer ownership', (await api('admin', 'PATCH', '/api/integrations/' + id, { ownerUid: 'bob' })).status === 200)
 check('transfer revokes previous owner credentials', (await hook(reconnected.body.credential, { text: 'rejected transferred' })).status >= 400)
 check('old owner loses management', (await api('alice', 'PATCH', '/api/integrations/' + id, { enabled: false })).status === 404)
 check('channel moderator retains removal power', (await api('alice', 'DELETE', '/api/channels/' + room.token + '/connections/' + connection.id)).status === 200)
 check('disconnected webhook rejected', (await hook(rotated.body.credential, { text: 'rejected removed' })).status >= 400)
 await api('bob', 'PATCH', '/api/integrations/' + id, { enabled: false })
 const alicePage = sessions.alice.page
 await alicePage.goto(base + '/')
 await alicePage.getByRole('button', { name: 'Bots', exact: true }).waitFor({ timeout: 15000 })
 check('management page renders', (await alicePage.locator('body').innerText()).includes('Your bots'))
 if (process.env.INTEGRATIONS_EVIDENCE_DIR) {
  fs.mkdirSync(process.env.INTEGRATIONS_EVIDENCE_DIR, { recursive: true })
  await alicePage.screenshot({ path: process.env.INTEGRATIONS_EVIDENCE_DIR + '/integrations.png', fullPage: true })
  fs.writeFileSync(process.env.INTEGRATIONS_EVIDENCE_DIR + '/results.json', JSON.stringify({ passed: true, checks }, null, 2))
 }
 console.log('PASS ' + checks.length + ' live acceptance checks; only loopback test messages sent')
} finally { await browser.close() }
