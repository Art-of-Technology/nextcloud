/* SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
  'use strict';
  let activeDialog = null;
  const make = (tag, text, className) => {
    const element = document.createElement(tag);
    if (text) element.textContent = text;
    if (className) element.className = className;
    return element;
  };

  function openDialog(event, user) {
    event?.preventDefault();
    if (!user || typeof user.id !== 'string' || !user.id) return;
    if (activeDialog) { activeDialog.focus(); return; }
    const previousFocus = document.activeElement;
    const dialog = make('dialog', '', 'workspace-invites-dialog');
    dialog.setAttribute('aria-labelledby', 'workspace-invites-title');
    dialog.setAttribute('aria-describedby', 'workspace-invites-description');
    const title = make('h2', 'Copy password-setup link');
    title.id = 'workspace-invites-title';
    const account = make('p', `Account: ${user.displayname || user.id} (${user.id})`);
    const description = make('p', 'Generating a link replaces any previous password-setup or reset link for this account. Anyone with the link can set its password. Share it only with the account owner through a private channel.');
    description.id = 'workspace-invites-description';
    const status = make('p', '', 'workspace-invites-status');
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    const label = make('label', 'Password-setup link');
    label.htmlFor = 'workspace-invites-link';
    const input = make('input');
    input.id = 'workspace-invites-link';
    input.type = 'text';
    input.readOnly = true;
    input.autocomplete = 'off';
    input.spellcheck = false;
    const result = make('div', '', 'workspace-invites-result');
    result.hidden = true;
    result.append(label, input);
    const buttons = make('div', '', 'workspace-invites-buttons');
    const generate = make('button', 'Generate link', 'primary');
    const copy = make('button', 'Copy link');
    copy.hidden = true;
    const close = make('button', 'Close');
    for (const button of [generate, copy, close]) button.type = 'button';
    buttons.append(generate, copy, close);
    dialog.append(title, account, description, status, result, buttons);
    document.body.append(dialog);
    activeDialog = dialog;
    let disposed = false;
    let ignoredCloseEvents = 0;
    let busy = false;
    const abort = new AbortController();

    function dispose() {
      if (disposed) return;
      disposed = true;
      abort.abort();
      input.value = '';
      if (dialog.open) dialog.close();
      dialog.remove();
      activeDialog = null;
      if (previousFocus?.isConnected) previousFocus.focus();
    }
    close.addEventListener('click', dispose);
    dialog.addEventListener('cancel', (e) => { e.preventDefault(); dispose(); });
    dialog.addEventListener('close', () => {
      if (ignoredCloseEvents > 0) { ignoredCloseEvents -= 1; return; }
      dispose();
    });
    window.addEventListener('pagehide', dispose, { once: true, signal: abort.signal });

    function confirmPassword() {
      const confirmation = window.OC?.PasswordConfirmation;
      if (!confirmation?.requirePasswordConfirmation) return Promise.reject(new Error('confirmation'));
      // The native modal must not make Nextcloud's password dialog inert.
      ignoredCloseEvents += 1;
      dialog.close();
      return new Promise((resolve, reject) => {
        confirmation.requirePasswordConfirmation(resolve, {}, reject);
      }).finally(() => {
        if (!disposed) dialog.showModal();
      });
    }

    generate.addEventListener('click', async () => {
      if (busy || disposed) return;
      busy = true;
      generate.disabled = true;
      status.textContent = 'Confirm your administrator password if prompted.';
      try {
        await confirmPassword();
        if (disposed) return;
        status.textContent = 'Generating link…';
        const response = await fetch(window.OC.generateUrl('/apps/workspace_invites/link'), {
          method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: abort.signal,
          headers: { 'Content-Type': 'application/json', requesttoken: window.OC.requestToken, Accept: 'application/json' },
          body: JSON.stringify({ userId: user.id }),
        });
        if (response.status === 412) {
          status.textContent = 'Password confirmation expired. Reload the Accounts page and try again; you will be asked to confirm your password.';
          return;
        }
        if (!response.ok) {
          const errorData = await response.json();
          if ([400, 403, 404, 409].includes(response.status) && typeof errorData.message === 'string') {
            status.textContent = errorData.message;
            return;
          }
          throw new Error('request');
        }
        const data = await response.json();
        if (disposed) return;
        const url = new URL(data.url);
        if (url.origin !== window.location.origin || url.protocol !== 'https:') throw new Error('url');
        input.value = url.href;
        result.hidden = false;
        copy.hidden = false;
        generate.hidden = true;
        const days = Number(data.expiresInSeconds) / 86400;
        status.textContent = Number.isFinite(days) && days > 0
          ? `Link generated. Valid for up to ${days} day${days === 1 ? '' : 's'}; using it, signing in, or changing the account email invalidates it. Copy it before closing this dialog.`
          : 'Link generated. Copy it before closing this dialog.';
        copy.focus();
      } catch (error) {
        if (!disposed) status.textContent = 'No link is available. Confirmation may have been cancelled, or the request failed. Try again; generating another link replaces the previous one.';
      } finally {
        busy = false;
        generate.disabled = false;
      }
    });
    copy.addEventListener('click', async () => {
      if (!input.value || disposed) return;
      try {
        await navigator.clipboard.writeText(input.value);
        if (!disposed) status.textContent = 'Copied. Share privately with the account owner. Closing this dialog clears the displayed link, but not your clipboard.';
      } catch (error) {
        if (!disposed) {
          input.focus(); input.select();
          status.textContent = 'Automatic copying is unavailable. The link is selected; press Ctrl+C (or Command+C) to copy.';
        }
      }
    });
    dialog.showModal();
    generate.focus();
  }

  // Nextcloud 34 installs this hook in UserManagement.created(), then emits
  // settings:user-management:loaded on its module event bus (not a DOM event).
  // Bounded polling avoids depending on a bundled event-bus package.
  const deadline = Date.now() + 30000;
  function register() {
    const list = window.OCA?.Settings?.UserList;
    if (typeof list?.registerAction === 'function') {
      list.registerAction('icon-clippy', 'Copy password-setup link', openDialog,
        (user) => typeof user?.id === 'string' && user.id.length > 0);
    } else if (Date.now() < deadline) {
      window.setTimeout(register, 100);
    }
  }
  register();
})();
