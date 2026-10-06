/* SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
 'use strict';
 // A stable Nextcloud navigation entry is available everywhere. On Talk web, also
 // offer a direct link beside the conversation navigation without modifying core.
 const add = () => {
  if (!document.body.dataset || !document.querySelector('#app-content-vue, #app-content')) return;
  const navigation = document.querySelector('#app-navigation-vue .app-navigation-entry__utils')?.closest('#app-navigation-vue') || document.querySelector('#app-navigation-vue');
  if (!navigation || document.getElementById('wi-talk-entry') || !document.querySelector('[data-app-id="spreed"], #app-content-vue')) return;
  const link = document.createElement('a'); link.id='wi-talk-entry'; link.textContent='Integrations'; link.href=OC.generateUrl('/apps/workspace_integrations/'); link.style.cssText='display:block;padding:12px 20px'; navigation.append(link);
  observer.disconnect();
 };
 if (!location.pathname.match(/\/(?:apps\/spreed|call)(?:\/|$)/)) return;
 const observer=new MutationObserver(add); observer.observe(document.body,{childList:true,subtree:true}); add();
 window.addEventListener('pagehide',()=>observer.disconnect(),{once:true});
})();
