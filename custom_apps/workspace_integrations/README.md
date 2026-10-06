# Notification integrations

Self-service notification bots for Nextcloud 34 and Talk 24. Regular registered users manage integrations they own; instance administrators can manage every integration created through this app. No new user roles are introduced. Existing externally installed bots are not imported, reassigned or changed.

## User flow

Open **Integrations** in Nextcloud navigation or from the Talk web navigation link. Create a notification bot, then connect a conversation you manage. Each connection returns a webhook URL and a credential exactly once. Save the credential in the sender's secret store. Listing integrations never returns credentials or the native Talk bot secret.

**My integrations** contains owned bots. Administrators also have **All integrations**, including ownership transfer and suspension. An administrator suspension cannot be reversed by the owner. Transfer revokes all existing connections and credentials; the new owner reconnects permitted channels. Disabled/deleted owner accounts cannot deliver notifications.

Connecting a conversation requires current local membership and Talk's existing moderator permission. Instance administrator status does not bypass that channel check. **Channel connections** lets a conversation manager remove a connected bot without becoming its owner or accessing its credentials. These managed bots use Talk's no-GUI-setup state, so attachment/removal must use this app, not Talk's native Bots toggle. Federated rooms, guests and direct-message conversations are excluded.

The companion desktop menu opens this authenticated management page in the system browser. It never places account credentials in a URL; a separate browser login may be necessary. Desktop management is not an embedded native screen in this release.

## Webhook contract

POST to the connection URL with `Authorization: Bearer <credential>` and `Content-Type: application/json`:

```json
{"text":"Operation completed","eventId":"source-event-123"}
```

`message` is accepted instead of `text`. A connection fixes the destination; the sender cannot choose another channel. Plain text only: Slack blocks/attachments and interactive callbacks are not supported. No arbitrary outgoing webhook URL is registered. Notification-only integrations do not listen to channel messages.

Use a stable `eventId` for retries. A successful duplicate returns its original message ID. Reusing an event ID with different text returns 409. Requests without an event ID are independent deliveries. The app reserves an event before sending; a pending or uncertain outcome is never automatically sent again. Inspect the destination on an uncertain response rather than inventing a new event ID. This is synchronous delivery with an idempotency ledger, not a background queue or guaranteed exactly-once transport.

The conservative uncertain state also covers authorization or credential changes after a reservation. Such an event is not automatically replayed after access is restored. Distinguishing a safely retriable pre-send failure requires a separate durable retry state and concurrency qualification; exceptions from Talk itself can happen after message storage and must never imply that nothing was sent.

Rotation immediately invalidates the old credential. Disconnection revokes it. Messages are limited to 16,000 UTF-8 bytes; connection delivery is limited to 60 new events/minute, with additional request rate limits. Integration and connection creation have abuse caps. No message body or credential is written to the app's delivery/audit tables. Delivery records retain hashes, status and message IDs; they are currently retained indefinitely to preserve deduplication. Monitor storage growth before a large rollout.

## Installation and rollback

Copy only this app directory to a configured custom-app directory and run `occ app:enable workspace_integrations` as the server user. The app creates five `wi_` tables and response-only native Talk bots on demand; it does not patch Nextcloud or Talk core. Verify on an isolated server before deployment. Disabling the app removes the UI and webhook endpoints without deleting retained records. Re-enabling restores existing integration configuration; no new credentials are revealed. Do not drop tables to roll back a populated deployment.

This is intentionally a notification-only first release. Interactive bot registration, owner collaborators, delivery replay controls and automatic retention cleanup are outside this change. All authorization is enforced server-side; hiding controls is not the access-control boundary.

## Validation

- PHP syntax checks and PHPUnit backend tests under `tests/backend*`.
- `node tests/discover.mjs`: Talk navigation insertion stops its DOM observer after success.
- `electron tests/ui-smoke.cjs`: isolated real-renderer UI test, blocks external network.
- `node tests/http.mjs`: real Nextcloud/Talk acceptance on loopback only. Set `PLAYWRIGHT_MODULE` to an installed Playwright module if necessary and `INTEGRATIONS_TEST_CONFIG` to an untracked JSON file with `url` and `users` (`alice`, `bob`, `carol`, `admin`, each with `password`). Install Playwright/Chromium outside this app. Optional `INTEGRATIONS_EVIDENCE_DIR` saves screenshots and credential-free results. The test creates conversations/bots and posts dummy messages only on the isolated server; use disposable accounts and volumes.

Do not commit test configuration, real server addresses, tokens or screenshots containing production identities.
