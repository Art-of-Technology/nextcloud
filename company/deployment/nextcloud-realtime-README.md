# Real-time companion deployment templates

These templates document reusable Client Push and staged Talk HPB configuration.
They do not claim that a specific installation is running or has passed acceptance.
Keep hostnames, addresses, network assignments, mount paths, backups and operational
evidence outside the source checkout. Example public endpoints are
`https://nextcloud.example/push` and
`wss://nextcloud.example/standalone-signaling/spreed`.

Copy `.env.example` outside the checkout, restrict its permissions, and fill the
variables needed by the selected Compose file. For Client Push:

- `PUSH_PROJECT_NAME` selects the project; use the same value for rollback.
- `PUSH_BINARY_PATH` is the absolute path to the app-provided notify_push binary
  for the host architecture. It is mounted read-only; a missing path must fail.
- `PUSH_ENV_FILE` is an absolute path to a separate private daemon environment
  file containing `DATABASE_URL`, `DATABASE_PREFIX`, `REDIS_URL`, `NEXTCLOUD_URL`
  and `PORT`. Keep credentials out of the Compose interpolation file when possible.
  `NEXTCLOUD_URL` must reach the application through its configured internal alias;
  set `PORT` to the daemon port used by the reverse proxy.
- `BACKEND_NETWORK` and `PROXY_NETWORK` name existing external networks.
- `PUSH_INTERNAL_NETWORK` names the dedicated existing internal network;
  `PUSH_INTERNAL_IP` must be a free static address within its configured subnet.

Persist the application attachment and callback alias on that dedicated network
in the application's private deployment configuration. Trust only the actual
immediate proxy and push addresses in Nextcloud; allow the public hostname and
callback alias without broadly trusting the subnet. Reserve static addresses to
prevent collisions. Keep the selected network subnet and application address in
private configuration, not this repository.

Validate with `docker compose --env-file /path/to/private.env -f
company/deployment/nextcloud-push.compose.yaml config --quiet` (on one line).
Replace the example path locally. This command does not start containers.
After a notify_push app update, recreate the daemon and rerun native setup checks
for Redis, database, application connection, exact proxy trust and version match.
Host-specific reverse proxy routes can strip `/push` and `/standalone-signaling`;
configure their service targets and ports privately. No public daemon port is
published by the push template.

See [HPB staging](nextcloud-hpb-README.md) for signaling and media acceptance.
Keep the global Talk HPB setting disabled until public TURN/media acceptance.
Test notification delivery with disposable users; browser and OS notification
permissions require client acceptance. Single latency observations do not prove
load capacity or latency percentiles.

Before deployment, protect application configuration and preference backups in a
private location. Roll back Client Push by clearing its configured endpoint with
its supported reset command, stopping only its companion project, removing its
route, and restoring prior proxy/domain configuration from the protected backup.
Preserve the application database, storage mounts and custom apps. Never stop
unrelated Compose projects as part of this rollback.
