# Company extensions and deployment work

This repository is a fork of [Nextcloud Server](https://github.com/nextcloud/server).
The `company/34` branch starts at upstream `v34.0.4`, matching the application version used when this branch was created. Upstream `master` is retained for reference; it is not a production deployment target.

## Extension boundary

Company functionality lives under `custom_apps/`. No upstream core files are changed by the initial company commit. Preserve this boundary when adding features; use Nextcloud app APIs and events rather than patching core.

- `custom_apps/workspace_invites`: administrator-only Copy password-setup link action. Uses native reset tokens, CSRF checks, recent password confirmation and rate limiting. It does not send invitation email. See the app README for restrictions and verification.
- `custom_apps/workspace_onboarding`: visible browser notification permission onboarding for signed-in users. Requests browser permission only after an explicit click, respects granted/denied states, and supports a seven-day dismissal.

To deploy this app into an existing Nextcloud installation, copy only `custom_apps/workspace_invites` to a configured custom-app directory, apply the web-server user's ownership, then run `php occ app:enable workspace_invites` as that user. Do not replace a production installation with this source checkout: Nextcloud's source repository requires its own build/dependency steps.

## Updates and validation

Track upstream security releases and test each version before rebasing or merging it into the company branch. The extension currently declares support for Nextcloud34 only.

For extension changes, run PHP syntax checks on its PHP files and JavaScript syntax checks on `js/users.js`. Live acceptance uses disposable users only and must cover administrator-only access, CSRF rejection, password confirmation, link replacement and expiry, successful setup and reuse rejection. No real user's password should be changed by automated tests.

The initial extension was tested against Nextcloud34.0.4 with disposable accounts before import. The original deployment acceptance harness remains in the private operational workspace; it is not a standalone test included in this repository.

## Production Talk work

### Notification sound defaults

Both Notifications app defaults (`sound_notification` and `sound_talk`) are enabled on our instance. Existing accounts were updated once on2026-09-28. These are supported application preferences, not core patches. New accounts inherit the defaults; users can later opt out.

`company/notification-sounds.php` inspects effective preferences by default. To initialize a new deployment or explicitly migrate existing accounts, run it as the web-server user using PHP CLI with `--apply`. Set `NEXTCLOUD_ROOT` if the installation is not `/var/www/html`. Back up the current preferences before applying; rerunning with `--apply` intentionally overrides existing users' sound choices. Do not schedule it as a recurring enforcement job. Reload existing browser tabs to load the changed settings. Each browser still needs notification permission; the server cannot grant that permission.

Real-time delivery is deployment infrastructure, separate from our extension code. Track HPB/WebSocket signaling, Client Push, TURN/media connectivity, notification acceptance, backups and security in repository issues. A desktop client alone does not configure the server backend.

Secrets belong in deployment secret storage. Never commit database credentials, reset links, API keys, SSH keys or webhook secrets. The GitHub Push webhook is managed in repository settings, outside the source tree.
