/* SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
 'use strict';
 const root=document.getElementById('wi-app'),status=document.getElementById('wi-status');
 if (!root) return;
 let scope='mine', data={isAdmin:false,integrations:[]}, channels=[];
 const el=(tag,text,cls)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(cls)n.className=cls;return n;};
 const encoded=encodeURIComponent;
 const base='/apps/workspace_integrations';
 function message(text,error=false){status.textContent=text;status.className=error?'wi-error':'';}
 async function api(path,method='GET',body){
  const response=await fetch(OC.generateUrl(base+path),{method,credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','requesttoken':OC.requestToken},body:body===undefined?undefined:JSON.stringify(body)});
  let value;try{value=await response.json();}catch{throw new Error('The server returned an unexpected response.');}
  if(!response.ok)throw new Error(value.error||'Request failed.');return value;
 }
 function button(label,action){const b=el('button',label);b.type='button';b.addEventListener('click',async()=>{b.disabled=true;try{await action();}catch(e){message(e.message,true);}finally{b.disabled=false;}});return b;}
 function field(label,value='',kind='input'){const wrapper=el('label',label),input=el(kind);input.value=value;wrapper.append(input);return{wrapper,input};}
 async function load(){
  [data,{channels}]=await Promise.all([api('/api/integrations?scope='+scope),api('/api/channels')]);render();
 }
 function showSecret(result){
  document.getElementById('wi-secret')?.remove();
  const box=el('section',undefined,'wi-secret');box.id='wi-secret';box.append(el('h2','Save this webhook credential now'),el('p','It is shown only once. Send JSON containing "text" and an optional unique "eventId". Use Authorization: Bearer followed by the credential. The destination is fixed to this channel.'));
  for(const [label,value] of [['Webhook URL',result.webhookUrl],['Credential',result.credential]]){
   const f=field(label,value);f.input.readOnly=true;f.input.autocomplete='off';f.input.spellcheck=false;box.append(f.wrapper,button('Copy '+label.toLowerCase(),async()=>{await navigator.clipboard.writeText(value);message(label+' copied.');}));
  }
  box.append(button('I have saved it',()=>{box.replaceChildren();box.remove();}));root.prepend(box);box.scrollIntoView({block:'nearest'});
 }
 function render(){
  root.replaceChildren();
  const tabs=el('div',undefined,'wi-row');
  for(const tab of ['mine',...(data.isAdmin?['all']:[])]){const b=button(tab==='mine'?'My integrations':'All integrations',async()=>{scope=tab;await load();});b.setAttribute('aria-pressed',String(scope===tab));tabs.append(b);}root.append(tabs);
  const create=el('form',undefined,'wi-card'),name=field('Bot name'),description=field('Description','','textarea');name.input.required=true;name.input.maxLength=64;description.input.maxLength=1000;
  const submit=el('button','Create notification bot');submit.type='submit';create.append(el('h2','Create integration'),name.wrapper,description.wrapper,submit);
  create.addEventListener('submit',async e=>{e.preventDefault();submit.disabled=true;try{await api('/api/integrations','POST',{name:name.input.value,description:description.input.value});await load();message('Integration created. Connect a channel to generate its webhook.');}catch(err){message(err.message,true);}finally{submit.disabled=false;}});root.append(create);
  if(!data.integrations.length)root.append(el('p','No integrations in this view.'));
  for(const item of data.integrations){
   const card=el('section',undefined,'wi-card'),prefix='/api/integrations/'+encoded(item.id),n=field('Bot name',item.name),d=field('Description',item.description||'','textarea');n.input.maxLength=64;d.input.maxLength=1000;
   card.append(el('h2',item.name),el('small','Owner: '+item.ownerUid+' · '+(item.enabled?'Enabled':'Disabled')),n.wrapper,d.wrapper);
   card.append(button('Save details',async()=>{await api(prefix,'PATCH',{name:n.input.value,description:d.input.value});await load();message('Details saved.');}));
   if(item.adminDisabled&&!data.isAdmin){card.append(el('p','Disabled by an administrator'));}
   else{card.append(button(item.enabled?'Disable integration':'Enable integration',async()=>{await api(prefix,'PATCH',{enabled:!item.enabled});await load();message('Integration updated.');}));}
   if(data.isAdmin){const owner=field('Transfer to account username',item.ownerUid);card.append(owner.wrapper,button('Transfer ownership',async()=>{if(!confirm('Transfer this integration to the specified account? All existing channel connections and webhook credentials will be revoked. The new owner must reconnect channels.'))return;await api(prefix,'PATCH',{ownerUid:owner.input.value});await load();message('Ownership transferred.');}));}
   card.append(el('h3','Channel connections'));
   for(const c of item.connections||[]){const connection=el('div',undefined,'wi-channel');connection.append(el('p',(c.name||c.token)+(c.enabled?'':' (disconnected or unavailable)')),el('small','Last delivery: '+(c.lastStatus||'No deliveries')+(c.lastAt?' · '+new Date(Number(c.lastAt)*1000).toLocaleString():'')));
    connection.append(button('Rotate webhook credential',async()=>{if(!confirm('Replace this credential? The old credential will stop working immediately.'))return;const result=await api(prefix+'/connections/'+encoded(c.id)+'/rotate','POST',{});try{await load();}finally{showSecret(result);}}),button('Disconnect',async()=>{if(!confirm('Stop delivering notifications to this channel?'))return;await api(prefix+'/connections/'+encoded(c.id),'DELETE');await load();message('Channel disconnected.');}));card.append(connection);}
   const select=el('select');select.setAttribute('aria-label','Channel to connect');const placeholder=el('option','Choose a channel you manage');placeholder.value='';select.append(placeholder);
   for(const c of channels){if((item.connections||[]).some(existing=>existing.token===c.token && existing.enabled))continue;const option=el('option',c.name);option.value=c.token;select.append(option);}
   const connect=button('Connect channel',async()=>{if(!select.value){message('Choose a channel first.',true);return;}const result=await api(prefix+'/connections','POST',{token:select.value});try{await load();}finally{showSecret(result);}});card.append(select,connect);root.append(card);
  }
  const moderation=el('section',undefined,'wi-card');moderation.append(el('h2','Channel connections'),el('p','Remove managed integrations from conversations you moderate. This does not grant access to their settings or credentials.'));
  for(const c of channels){const group=el('div',undefined,'wi-channel');group.append(el('h3',c.name));const items=el('div');group.append(button('Show connected bots',async()=>{const result=await api('/api/channels/'+encoded(c.token)+'/connections');items.replaceChildren();if(!result.connections.length)items.append(el('p','No managed integrations connected.'));for(const connected of result.connections){const row=el('div',undefined,'wi-row');row.append(el('span',connected.name),button('Remove from channel',async()=>{if(!confirm('Remove this bot from the channel?'))return;await api('/api/channels/'+encoded(c.token)+'/connections/'+encoded(connected.id),'DELETE');await load();message('Bot removed from channel.');}));items.append(row);}}),items);moderation.append(group);}
  if(!channels.length)moderation.append(el('p','You currently have no conversations with management permission.'));root.append(moderation);
 }
 load().catch(e=>message(e.message,true));
})();
