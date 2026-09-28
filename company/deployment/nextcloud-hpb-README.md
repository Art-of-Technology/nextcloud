# Talk HPB staging

Official AIO Talk image bundles Spreed signaling 2.1.1, NATS, Janus and TURN.
VM2700 10.34.9.84 stores root-only configuration in `/srv/nextcloud/talk-hpb`
on the verified MSA filesystem. `compose.json` is the live equivalent of the
sanitized YAML here. `.env` contains three independent secrets, mode0600;
never commit or print it. `image.lock` pins the deployed digest.

Container `nextcloud-talk-hpb` publishes only loopback18081 to signaling8081.
Traefik can reach8081 via the external `dokploy-network`; WebSocket endpoint is
`/spreed`, HTTP welcome `/api/v1/welcome`. A public route may strip a
`/standalone-signaling` prefix. Shared network routing is managed separately.

The service is staged, not configured as Nextcloud's global Talk HPB. There
are no published TURN or media ports. `TURN_DOMAIN=hpb` is deliberately local
for staging: replace only after the real external media path is designed and
tested. Do not direct public users to these private ICE candidates.

Initial budget1CPU/1GiB on a4CPU/8GiBVM; preflight6.9GiB available. This is not
a demonstrated company call-capacity guarantee. Load test before wider rollout.

Validation: image health healthy, local welcome200, WebSocket101, internal
authentication accepted with correct HMAC and rejected with incorrect HMAC.
`scripts/verify-hpb.py` reads root-only secrets locally and prints no tokens.
This proves the signaling transport/auth mechanism, not user login or media.

Pending: external WSS path, actual Nextcloud user authentication, public TURN
and relay connectivity, external-network call test, concurrency/resource test.
Cloudflare's existing HTTP tunnel alone does not carry TURN UDP media.

Rollback staged service: run Docker Compose down for this project only;
retain `.env`, image.lock and configuration. No Nextcloud global setting has
been modified by this staging script.
