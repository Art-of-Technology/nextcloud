/* SPDX-License-Identifier: AGPL-3.0-or-later */
// Run using Electron: electron tests/card-renderer.cjs (isolated, no network).
const { app, BrowserWindow } = require('electron');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'notification-card-test-'));
app.setPath('userData', profile);
app.commandLine.appendSwitch('disable-gpu');
app.whenReady().then(async () => {
	const win = new BrowserWindow({ show: false, width: 680, height: 900, webPreferences: { nodeIntegration: false, contextIsolation: true, offscreen: true } });
	win.webContents.on('console-message', (_event, _level, message) => console.log('renderer:', message));
	await win.loadURL('data:text/html,<html><head></head><body></body></html>');
	await win.webContents.executeJavaScript(fs.readFileSync(path.join(__dirname, '../js/notification-card.js'), 'utf8'));
	await win.webContents.insertCSS(fs.readFileSync(path.join(__dirname, '../css/notification-card.css'), 'utf8'));
	const result = await win.webContents.executeJavaScript(`(async function () {
		const api = window.WorkspaceNotificationCard;
		let count = 0;
		function assert(value, label) { if (!value) throw new Error(label); count++; }
		let copied = '';
		const card = {schemaVersion:1,blocks:[
			{type:'header',text:{type:'plain_text',text:'Deployment complete'}},
			{type:'section',text:{type:'mrkdwn',text:'*Success* <https://example.org/report|View report> <img src=x onerror=alert(1)>'},fields:[{type:'mrkdwn',text:'*Service*\\nAPI'},{type:'plain_text',text:'Status\\nHealthy'}]},
			{type:'divider'},
			{type:'context',elements:[{type:'plain_text',text:'Production · 14:30'}]},
			{type:'attachment',color:'#008800',blocks:[{type:'rich_text',elements:[{type:'rich_text_quote',elements:[{type:'text',text:'Ready for review',style:{bold:true}}]},{type:'rich_text_list',style:'ordered',elements:[{type:'rich_text_section',elements:[{type:'text',text:'Check metrics'}]}]},{type:'rich_text_preformatted',elements:[{type:'text',text:'deploy --dry-run'}]}]}]},
			{type:'table',rows:[[{type:'raw_text',text:'p95'},{type:'rich_text',elements:[{type:'rich_text_section',elements:[{type:'text',text:'32 ms'}]}]}]]},
			{type:'image',image_url:'https://example.org/tracker.png',alt_text:'Optional chart'}
		]};
		const root = api.render(card,{clipboard:{writeText:async text => {copied = text}}});
		document.body.appendChild(root);
		assert(root.querySelector('h3').textContent === 'Deployment complete','header');
		assert(root.querySelector('strong').textContent === 'Success','formatting');
		assert(!root.querySelector('img'),'no automatic image request');
		assert(root.textContent.includes('<img src=x onerror=alert(1)>'),'HTML remains literal');
		assert(!root.querySelector('[onerror]'),'no event attributes');
		const link = root.querySelector('a');
		assert(link.rel.includes('noreferrer') && link.target === '_blank','safe link');
		assert(root.querySelectorAll('td').length === 2,'table');
		assert(root.querySelector('blockquote') && root.querySelector('ol'),'rich quote/list');
		root.querySelector('.wnc-copy').click();
		await new Promise(resolve => setTimeout(resolve,20));
		assert(copied === 'deploy --dry-run','copy code');
		['javascript:alert(1)','data:image/svg+xml,x','file:///etc/passwd','https://user:pass@example.org','https://example.org/\\nfoo'].forEach(url => assert(api.safeUrl(url) === null,'unsafe URL'));
		let rejected = false; try { api.render({schemaVersion:1,blocks:[{type:'actions'}]}); } catch (_) {rejected = true}
		assert(rejected,'explicit unsupported block');
		assert(root.scrollWidth <= root.clientWidth + 1,'layout fits viewport');
		const bt=String.fromCharCode(96), json=JSON.stringify({filters:[{field:'amount',op:'>',value:500}],label:'<img onerror=alert(1)>'});
		const extra=api.render({schemaVersion:1,blocks:[
			{type:'section',text:{type:'mrkdwn',text:':moneybag: :white_check_mark: :warning: :robot_face: :unknown_custom: *bold _italic_* '+bt+':moneybag:'+bt}},
			{type:'rich_text',elements:[{type:'rich_text_section',elements:[{type:'emoji',name:'rotating_light'},{type:'emoji',name:'hourglass_flowing_sand'}]}]},
			{type:'context',elements:[{type:'mrkdwn',text:'Filters: '+bt+json+bt+' ordinary '+bt+'not JSON'+bt}]}
		]},{clipboard:{writeText:async text=>{copied=text}}});
		document.body.appendChild(extra);
		assert(extra.textContent.includes('💰 ✅ ⚠️ 🤖 :unknown_custom:'),'common emoji aliases and unknown fallback');
		assert(extra.textContent.includes('🚨⏳'),'rich emoji aliases');
		assert(extra.querySelector('strong em').textContent==='italic','nested mixed emphasis');
		assert(extra.querySelector('.wnc-inline-code').textContent===':moneybag:','code remains literal');
		const details=extra.querySelector('details');
		assert(details&&!details.open&&details.querySelector('summary').textContent==='JSON details','context JSON expandable');
		assert(details.querySelector('code').textContent===JSON.stringify(JSON.parse(json),null,2),'context JSON pretty');
		assert(extra.querySelectorAll('details').length===1&&!extra.querySelector('img'),'non-JSON code remains normal; JSON HTML safe');
		details.querySelector('button').click();await new Promise(resolve=>setTimeout(resolve,20));
		assert(copied===json,'JSON copies original source');
		return count;
	})()`);
	await new Promise(resolve => setTimeout(resolve, 250));
	if (process.env.CARD_RENDERER_SCREENSHOT) fs.writeFileSync(process.env.CARD_RENDERER_SCREENSHOT, (await win.webContents.capturePage()).toPNG());
	await win.webContents.executeJavaScript(`window._cardWidgetFetch = []; window.fetch = function(url, options) {return new Promise((resolve,reject) => {window._cardWidgetFetch.push({url,options,resolve,reject});options.signal.addEventListener('abort',()=>reject(new Error('aborted')));});}; void 0;`);
	await win.webContents.executeJavaScript(fs.readFileSync(path.join(__dirname, '../js/card-widget.js'), 'utf8'));
	const widgets = await win.webContents.executeJavaScript(`(async function(){
		const widget=window._vue_richtext_widgets.workspace_notification_card;
		const host=document.createElement('div');document.body.appendChild(host);
		const args={richObject:{cardId:'0123456789abcdef0123456789abcdef'}};
		widget.callback(host,args);let request=window._cardWidgetFetch.pop();
		if(request.options.credentials!=='same-origin'||request.options.cache!=='no-store')throw new Error('authenticated no-store');
		request.resolve({status:404});await new Promise(r=>setTimeout(r,10));
		if(host.textContent||host.style.display!=='none')throw new Error('404 leaks content');
		widget.callback(host,args);request=window._cardWidgetFetch.pop();const wrapper=document.createElement('div');host.replaceWith(wrapper);wrapper.appendChild(host);widget.onDestroy(wrapper);
		if(!request.options.signal.aborted)throw new Error('unmount did not abort');
		await new Promise(r=>setTimeout(r,10));if(host.textContent)throw new Error('unmounted callback rendered');
		widget.callback(host,args);request=window._cardWidgetFetch.pop();request.resolve({ok:false,status:500});
		await new Promise(r=>setTimeout(r,10));if(!host.querySelector('[role=alert]'))throw new Error('error hidden');
		widget.onDestroy(host);
		const realTimeout=window.setTimeout, timers=[];
		window.setTimeout=function(fn,ms){if([1000,3000,5000,30000].includes(ms)){timers.push({fn,ms});return 9000+timers.length;}return realTimeout(fn,ms);};
		widget.callback(host,args);request=window._cardWidgetFetch.pop();request.resolve({status:404});
		await new Promise(r=>realTimeout(r,10));
		const retry=timers.find(x=>x.ms===1000);if(!retry)throw new Error('missing bounded receipt retry');retry.fn();
		request=window._cardWidgetFetch.pop();request.resolve({ok:true,status:200,json:async()=>({schemaVersion:1,blocks:[{type:'section',text:{type:'plain_text',text:'Visible'}}]})});
		await new Promise(r=>realTimeout(r,10));if(!host.textContent.includes('Visible'))throw new Error('receipt retry did not render');
		const refresh=timers.find(x=>x.ms===30000);if(!refresh)throw new Error('access revalidation absent');refresh.fn();
		request=window._cardWidgetFetch.pop();request.resolve({status:404});
		await new Promise(r=>realTimeout(r,10));if(host.textContent)throw new Error('revoked membership retained content');
		widget.onDestroy(host);timers.length=0;
		widget.callback(host,args);request=window._cardWidgetFetch.pop();timers.find(x=>x.ms===5000).fn();
		await new Promise(r=>realTimeout(r,10));if(!request.options.signal.aborted||!host.querySelector('[role=alert]'))throw new Error('timeout not explicit');
		widget.onDestroy(host);window.setTimeout=realTimeout;return 7;
	})()`);
	console.log('Notification card renderer: ' + result + ' assertions; widget: ' + widgets + ' cases passed');
	win.destroy(); app.exit(0);
}).catch(error => { console.error(error); app.exit(1); });
