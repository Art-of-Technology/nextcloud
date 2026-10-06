# Talk HPB staging template

The pinned official AIO Talk image bundles signaling, NATS, Janus and TURN.
This file describes a reusable template, not the state of a running deployment.
Keep host identities, exact network assignments, credentials, image locks and
operational evidence in private configuration outside the source checkout.

Copy `.env.example` to a private file outside the checkout, restrict its permissions
(for example mode 0600 on Linux), and fill every variable required by the selected
Compose file. Generate three independent secrets. Set `NC_DOMAIN` to the actual
Nextcloud hostname; `nextcloud.example` is a documentation example only.
`HPB_PROJECT_NAME` identifies the Compose project and `PROXY_NETWORK` names an
existing external reverse proxy network. Do not print the private environment.

Validate with `docker compose --env-file /path/to/private.env -f
company/deployment/nextcloud-hpb.compose.yaml config --quiet` (on one line).
The example path must be replaced locally. Validation is not deployment approval.

The signaling listener publishes only `127.0.0.1:${HPB_LOOPBACK_PORT}:8081`.
Preserve that loopback binding. The reverse proxy can reach service `hpb` on
port 8081 through the configured proxy network. The WebSocket endpoint is
`/spreed` and HTTP welcome is `/api/v1/welcome`. A public route may strip a
`/standalone-signaling` prefix. Routing and certificates are managed separately.
Avoid duplicate service aliases if sharing a network with another HPB project.

The template publishes no TURN or media ports. `TURN_DOMAIN=hpb` and
`TALK_HOST=hpb` are deliberately local service names for staging. Design and test
the external media path before changing them or configuring a global Talk HPB.
Do not direct public users to private ICE candidates. An HTTP tunnel alone does
not provide public TURN UDP media.

The initial resource limits are one CPU and 1 GiB. Load test before wider use.
Required acceptance includes the local welcome response, a WebSocket upgrade,
correct-HMAC acceptance and incorrect-HMAC rejection, actual Nextcloud user
authentication, public TURN relay connectivity, an external-network call, and
concurrency/resource testing. Signaling health alone does not prove media works.
Keep secrets and acceptance evidence private; no live qualification is implied.

To roll back a staged service, run Compose down with the same private environment
file and this Compose file, scoped to its project. Retain protected configuration
and image locks. Restore any separately changed application settings and routes
from the private operational record.
