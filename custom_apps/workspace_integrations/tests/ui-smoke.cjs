// SPDX-License-Identifier: AGPL-3.0-or-later
// Run with Electron. All API responses are isolated fixtures; network access is denied.
const { app, BrowserWindow } = require('electron');
const fs = require('node:fs'), path = require('node:path'), os = require('node:os'), assert = require('node:assert/strict');
app.setPath('userData', fs.mkdtempSync(path.join(os.tmpdir(), 'integrations-ui-test-')));
app.whenReady().then(async () => {
 const window = new BrowserWindow({ show: false, width: 1200, height: 900, webPreferences: { contextIsolation: true, backgroundThrottling: false } });
 window.webContents.session.webRequest.onBeforeRequest({ urls: ['http://*/*', 'https://*/*'] }, (_details, callback) => callback({ cancel: true }));
 const js = code => window.webContents.executeJavaScript(code);
 const wait = () => new Promise(r => setTimeout(r, 90));
 const click = async label => { await js(`Array.from(document.querySelectorAll('button')).find(b=>b.textContent===${JSON.stringify(label)}).click()`); await wait(); };
 const text = () => js('document.body.innerText');
 await window.loadURL('data:text/html,<main id="app-content" class="wi-content"><div class="wi-page"><h1>Integrations</h1><p>Manage bots and their channel connections.</p><div id="wi-status" role="status"></div><div id="wi-app"></div></div></main>');
 await window.webContents.insertCSS(':root{--color-main-background:#171717;--color-main-text:#eee;--color-text-maxcontrast:#aaa;--color-border:#353535;--color-primary-element:#008cca;--color-primary-element-text:white;--color-primary-element-light:#16323f;--color-background-hover:#252525;}body{margin:0;font:15px system-ui;background:#171717;color:#eee}button,input,textarea,select{font:inherit;color:inherit;background:#252525;border:1px solid #555;border-radius:7px;padding:9px}button{cursor:pointer}');
 await window.webContents.insertCSS(fs.readFileSync(path.join(__dirname, '../css/integrations.css'), 'utf8'));
 await js(`
 window.OC={generateUrl:p=>p,requestToken:'test-csrf'}; window.calls=[];window.accept=true;window.confirm=()=>accept;window.admin=false;window.failRead=false;window.failAction=false;window.failChannels=false;
 window.bots=[{id:'first',name:'Audit worker',description:'Posts operational notifications.',ownerUid:'alice',enabled:true,connections:[{id:'one',token:'room1234',name:'Operations',enabled:true,lastStatus:'delivered'}]},{id:'second',name:'<img src=x onerror=alert(1)>',description:'',ownerUid:'alice',enabled:true,connections:[]}];
 window.fetch=async(url,options)=>{
 calls.push({url,options}); let result;const body=JSON.parse(options.body||'{}');
 if(failRead&&options.method==='GET')return {ok:false,json:async()=>({error:'Read unavailable.'})};
 if(failAction&&options.method!=='GET')return {ok:false,json:async()=>({error:'Action failed.'})};
 if(url.endsWith('/api/channels'))result={channels:[{token:'room1234',name:'Operations'},{token:'room5678',name:'Audit logs'}]};
 else if(url.includes('/api/channels/')){if(failChannels)return{ok:false,json:async()=>({error:'Connections unavailable.'})};result={connections:options.method==='DELETE'?[]:[{id:'one',name:'Audit worker'}]};}
 else if(url.endsWith('/rotate'))result={credential:'one-time-test-credential',webhookUrl:'https://chat.example/hooks/one'};
 else if(url.endsWith('/connections')&&options.method==='POST'){result={connection:{id:'two',token:body.token,name:'Audit logs',enabled:true,lastStatus:''},credential:'new-channel-credential',webhookUrl:'https://chat.example/hooks/two'};bots[0].connections.push(result.connection);}
 else if(options.method==='PATCH'){const bot=bots.find(i=>url.endsWith('/'+i.id));Object.assign(bot,body);result=bot;}
 else if(options.method==='POST'){result={id:'created',name:body.name,description:body.description,ownerUid:'alice',enabled:true,connections:[]};bots.push(result);}
 else result={isAdmin:admin,integrations:bots};
 return {ok:true,json:async()=>JSON.parse(JSON.stringify(result))};};void 0;`);
 const source = fs.readFileSync(path.join(__dirname, '../js/integrations.js'), 'utf8');
 await js(source); await wait();
 assert.equal(await js('document.querySelectorAll("img").length'), 0);
 assert.equal(await js('document.querySelectorAll("textarea").length'), 0, 'list contains no expanded edit forms');
 assert.doesNotMatch(await text(), /Transfer ownership|All integrations|Show connected bots|Rotate credential/);
 await click('Manage bot');
 assert.match(await text(), /Connected channels/);assert.doesNotMatch(await text(), /<img/);
 assert.equal(await js('document.querySelector(".wi-settings").open'), false);
 await click('Rotate credential');
 assert.equal(await js('document.querySelector("#wi-secret").closest(".wi-connection")!==null'), true);
 assert.equal(await js('Array.from(document.querySelectorAll("#wi-secret input")).some(i=>i.value==="one-time-test-credential")'), true);
 await js('window.accept=false'); await click('Back to bots'); assert.match(await text(), /Save this channel/);
 await js('window.accept=true'); await click('I have saved it');assert.equal(await js('document.querySelector("#wi-secret")'), null);
 await js('document.querySelector(".wi-connect select").value="room5678";document.querySelector(".wi-connect").requestSubmit()');await wait();
 assert.equal(await js('document.querySelector("#wi-secret").closest(".wi-connection").textContent.includes("Audit logs")'), true);
 assert.equal(await js('calls.filter(c=>c.options.method==="POST"&&c.url.endsWith("/connections")).length'), 1);
 if(process.env.WI_SCREENSHOT_DIR){await new Promise(r=>setTimeout(r,300));fs.mkdirSync(process.env.WI_SCREENSHOT_DIR,{recursive:true});fs.writeFileSync(path.join(process.env.WI_SCREENSHOT_DIR,'bot-channel-webhook.png'),(await window.webContents.capturePage()).toPNG());}
 await click('I have saved it');
 await click('Channel access');assert.equal(await js('document.querySelector("#wi-secret,.wi-settings")'),null);
 await click('Operations'); assert.match(await text(),/1 connected bots/);
 await js('window.accept=false');await click('Remove from channel');assert.equal(await js('calls.filter(c=>c.options.method==="DELETE").length'),0);
 await js('window.accept=true;window.failChannels=true');await click('Audit logs');assert.match(await text(),/Connections unavailable/);
 await js('window.failChannels=false');await click('Audit logs');assert.match(await text(),/1 connected bots/);
 await click('Bots');
 await js('const s=document.querySelector("input[type=search]");s.value="no match";s.dispatchEvent(new Event("input"))');assert.match(await text(),/No bots match/);
 await click('Create bot');assert.equal(await js('document.querySelectorAll("textarea").length'),1);
 await js('document.querySelector(".wi-form input").value="New worker";document.querySelector(".wi-form").requestSubmit()');await wait();assert.match(await text(),/New worker/);assert.match(await text(),/No channels connected yet/);
 // Mutation failure keeps the form/draft and surfaces an error.
 await js('document.querySelector(".wi-settings").open=true;window.failAction=true;document.querySelector(".wi-form input").value="Edited worker";document.querySelector(".wi-form").requestSubmit()');await wait();assert.match(await text(),/Action failed/);assert.equal(await js('document.querySelector(".wi-form input").value'),'Edited worker');
 await js('window.failAction=false;window.admin=true');await click('Back to bots');
 // Reloading the script represents a fresh page load for the admin fixture.
 await js(source);await wait();assert.match(await text(),/All integrations/);
 await click('Manage bot');await js('document.querySelector(".wi-settings").open=true');assert.match(await text(),/Transfer ownership/);
 await click('Back to bots');
 if(process.env.WI_SCREENSHOT_DIR)fs.writeFileSync(path.join(process.env.WI_SCREENSHOT_DIR,'bots-list.png'),(await window.webContents.capturePage()).toPNG());
 window.setSize(390,844);await wait();
 assert.equal(await js('document.documentElement.scrollWidth<=window.innerWidth'),true,'mobile list has no horizontal overflow');
 if(process.env.WI_SCREENSHOT_DIR)fs.writeFileSync(path.join(process.env.WI_SCREENSHOT_DIR,'bots-mobile.png'),(await window.webContents.capturePage()).toPNG());
 await click('Manage bot');assert.equal(await js('document.documentElement.scrollWidth<=window.innerWidth'),true,'mobile detail has no horizontal overflow');
 const calls=await js('calls');assert(calls.every(c=>c.options.headers.requesttoken==='test-csrf'));assert.equal(calls.filter(c=>c.url.endsWith('/rotate')).length,1);
 await js('window.admin=false;window.bots=[{id:"paused",name:"Paused bot",ownerUid:"alice",enabled:false,adminDisabled:true,connections:[]}]');await js(source);await wait();await js('{const search=document.querySelector("input[type=search]");search.value="undefined";search.dispatchEvent(new Event("input"));}');assert.match(await text(),/No bots match/);await js('{const search=document.querySelector("input[type=search]");search.value="";search.dispatchEvent(new Event("input"));}');await click('Manage bot');await js('document.querySelector(".wi-settings").open=true');assert.match(await text(),/Disabled by an administrator/);assert.doesNotMatch(await text(),/Enable bot|Transfer ownership/);
 await js('window.failRead=true');await js(source);await wait();assert.equal(await js('document.getElementById("wi-status").textContent'),'Read unavailable.');
 console.log('PASS: focused navigation, search, create, connect, inline credentials, dismissal guard, rotation, scoped channel moderation, failure recovery, role restrictions, XSS, CSRF, mobile overflow');app.exit(0);
}).catch(error=>{console.error(error);app.exit(1);});
