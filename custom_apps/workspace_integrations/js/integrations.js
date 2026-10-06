/* SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
 'use strict';
 const root = document.getElementById('wi-app'), status = document.getElementById('wi-status');
 if (!root) return;
 let scope = 'mine', view = 'bots', selected = null, query = '', channelToken = '';
 let data = { isAdmin: false, integrations: [] }, channels = [], connections = null, secret = null, busy = false;
 const el = (tag, text, cls) => { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n; };
 const encoded = encodeURIComponent;
 function message(text, error = false) { status.textContent = text; status.className = error ? 'wi-error' : ''; }
 async function api(path, method = 'GET', body) {
  const response = await fetch(OC.generateUrl('/apps/workspace_integrations' + path), { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', requesttoken: OC.requestToken }, body: body === undefined ? undefined : JSON.stringify(body) });
  let value; try { value = await response.json(); } catch { throw new Error('The server returned an unexpected response.'); }
  if (!response.ok) throw new Error(value.error || 'Request failed.'); return value;
 }
 async function run(control, action) {
  if (busy) return;
  busy = true; control.disabled = true; root.setAttribute('aria-busy', 'true'); message('');
  try { await action(); } catch (error) { message(error.message, true); }
  finally { busy = false; control.disabled = false; root.removeAttribute('aria-busy'); }
 }
 function button(label, action, cls) {
  const b = el('button', label, cls); b.type = 'button'; b.addEventListener('click', () => run(b, action)); return b;
 }
 function field(label, value = '', kind = 'input') {
  const wrapper = el('label', label), input = el(kind); input.value = value; wrapper.append(input); return { wrapper, input };
 }
 function mayLeave() {
  if (secret && !confirm('Have you saved the webhook credential? It cannot be displayed again after leaving this view.')) return false;
  secret = null; document.getElementById('wi-secret')?.remove(); return true;
 }
 window.addEventListener('beforeunload', event => { if (secret) { event.preventDefault(); event.returnValue = ''; } });
 function navigate(next, id = null) {
  if (!mayLeave()) return;
  view = next; selected = id; query = ''; render(); focusHeading();
 }
 function focusHeading() { const heading = root.querySelector('h2'); if (heading) { heading.tabIndex = -1; heading.focus({ preventScroll: true }); } }
 async function refresh() {
  const [next, available] = await Promise.all([api('/api/integrations?scope=' + scope), api('/api/channels')]);
  data = next; channels = available.channels;
 }
 function card(title, text) { const n = el('section', undefined, 'wi-card'); if (title) n.append(el('h2', title)); if (text) n.append(el('p', text, 'wi-muted')); return n; }
 function search(label, onInput) {
  const f = field(label, query); f.input.type = 'search'; f.input.placeholder = label;
  f.input.addEventListener('input', () => { query = f.input.value; onInput(); }); return f.wrapper;
 }
 function render() {
  root.replaceChildren();
  const nav = el('nav', undefined, 'wi-tabs'); nav.setAttribute('aria-label', 'Integration management');
  for (const [key, label] of [['bots', 'Bots'], ['channels', 'Channel access']]) {
   const b = button(label, () => navigate(key)); b.setAttribute('aria-current', (key === 'channels' ? view === key : view !== 'channels') ? 'page' : 'false'); nav.append(b);
  }
  root.append(nav);
  if (view === 'create') renderCreate();
  else if (view === 'detail') renderDetail();
  else if (view === 'channels') renderChannels();
  else renderBots();
 }
 function renderBots() {
  const toolbar = el('div', undefined, 'wi-toolbar'); toolbar.append(el('h2', 'Your bots'), button('Create bot', () => navigate('create'), 'wi-primary')); root.append(toolbar);
  root.append(el('p', 'Choose a bot to manage its channels and webhooks.', 'wi-muted'));
  if (data.isAdmin) {
   const scopes = el('div', undefined, 'wi-row');
   for (const s of ['mine', 'all']) { const b = button(s === 'mine' ? 'My integrations' : 'All integrations', async () => { scope = s; await refresh(); render(); }); b.setAttribute('aria-pressed', String(scope === s)); scopes.append(b); }
   root.append(scopes);
  }
  const list = el('div', undefined, 'wi-list');
  function fill() {
   list.replaceChildren(); const matches = data.integrations.filter(i => (i.name + ' ' + (i.description ?? '')).toLowerCase().includes(query.toLowerCase()));
   if (!matches.length) { list.append(el('p', data.integrations.length ? 'No bots match your search.' : 'No bots yet in this view. Create a bot, then connect its first channel.', 'wi-empty')); return; }
   for (const item of matches) {
    const row = el('article', undefined, 'wi-list-row'), info = el('div');
    info.append(el('h3', item.name), el('small', 'Owner: ' + item.ownerUid + ' · ' + (item.adminDisabled ? 'Disabled by an administrator' : item.enabled ? 'Enabled' : 'Disabled') + ' · ' + (item.connections || []).filter(c => c.enabled).length + ' active channels'));
    if (item.description) info.append(el('p', item.description, 'wi-description'));
    const open = button('Manage bot', () => navigate('detail', item.id)); open.setAttribute('aria-label', 'Manage ' + item.name); row.append(info, open); list.append(row);
   }
  }
  root.append(search('Search bots', fill), list); fill();
 }
 function renderCreate() {
  root.append(button('Back to bots', () => navigate('bots'), 'wi-back'));
  const panel = card('Create bot', 'This is the sender name people will see in Talk. After creating it, connect a channel to get a webhook for your existing service.');
  const form = el('form', undefined, 'wi-form'), name = field('Bot name'), description = field('Description (optional)', '', 'textarea');
  name.input.required = true; name.input.maxLength = 64; description.input.maxLength = 1000;
  const submit = el('button', 'Create bot', 'wi-primary'); submit.type = 'submit';
  form.append(name.wrapper, description.wrapper, submit, button('Cancel', () => navigate('bots')));
  form.addEventListener('submit', event => { event.preventDefault(); run(submit, async () => {
   const item = await api('/api/integrations', 'POST', { name: name.input.value, description: description.input.value });
   // Keep the successful result usable even if the following list refresh fails.
   data.integrations.push(item); selected = item.id; view = 'detail'; render(); focusHeading(); message('Bot created. Connect its first channel below.');
  }); }); panel.append(form); root.append(panel);
 }
 function showSecret(result, token, container) {
  secret = { ...result, token };
  const existing = document.getElementById('wi-secret'); if (existing) existing.remove();
  const box = el('section', undefined, 'wi-secret'); box.id = 'wi-secret'; box.setAttribute('aria-label', 'New webhook credential');
  box.append(el('h3', 'Save this channel’s webhook'), el('p', 'Copy both values to your service’s secret settings. The credential is shown only once.'));
  for (const [label, value] of [['Webhook URL', result.webhookUrl], ['Credential', result.credential]]) {
   const f = field(label, value); f.input.readOnly = true; f.input.autocomplete = 'off'; f.input.spellcheck = false;
   box.append(f.wrapper, button('Copy ' + label.toLowerCase(), async () => { await navigator.clipboard.writeText(value); message(label + ' copied.'); }));
  }
  box.append(el('p', 'Send JSON with "text" and an optional unique "eventId", using Authorization: Bearer <credential>. This webhook sends only to this channel.', 'wi-muted'));
  box.append(button('I have saved it', () => { secret = null; box.remove(); message('Credential dismissed.'); }));
  container.append(box);
  // Focus without scrolling: never jump to the top of the page after a connection.
  box.tabIndex = -1; box.focus({ preventScroll: true });
 }
 function renderDetail() {
  const item = data.integrations.find(i => i.id === selected);
  if (!item) { view = 'bots'; renderBots(); message('This bot is no longer in this view.'); return; }
  const prefix = '/api/integrations/' + encoded(item.id);
  root.append(button('Back to bots', () => navigate('bots'), 'wi-back'));
  const header = el('div', undefined, 'wi-toolbar'); header.append(el('h2', item.name), el('span', item.adminDisabled ? 'Disabled by an administrator' : item.enabled ? 'Enabled' : 'Disabled', 'wi-badge')); root.append(header, el('p', 'Owner: ' + item.ownerUid, 'wi-muted'));
  const panel = card('Connected channels', 'Each channel has its own webhook and credential.');
  const list = el('div', undefined, 'wi-list');
  for (const c of item.connections || []) {
   const row = el('section', undefined, 'wi-connection'), line = el('div', undefined, 'wi-list-row'), info = el('div');
   info.append(el('h3', c.name || c.token), el('small', c.enabled ? 'Connected · Last delivery: ' + (c.lastStatus || 'No deliveries') + (c.lastAt ? ' · ' + new Date(Number(c.lastAt) * 1000).toLocaleString() : '') : 'Disconnected or unavailable'));
   const actions = el('div', undefined, 'wi-row');
   if (c.enabled) actions.append(button('Rotate credential', async () => {
    if (!mayLeave() || !confirm('Replace this channel’s credential? Its old credential will stop working immediately.')) return;
    const result = await api(prefix + '/connections/' + encoded(c.id) + '/rotate', 'POST', {}); showSecret(result, c.token, row);
   }), button('Disconnect', async () => {
    if (!mayLeave() || !confirm('Stop this bot from delivering messages to this channel?')) return;
    await api(prefix + '/connections/' + encoded(c.id), 'DELETE'); c.enabled = false; render(); message('Channel disconnected.');
   }));
   line.append(info, actions); row.append(line); list.append(row);
  }
  if (!item.connections?.length) list.append(el('p', 'No channels connected yet. Choose the first destination below.', 'wi-empty'));
  panel.append(list);
  const connectForm = el('form', undefined, 'wi-connect'), selectField = field('Connect a channel', '', 'select'), select = selectField.input;
  const placeholder = el('option', 'Choose a channel you manage'); placeholder.value = ''; select.append(placeholder); select.required = true;
  for (const c of channels) { if ((item.connections || []).some(existing => existing.token === c.token && existing.enabled)) continue; const option = el('option', c.name); option.value = c.token; select.append(option); }
  const connect = el('button', 'Connect channel', 'wi-primary'); connect.type = 'submit'; connect.disabled = !item.enabled || select.options.length === 1;
  connectForm.append(selectField.wrapper, connect);
  if (!item.enabled) connectForm.append(el('p', 'Enable this bot in Settings before connecting a channel.', 'wi-muted'));
  else if (select.options.length === 1) connectForm.append(el('p', 'No additional channels available. You must be a member with moderator permission to connect a channel.', 'wi-muted'));
  connectForm.addEventListener('submit', event => { event.preventDefault(); run(connect, async () => {
   if (!select.value || !mayLeave()) return;
   const token = select.value, result = await api(prefix + '/connections', 'POST', { token });
   // Retain the one-time response without depending on another network request.
   item.connections = (item.connections || []).filter(c => c.id !== result.connection.id); item.connections.push(result.connection);
   render(); const rows = root.querySelectorAll('.wi-connection'); showSecret(result, token, rows[rows.length - 1]); message('Channel connected. Save its webhook below.');
  }); }); panel.append(connectForm); root.append(panel);
  const settings = el('details', undefined, 'wi-card wi-settings'); settings.append(el('summary', 'Bot settings'));
  const form = el('form', undefined, 'wi-form'), name = field('Bot name', item.name), description = field('Description (optional)', item.description || '', 'textarea'); name.input.required = true; name.input.maxLength = 64; description.input.maxLength = 1000;
  const save = el('button', 'Save details'); save.type = 'submit'; form.append(name.wrapper, description.wrapper, save);
  form.addEventListener('submit', event => { event.preventDefault(); run(save, async () => { if (!mayLeave()) return; const updated = await api(prefix, 'PATCH', { name: name.input.value, description: description.input.value }); Object.assign(item, updated); render(); message('Bot details saved.'); }); }); settings.append(form);
  if (item.adminDisabled && !data.isAdmin) settings.append(el('p', 'Disabled by an administrator'));
  else settings.append(button(item.enabled ? 'Disable bot' : 'Enable bot', async () => {
   if (!mayLeave() || (item.enabled && !confirm('Disable this bot? Delivery to all its channels will stop.'))) return;
   const updated = await api(prefix, 'PATCH', { enabled: !item.enabled }); Object.assign(item, updated); render(); message('Bot updated.');
  }));
  if (data.isAdmin) {
   const transfer = el('details', undefined, 'wi-transfer'); transfer.append(el('summary', 'Transfer ownership'));
   transfer.append(el('p', 'Transferring ownership revokes every connected webhook. The new owner must reconnect channels.'));
   const owner = field('New owner’s account username'); transfer.append(owner.wrapper, button('Transfer ownership', async () => {
    if (!owner.input.value.trim()) { message('Enter the new owner’s account username.', true); return; }
    if (!mayLeave() || !confirm('Transfer ownership and revoke all existing channel connections and credentials?')) return;
    await api(prefix, 'PATCH', { ownerUid: owner.input.value.trim() }); view = 'bots'; selected = null; await refresh(); render(); message('Ownership transferred.');
   })); settings.append(transfer);
  }
  root.append(settings);
 }
 function renderChannels() {
  root.append(el('h2', 'Channel access'), el('p', 'Review or remove bots connected through Integrations in channels you moderate. Bot settings and credentials remain private to their owner and administrators.', 'wi-muted'));
  const layout = el('div', undefined, 'wi-channel-layout'), sidebar = el('section', undefined, 'wi-channel-picker'), list = el('div', undefined, 'wi-list'), detail = card();
  function fill() {
   list.replaceChildren(); const matches = channels.filter(c => c.name.toLowerCase().includes(query.toLowerCase()));
   if (!matches.length) list.append(el('p', channels.length ? 'No channels match your search.' : 'You have no channels with moderator permission.', 'wi-empty'));
   for (const c of matches) { const b = button(c.name, async () => {
    channelToken = c.token; connections = null; render();
    const result = await api('/api/channels/' + encoded(c.token) + '/connections'); connections = result.connections; render();
   }, 'wi-channel-choice'); b.setAttribute('aria-pressed', String(channelToken === c.token)); list.append(b); }
  }
  sidebar.append(search('Search channels', fill), list); fill(); layout.append(sidebar, detail); root.append(layout);
  const channel = channels.find(c => c.token === channelToken);
  if (!channel) { detail.append(el('p', 'Select a channel to see its connected bots.', 'wi-empty')); return; }
  detail.append(el('h3', channel.name));
  if (connections === null) { detail.append(el('p', 'Select the channel to load or retry its connections.', 'wi-muted')); return; }
  detail.append(el('p', connections.length + ' connected bots', 'wi-muted'));
  if (!connections.length) detail.append(el('p', 'No bots managed by Integrations are connected to this channel.', 'wi-empty'));
  for (const connected of connections) {
   const row = el('div', undefined, 'wi-list-row'); row.append(el('span', connected.name), button('Remove from channel', async () => {
    if (!confirm('Remove this bot from the channel? Its webhook will stop working.')) return;
    await api('/api/channels/' + encoded(channelToken) + '/connections/' + encoded(connected.id), 'DELETE'); connections = connections.filter(c => c.id !== connected.id);
    for (const item of data.integrations) for (const c of item.connections || []) if (c.id === connected.id) c.enabled = false;
    render(); message('Bot removed from channel.');
   })); detail.append(row);
  }
 }
 refresh().then(render).catch(error => message(error.message, true));
})();
