// SPDX-License-Identifier: AGPL-3.0-or-later
// Run: electron tests/ui-smoke.cjs (no production network or account required).
const {app,BrowserWindow}=require('electron');
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),assert=require('node:assert/strict');
app.setPath('userData',fs.mkdtempSync(path.join(os.tmpdir(),'integrations-ui-test-')));
app.whenReady().then(async()=>{
 const window=new BrowserWindow({show:false,webPreferences:{contextIsolation:true}});
 window.webContents.session.webRequest.onBeforeRequest({urls:['http://*/*','https://*/*']},(_details,callback)=>callback({cancel:true}));
 await window.loadURL('data:text/html,<div id="wi-status"></div><div id="wi-app"></div>');
 await window.webContents.executeJavaScript(`
 window.OC={generateUrl:p=>p,requestToken:'test-csrf'};window.calls=[];window.confirm=()=>true;
 window.fetch=async(url,options)=>{
 calls.push({url,options});let result;
 if(url.endsWith('/api/channels'))result={channels:[{token:'room1234',name:'Audit'}]};
 else if(url.endsWith('/rotate'))result={credential:'one-time-test-credential',webhookUrl:'https://chat.example/hooks/connection'};
 else if(options.method==='PATCH')result={updated:true};
 else result={isAdmin:false,integrations:[{id:'integration',name:'<img src=x onerror=alert(1)>',description:'text',ownerUid:'alice',enabled:true,connections:[{id:'connection',token:'room1234',name:'Audit',lastStatus:'delivered'}]}]};
 return {ok:true,json:async()=>result};};void 0;`);
 const source=fs.readFileSync(path.join(__dirname,'../js/integrations.js'),'utf8');
 await window.webContents.executeJavaScript(source);await new Promise(r=>setTimeout(r,150));
 assert.equal(await window.webContents.executeJavaScript('document.querySelectorAll("img").length'),0,'untrusted names never become HTML');
 let text=await window.webContents.executeJavaScript('document.body.innerText');assert.match(text,/My integrations/);assert.doesNotMatch(text,/All integrations|Transfer ownership/);
 await window.webContents.executeJavaScript('Array.from(document.querySelectorAll("button")).find(b=>b.textContent==="Rotate webhook credential").click()');await new Promise(r=>setTimeout(r,150));
 assert.equal(await window.webContents.executeJavaScript('document.querySelector("#wi-secret input:last-of-type")!==null'),true);
 assert.equal(await window.webContents.executeJavaScript('Array.from(document.querySelectorAll("#wi-secret input")).some(i=>i.value==="one-time-test-credential")'),true);
 await window.webContents.executeJavaScript('Array.from(document.querySelectorAll("button")).find(b=>b.textContent==="I have saved it").click()');
 assert.equal(await window.webContents.executeJavaScript('document.querySelector("#wi-secret")'),null);
 const calls=await window.webContents.executeJavaScript('calls');assert(calls.every(c=>c.options.headers.requesttoken==='test-csrf'));assert.equal(calls.filter(c=>c.url.endsWith('/rotate')).length,1);
 await window.webContents.executeJavaScript('void (window.fetch=async(url)=>({ok:true,json:async()=>url.endsWith("/api/channels")?{channels:[]}:{isAdmin:false,integrations:[{id:"blocked",name:"Paused bot",ownerUid:"alice",enabled:false,adminDisabled:true,connections:[]}]}}))');
 await window.webContents.executeJavaScript(source);await new Promise(r=>setTimeout(r,100));
 const suspendedText=await window.webContents.executeJavaScript('document.body.innerText');assert.match(suspendedText,/Disabled by an administrator/);assert.doesNotMatch(suspendedText,/Enable integration/);
 await window.webContents.executeJavaScript('void (window.fetch=async()=>({ok:true,json:async()=>({isAdmin:true,integrations:[],channels:[]})}))');
 await window.webContents.executeJavaScript(source);await new Promise(r=>setTimeout(r,100));
 assert.match(await window.webContents.executeJavaScript('document.body.innerText'),/All integrations/);
 await window.webContents.executeJavaScript('void (window.fetch=async()=>({ok:false,json:async()=>({error:"Access denied."})}))');
 await window.webContents.executeJavaScript(source);await new Promise(r=>setTimeout(r,100));assert.equal(await window.webContents.executeJavaScript('document.getElementById("wi-status").textContent'),'Access denied.');
 console.log('PASS: owner/admin views, XSS escaping, credential dismissal, CSRF header, single rotation, errors');app.exit(0);
}).catch(error=>{console.error(error);app.exit(1);});
