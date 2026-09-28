# Notification permission onboarding

Nextcloud34 custom app; no core changes. On signed-in HTML pages, a dismissible
banner offers **Enable notifications** if browser permission is undecided.
The browser's native permission request is called only from that button's click.
After Allow, a separate Reload button refreshes Nextcloud's cached permission state;
the app never reloads automatically or discards a user's draft.

Granted permission hides the banner. Denied permission displays manual recovery
instructions (browsers cannot be forced to ask again). Dismissal snoozes the banner
for7days in browser local storage; it is per browser/origin, not an account setting.
HTTPS and a supported Notification API are required; embedded frames are excluded.
Browsers may show a quiet permission indicator instead of a popup.

Install as custom_apps/workspace_onboarding, with web-user ownership, then run
`php occ app:enable workspace_onboarding`. Disable using app:disable.
This app grants no permissions itself and does not change sound/DND preferences.

Validation2026-09-28: PHP lint and JS syntax passed. Public browser acceptance
verified authenticated injection and anonymous exclusion, default/granted/denied
states, request only with active user gesture, explicit reload and dismissal
across reload. Permission API results were simulated for deterministic testing;
the browser-native permission popup itself was not automated. Desktop banner
layout was visually inspected. No production messages were sent.
