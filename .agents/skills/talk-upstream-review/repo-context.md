# Nextcloud Server and Talk web review profile

## Repositories and baselines

- Fork: `Art-of-Technology/nextcloud`; server parent: `nextcloud/server`.
- At policy creation, fork default and integration line are `company/34`; verify
  current default/release branches at each review. Upstream `master` is not the
  deployment target; branch names alone do not establish the deployed version.
- `company/README.md` records a `v34.0.4` starting point; `version.php` reports
  34.0.4 in this snapshot. Revalidate refs, ancestry and version at each review.
- Track matching upstream `stable34` fixes, releases and security advisories.
  Inspect upstream `master` for relevant developments, but do not blindly merge
  future-major code or raise app compatibility bounds without migration review.
- Talk web is separately maintained at `nextcloud/spreed`. Neither `apps/spreed`
  nor `custom_apps/spreed` is tracked here; `.gitmodules` only lists `3rdparty`.
  Server ancestry cannot establish a Talk web commit, version or deployed state.
- Obtain Talk's installed version and package/source pin from authorized runtime
  evidence (read-only app inventory, installed `appinfo/info.xml`, package digest
  or deployment lock). For a source install, verify its repository and exact SHA.
  A declared compatible version or old acceptance note is not an installed pin.
- Resolve the evidenced Talk version to its official tag/SHA and compatible
  maintenance line. Record unknown or inaccessible evidence as a scoped blocker;
  do not invent a Talk baseline or claim an unverified runtime is current.
- Keep server and `nextcloud/spreed` review records separately scoped by upstream,
  baseline, reviewed tip, timestamp and evidence. Completing server review must
  not advance an incomplete Talk review. Also distinguish source from runtime pins.

## Local behavior and review hotspots

- `custom_apps/workspace_invites`: administrator-only password-setup links.
  Inspect `lib/Controller/LinkController.php` and `js/users.js`: authentication,
  CSRF, recent password confirmation, enabled local accounts, native token expiry,
  replacement/reuse rejection, rate limits and no token leakage must survive.
- `custom_apps/workspace_onboarding`: `lib/Listener/PageListener.php` and
  `js/notifications.js`; authenticated injection, explicit permission gesture,
  granted/denied states, dismissal and explicit reload must preserve user drafts.
- `company/notification-sounds.php` changes preferences only with `--apply`.
  Review default/opt-out semantics; do not run preference migrations as a review.
- `custom_apps/workspace_bot_mentions`: `lib/Middleware/MentionMiddleware.php`
  and `lib/Service/BotSuggestions.php`; preserve local membership, guest/federation
  exclusion, bot availability, collision filtering, human ordering, JSON-only
  response gates, fail-closed behavior and private/no-store responses.
- `custom_apps/workspace_integrations`: inspect `lib/Service/AccountAccess.php`,
  `IntegrationService.php`, `TalkGateway.php`, `lib/Controller/ApiController.php`,
  `lib/Db/Repository.php`, migrations and `js/discover.js`. Preserve owner/admin
  separation, moderator membership checks (no admin bypass), disabled/guest
  denial, suspension, transfer/revocation, one-time credentials, destination scope,
  idempotency and uncertain-delivery handling, CSRF and abuse limits.
- Talk PHP service/model contracts, OCS mention envelopes, navigation hooks and
  account/reset APIs are upgrade-sensitive. Trace changed upstream paths to actual
  callers; generic server test success does not prove these integrations work.
- `company/deployment/` documents Client Push and HPB/signaling separately.
  Verify applicable app/daemon/image pins from authorized evidence; historical
  transport checks do not prove current notifications, login, calls or TURN/media.
- These four tracked custom apps are owned here. Apps deployed from external
  repositories remain separately owned: record their source/pin and hand off
  recommendations; do not edit an external repo as part of this review.

## Validation when an update is separately authorized

- Run PHP/JavaScript syntax checks on affected app files and relevant upstream
  tests selected from the changed paths and repository test configuration.
- Bot mentions: `php custom_apps/workspace_bot_mentions/tests/run.php` (mbstring).
- Integrations: use its `tests/backendTest.php` with `backend-bootstrap.php`,
  `node tests/discover.mjs` and `electron tests/ui-smoke.cjs` from the app directory;
  follow its README for prerequisites and isolated `node tests/http.mjs` acceptance.
- Qualify changed authentication/roles, reset links, notification permissions and
  Talk mention/delivery flows against the actual supported Server/Talk pair.
  Invitations' referenced operational acceptance script is not tracked here;
  do not report it as run. Use authorized disposable accounts/volumes only.
- Report missing tools, external harnesses, runtime access and untested clients
  explicitly. Keep credentials, hostnames and production identities out of records.
