# Workspace invitation links

Local Nextcloud 34 extension. On the administrator Accounts/Users page, open an account's actions menu and choose **Copy password-setup link**. Select **Generate link**, complete Nextcloud's password confirmation when prompted, then copy the link and share privately. No email is sent. An account needs its email field filled in because Nextcloud 34's native reset page errors on null email; an active mailbox or configured SMTP server is not required to generate/use the link.

The extension uses the existing user-list action hook, a typed settings event and Nextcloud's public verification-token service. Core files are unchanged. App files live under the MSA-backed `/var/www/html/custom_apps/workspace_invites` directory. Nextcloud major versions after 34 need a compatibility review before raising the declared maximum version.

## Access and token behavior

- Only full instance administrators can generate links; group administrators and ordinary users are rejected.
- POST requires normal Nextcloud CSRF protection and recent native administrator password confirmation. Nextcloud's normal authentication/SSO rules apply.
- Targets must be enabled local Database accounts with password-changing capability. External identity-provider accounts are excluded.
- Uses native `lostpassword` tokens. Nextcloud 34's lifetime is seven days. A newer reset link replaces the old one; successful use, subsequent account login or an email-address change also invalidates it.
- Generation does not change the password, bypass MFA or enable a disabled account.
- HTTP response is `no-store`. The dialog clears its displayed value when closed; the clipboard is controlled by the user/browser.
- Audit entries record actor, target and operation at warning level so they are retained at the default server log threshold. The extension does not log the URL/token. Native reset URLs can still appear in ordinary infrastructure access logs, as with emailed reset links.
- Per-administrator rate limit: 20 requests per five minutes. Local password-reset disable/external-redirect configuration is respected.

Source: AGPL-3.0-or-later. Disable with `php occ app:disable workspace_invites` inside the application container. This removes the UI/API feature; native reset tokens already generated retain normal Nextcloud validity.

## Verification

`scripts/test-workspace-invites.py` exercises the real public browser UI and endpoint against disposable users. It reads administrator credentials from stdin, never logs URLs/passwords, and removes its fixture account. It requires Python requests and the temporary Playwright test installation. Do not use production users as test fixtures.

Acceptance passed 2026-09-28: Accounts action registration, explicit generation dialog, copy/fallback and close cleanup, CSRF rejection, anonymous/non-admin rejection, nonexistent-account rejection, no-store response, replacement-token invalidation, successful native password reset and used-token rejection. Disposable test users were removed. Production user passwords were unchanged.
