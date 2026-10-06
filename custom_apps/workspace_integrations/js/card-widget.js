/* SPDX-License-Identifier: AGPL-3.0-or-later */
(function () {
	'use strict';
	var TYPE = 'workspace_notification_card';
	var contexts = new WeakMap();
	function appUrl(path) { return window.OC && window.OC.generateUrl ? window.OC.generateUrl(path) : '/index.php' + path; }
	function frame(ctx, visible) {
		var parent = ctx.host.closest && ctx.host.closest('.widget-custom');
		(parent || ctx.host).style.display = visible ? '' : 'none';
	}
	function stop(ctx) {
		if (!ctx) return;
		ctx.destroyed = true;
		clearTimeout(ctx.timer);
		clearTimeout(ctx.nextRead);
		ctx.controller.abort();
	}
	function mount(host, args) {
		stop(contexts.get(host));
		host.classList.add('workspace-notification-card-host');
		host.textContent = '';
		var id = args && args.richObject && args.richObject.cardId;
		var ctx = { host: host, controller: new AbortController(), destroyed: false, timer: null, nextRead: null, missingReads: 0, rendered: false };
		contexts.set(host, ctx);
		frame(ctx, false);
		if (typeof id !== 'string' || !/^[A-Za-z0-9_-]{16,128}$/.test(id)) return;
		function read() {
		if (ctx.destroyed) return;
		ctx.controller = new AbortController();
		ctx.timer = setTimeout(function () { ctx.controller.abort(); }, 5000);
		fetch(appUrl('/apps/workspace_integrations/api/cards/' + encodeURIComponent(id)), {
			method: 'GET', credentials: 'same-origin', cache: 'no-store', signal: ctx.controller.signal,
			headers: { Accept: 'application/json', requesttoken: (window.OC && window.OC.requestToken) || document.head.dataset.requesttoken || '' },
		}).then(function (response) {
			if (ctx.destroyed) return null;
			if (response.status === 404) {
				host.textContent = ''; ctx.rendered = false; frame(ctx, false);
				// Delivery receipt may commit just after Talk first renders the link.
				if (ctx.missingReads < 2) ctx.nextRead = setTimeout(read, [1000, 3000][ctx.missingReads++]);
				return null;
			}
			if (!response.ok) throw new Error('Notification card could not be loaded');
			return response.json();
		}).then(function (card) {
			if (ctx.destroyed || !card) return;
			ctx.missingReads = 0;
			if (!ctx.rendered) {
				host.textContent = '';
				host.appendChild(window.WorkspaceNotificationCard.render(card));
				ctx.rendered = true;
			}
			frame(ctx, true);
			// Revalidate access while mounted; never cache member-only content.
			ctx.nextRead = setTimeout(read, 30000);
		}).catch(function () {
			if (ctx.destroyed) return;
			host.textContent = '';
			ctx.rendered = false;
			var error = document.createElement('p');
			error.className = 'workspace-card-error';
			error.setAttribute('role', 'alert');
			error.textContent = 'Notification could not be loaded. Reopen it to try again.';
			host.appendChild(error);
			frame(ctx, true);
		}).finally(function () { clearTimeout(ctx.timer); });
		}
		read();
	}
	function destroy(host) {
		stop(contexts.get(host));
		// Reference integrations may pass the outer widget frame on destruction.
		host.querySelectorAll('.workspace-notification-card-host').forEach(function (child) { stop(contexts.get(child)); });
		host.textContent = '';
	}
	var options = { hasInteractiveView: false, fullWidth: true, isResizable: false };
	window._vue_richtext_widgets = window._vue_richtext_widgets || {};
	if (typeof window._registerWidget === 'function') window._registerWidget(TYPE, mount, destroy, options);
	else if (!window._vue_richtext_widgets[TYPE]) window._vue_richtext_widgets[TYPE] = Object.assign({ id: TYPE, callback: mount, onDestroy: destroy }, options);
	function standalone() {
		document.querySelectorAll('[data-workspace-card-id]').forEach(function (host) {
			mount(host, { richObject: { cardId: host.dataset.workspaceCardId } });
			window.addEventListener('pagehide', function () { destroy(host); }, { once: true });
		});
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', standalone, { once: true });
	else standalone();
})();
