# Repository guidance

## Upstream review

Use the repository-local [Talk upstream review skill](.agents/skills/talk-upstream-review/SKILL.md)
and its [server/web profile](.agents/skills/talk-upstream-review/repo-context.md).
At session start, inspect the review records under `docs/upstream/`. Run a review
when the last complete review is missing or older than seven days. Review again
before a release or when a relevant security fix is reported, regardless of age.
An incomplete attempt does not reset the cadence. These are session triggers,
not a scheduler or permission to register automation.

Review produces evidence and recommendations only. It does not authorize merges,
dependency updates, deployment changes, or changes in another repository.
Keep Nextcloud Server and Talk web (`nextcloud/spreed`) evidence and baselines
separate; never infer the deployed Talk version from this server checkout.

## Extension and deployment boundaries

Preserve supported app APIs/events and the `custom_apps/` extension boundary.
The integration line is `company/34`; upstream `master` is reference material,
not the deployment target. Verify current refs and compatibility before proposing
changes. A future-major merge requires a separate migration decision.

Keep committed additions brand-neutral. Deployment identities, hosts, secrets,
branding configuration and private operational evidence belong outside committed
source. Do not copy sensitive details from historical deployment notes into reviews.
Use isolated fixtures for acceptance tests; never change real users' credentials.
