/* SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
    'use strict';
    function start() {
        if (!window.isSecureContext || window.top !== window.self || !('Notification' in window)
            || typeof Notification.requestPermission !== 'function' || Notification.permission === 'granted'
            || document.getElementById('workspace-notification-prompt')) return;
        const key = 'workspace-notification-prompt-snooze-v1';
        try { if (Number(localStorage.getItem(key)) > Date.now()) return; } catch (_) { /* Storage is optional. */ }
        const box = document.createElement('section');
        box.id = 'workspace-notification-prompt';
        box.setAttribute('role', 'region');
        box.setAttribute('aria-label', 'Browser notifications');
        const message = document.createElement('p');
        message.setAttribute('aria-live', 'polite');
        const enable = document.createElement('button');
        enable.type = 'button';
        enable.textContent = 'Enable notifications';
        const dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.textContent = 'Not now';
        function blocked() {
            message.textContent = 'Notifications are blocked in this browser. Use the site controls beside the address bar to allow them, then reload Nextcloud.';
            enable.hidden = true;
            dismiss.textContent = 'Dismiss';
        }
        message.textContent = 'Get alerts for new messages and calls. Enable browser notifications, then choose Allow when your browser asks.';
        if (Notification.permission === 'denied') blocked();
        dismiss.addEventListener('click', () => {
            try { localStorage.setItem(key, String(Date.now() + 7 * 24 * 60 * 60 * 1000)); } catch (_) {}
            box.remove();
        });
        enable.addEventListener('click', () => {
            if (enable.dataset.reload === 'yes') { window.location.reload(); return; }
            if (Notification.permission === 'denied') { blocked(); return; }
            enable.disabled = true;
            // Keep this call directly in the click handler: browsers require user activation.
            let request;
            try { request = Notification.requestPermission(); } catch (_) {
                message.textContent = 'Your browser could not open the request. Use the site controls beside the address bar to allow notifications.';
                enable.disabled = false;
                return;
            }
            Promise.resolve(request).then(permission => {
                if (permission === 'granted') {
                    message.textContent = 'Notifications are allowed. Reload Nextcloud when you are ready to activate alerts in this tab.';
                    enable.textContent = 'Reload Nextcloud';
                    enable.dataset.reload = 'yes';
                    dismiss.textContent = 'Later';
                } else if (permission === 'denied') blocked();
                else message.textContent = 'Permission was not granted. Click Enable notifications to try again, or choose Not now.';
            }).catch(() => {
                message.textContent = 'Your browser could not open the request. Use the site controls beside the address bar to allow notifications.';
            }).finally(() => { enable.disabled = false; });
        });
        box.append(message, enable, dismiss);
        document.body.append(box);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
})();
