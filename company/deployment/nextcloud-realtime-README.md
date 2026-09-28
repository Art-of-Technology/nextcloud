# Real-time deployment state (2026-09-28)

Client Push1.4.1 is enabled and running. Public endpoint:
`https://nextcloud.weezbooapps.com/push`.
All native setup checks pass (Redis, database, app connection, exact proxy trust and version match).

Persistent service configuration is `/srv/nextcloud/client-push/compose.json`,
with root0600 `.env`. It uses the app-store-provided binary mounted read-only.
After a notify_push app update recreate the daemon and rerun setup to verify matching versions.
The dedicated internal Docker network `nextcloud-push-internal` uses172.30.27.0/28:
push172.30.27.2, app172.30.27.3 alias `nextcloud-push-app`.
The app's network attachment is persisted in the existing Dokploy Compose configuration.
Companion services currently use separate on-host Compose projects with restart policies;
they are not yet separate Dokploy UI entries.

`zz-push.config.php` trusts only the existing immediate proxy10.0.1.2 and push172.30.27.2,
and permits the public hostname and private push callback alias.
Host-specific Traefik dynamic routes strip `/push` and `/standalone-signaling`.
The existing manager route forwards both paths to the application VM.
No new inbound public ports were opened.

The staged HPB is reachable over public authenticated WSS at
`wss://nextcloud.weezbooapps.com/standalone-signaling/spreed`.
Valid internal HMAC authentication passes; invalid authentication is rejected.
This is an infrastructure readiness check, not an end-user/media acceptance test.
The global Talk HPB setting remains disabled until public TURN/media connectivity is established.
The current HTTP tunnel cannot provide public TURN UDP. See the separate HPB staging notes.

A disposable-account public browser test discovered a new conversation within5seconds
after Client Push deployment, compared with20seconds in the earlier baseline.
These are single observations, not a latency percentile or load-test result.
Browser and OS sound/popup permissions still require client acceptance.

Config backups before deployment are under `/srv/nextcloud/config-backups/`.
Rollback Client Push: clear its configured endpoint using its supported reset command,
stop only the companion push project, remove its host-specific route, and restore the
prior proxy/domain configuration from the protected backup. Preserve the application database,
MSA mounts and custom_apps/workspace_invites. Do not run unrelated Compose projects down.
