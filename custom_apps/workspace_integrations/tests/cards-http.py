# SPDX-License-Identifier: AGPL-3.0-or-later
"""Run only against a disposable loopback Nextcloud. Never print credentials."""
import asyncio, base64, copy, json, os, re, time
from pathlib import Path
from urllib.parse import urlparse, quote
from playwright.async_api import async_playwright

async def main():
    config=json.loads(Path(os.environ['INTEGRATIONS_TEST_CONFIG']).read_text(encoding='utf-8-sig'))
    url=config['url'].rstrip('/')
    assert urlparse(url).hostname in ('localhost','127.0.0.1'), 'Loopback lab only'
    evidence=Path(os.environ['INTEGRATIONS_EVIDENCE_DIR']); evidence.mkdir(parents=True,exist_ok=True)
    checks=[]
    def check(name, condition):
        assert condition, name
        checks.append(name);print('PASS '+name,flush=True)
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))
        sessions={}; room=None; integration=None
        try:
            for uid in ('alice','bob','carol'):
                state=evidence/(uid+'-session.json')
                context=await browser.new_context(storage_state=str(state) if state.exists() else None)
                page=await context.new_page()
                await page.goto(url+'/apps/workspace_integrations/',wait_until='domcontentloaded')
                if '/login' not in page.url:
                    sessions[uid]=(context,page,await page.locator('head').get_attribute('data-requesttoken'))
                    continue
                await page.locator('#user').fill(uid)
                await page.locator('#password').fill(config['users'][uid]['password'])
                await page.locator('button[type=submit]').click()
                await page.wait_for_url(lambda u:'/login' not in u,timeout=60000)
                sessions[uid]=(context,page,await page.locator('head').get_attribute('data-requesttoken'))
                await context.storage_state(path=str(state))
            async def request(uid,method,path,data=None,ocs=False):
                context,_,token=sessions[uid]
                headers={'Accept':'application/json','requesttoken':token or ''}
                if ocs:headers['OCS-APIRequest']='true'
                response=await context.request.fetch(url+path,method=method,data=data,headers=headers)
                try:body=await response.json()
                except Exception:body={}
                return response,body
            async def api(uid,method,path,data=None):
                return await request(uid,method,'/apps/workspace_integrations'+path,data)
            async def talk(uid,method,path,data=None):
                response,body=await request(uid,method,'/ocs/v2.php/apps/spreed/api/'+path,data,True)
                assert response.ok, 'Talk setup status '+str(response.status)
                return body['ocs']['data']
            suffix=str(int(time.time()*1000))
            room=await talk('alice','POST','v4/room',{'roomType':2,'roomName':'Card acceptance '+suffix})
            await talk('alice','POST','v4/room/'+room['token']+'/participants',{'newParticipant':'bob'})
            r,body=await api('alice','POST','/api/integrations',{'name':'Audit acceptance '+suffix,'description':'Disposable test'})
            check('create disposable bot',r.status==200)
            integration=body.get('integration',body)['id']
            r,conn=await api('alice','POST','/api/integrations/'+integration+'/connections',{'token':room['token']})
            check('connect bot to disposable room',r.status==200)
            assert urlparse(conn['webhookUrl']).netloc==urlparse(url).netloc
            anon=await browser.new_context()
            async def hook(payload):
                response=await anon.request.post(conn['webhookUrl'],data=payload,headers={'Authorization':'Bearer '+conn['credential'],'Accept':'application/json'})
                return response,await response.json()
            title='Transfer bonus 15% (531) — sample-user (preview only)'
            slack={'eventId':'card-'+suffix,'text':title,'blocks':[
                {'type':'section','text':{'type':'mrkdwn','text':':moneybag: *'+title+'*'}},
                {'type':'section','fields':[{'type':'mrkdwn','text':'*'+k+':*\n'+v} for k,v in [('Member','sample-user'),('Decision','eligible'),('Provider','101'),('Amount','10.00 EUR'),('Brand','example'),('Event ts','1700000000')]]},
                {'type':'context','elements':[{'type':'mrkdwn','text':'predicates: `{"eligible":true,"segmentIds":[1,2]}`'}]}
            ]}
            r,sent=await hook(slack)
            check('Slack six-field payload delivered',r.status==200 and sent.get('status')=='delivered')
            r,repeat=await hook(slack)
            check('duplicate returns original message',r.status==200 and repeat.get('status')=='duplicate' and repeat['messageId']==sent['messageId'])
            changed=copy.deepcopy(slack);changed['blocks'][1]['fields'][0]['text']='Changed member'
            r,_=await hook(changed);check('changed field with same event rejected',r.status==409)
            custom={'eventId':'custom-'+suffix,'title':'Structured audit','status':'preview','fields':[{'label':'Amount','value':'10.00 EUR'}],'context':{'predicates':{'eligible':True,'segments':[1,2]}}}
            r,customsent=await hook(custom);check('custom card delivered',r.status==200 and customsent.get('status')=='delivered')
            messages=await talk('alice','GET','v1/chat/'+room['token']+'?lookIntoFuture=0&limit=100')
            byid={str(m['id']):m for m in messages}
            text=byid[str(sent['messageId'])]['message']
            match=re.search(r'/apps/workspace_integrations/cards/([a-f0-9]{32})',text)
            check('message retains full fallback plus card link',bool(match) and 'segmentIds' in text and '10.00 EUR' in text)
            cardid=match[1];cardpath='/api/cards/'+cardid;cardpage='/apps/workspace_integrations/cards/'+cardid
            for uid in ('alice','bob'):
                r,card=await api(uid,'GET',cardpath)
                check(uid+' member can read card',r.status==200 and card.get('schemaVersion')==1 and len(card.get('blocks',[]))==3)
                check(uid+' card read no-store','no-store' in r.headers.get('cache-control',''))
            r,_=await api('carol','GET',cardpath);check('nonmember card read 404',r.status==404)
            r,_=await request('carol','GET',cardpage);check('nonmember card page 404',r.status==404)
            basic=await p.request.new_context(extra_http_headers={'Authorization':'Basic '+base64.b64encode(('bob:'+config['users']['bob']['password']).encode()).decode(),'OCS-APIRequest':'true','Accept':'application/json'})
            r=await basic.get(url+'/apps/workspace_integrations'+cardpath)
            check('Basic account-authenticated desktop-path read',r.status==200)
            tokenresponse=await basic.get(url+'/ocs/v2.php/core/getapppassword')
            assert tokenresponse.status==200, 'App password creation failed'
            apppassword=(await tokenresponse.json())['ocs']['data']['apppassword']
            appauth=await p.request.new_context(extra_http_headers={'Authorization':'Basic '+base64.b64encode(('bob:'+apppassword).encode()).decode(),'OCS-APIRequest':'true','Accept':'application/json'})
            r=await appauth.get(url+'/apps/workspace_integrations'+cardpath)
            check('app-password desktop-path read',r.status==200)
            r,reference=await request('alice','GET','/ocs/v2.php/references/resolve?reference='+quote(url+cardpage,safe=''),ocs=True)
            check('reference resolution responds',r.status==200)
            serialized=json.dumps(reference)
            check('reference metadata static without message content','workspace_notification_card' in serialized and cardid in serialized and 'segmentIds' not in serialized and 'sample-user' not in serialized and '10.00 EUR' not in serialized)
            page=sessions['alice'][1]
            errors=[]
            page.on('pageerror',lambda error:errors.append(str(error)))
            await page.goto(url+cardpage)
            await page.locator('.workspace-notification-card').wait_for(timeout=20000)
            wizard=page.locator('#firstrunwizard')
            if await wizard.count():
                await wizard.get_by_role('button',name='Close',exact=True).click()
            await page.locator('.workspace-notification-card summary').first.click()
            visible=await page.locator('.workspace-notification-card').inner_text()
            check('standalone card renders fields and JSON','10.00 EUR' in visible and 'segmentIds' in visible and 'sample-user' in visible)
            await page.screenshot(path=str(evidence/'card-desktop.png'),full_page=True)
            await page.set_viewport_size({'width':390,'height':844})
            check('mobile card stays within viewport',await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth'))
            await page.screenshot(path=str(evidence/'card-mobile.png'),full_page=True)
            await page.set_viewport_size({'width':1280,'height':900})
            await page.goto(url+'/call/'+room['token'])
            await page.locator('.workspace-notification-card').first.wait_for(timeout=45000)
            check('Talk inline card renders',await page.locator('.workspace-notification-card').count()>=1)
            await page.screenshot(path=str(evidence/'card-talk-inline.png'),full_page=True)
            participants=await talk('alice','GET','v4/room/'+room['token']+'/participants')
            bob=next(x for x in participants if x.get('actorId')=='bob')
            await talk('alice','DELETE','v4/room/'+room['token']+'/attendees',{'attendeeId':bob['attendeeId']})
            r,_=await api('bob','GET',cardpath);check('removed member card read 404',r.status==404)
            r=await basic.get(url+'/apps/workspace_integrations'+cardpath);check('removed Basic-auth member read 404',r.status==404)
            r=await appauth.get(url+'/apps/workspace_integrations'+cardpath)
            check('removed app-password member read 404',r.status==404)
            await appauth.delete(url+'/ocs/v2.php/core/apppassword')
            await appauth.dispose()
            await basic.dispose()
            (evidence/'results.json').write_text(json.dumps({'passed':True,'checks':checks},indent=2))
            print('PASS '+str(len(checks))+' isolated HTTP/browser acceptance checks',flush=True)
        except Exception as exc:
            if 'alice' in sessions:
                await sessions['alice'][1].screenshot(path=str(evidence/'failure.png'),full_page=True)
                (evidence/'failure.html').write_text(await sessions['alice'][1].content(),encoding='utf-8')
            if 'errors' in locals():(evidence/'browser-errors.json').write_text(json.dumps(errors))
            (evidence/'results.json').write_text(json.dumps({'passed':False,'checks':checks,'error':str(exc)},indent=2))
            raise
        finally:
            # Preserve disposable lab fixtures for diagnosis; never touch other rooms.
            await browser.close()

asyncio.run(main())
