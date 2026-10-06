# Upstream baseline review — 2026-10-06

<!-- SPDX-FileCopyrightText: 2026 Fork contributors -->
<!-- SPDX-License-Identifier: MIT -->

Status: PARTIAL. No upstream adoption or runtime qualification is implied.

## Scope and evidence

Fork `Art-of-Technology/nextcloud`, default/integration branch `company/34`,
reviewed source head `457c1280381b4c1c3ae55714e859a60a6deabd02`.
The consolidation PR targets `main`; the default branch was verified via GitHub.
`version.php` declares Server 34.0.4. Local tag baseline resolves to
`77c7284fed1f5c7b4bce1a94299e229f3281b606` and is an ancestor of this head.

Official `nextcloud/server` refs resolved using `git ls-remote`:

- `stable34`: `43be973499e2d8dcef0ad5065074c2e41944ec0b`.
- `master`: `9acc989dfa09a5df32ea8243af149f9998bdc7bd`.

The checkout reports shallow history. A dedicated `--no-tags` stable34 fetch
was stopped after it did not finish during this bounded review. No complete
baseline-to-tip commit enumeration or patch assessment was performed: assessed
upstream commits: zero; total remaining: unknown. Do not use these resolved
refs as completed-review cursors or infer security coverage from them.

The official latest-release API reported v35.0.1, published 2026-09-24:
https://github.com/nextcloud/server/releases/tag/v35.0.1
This establishes release metadata only; release notes were not fully assessed.
A bounded request to the official server security-advisories endpoint returned
no entries. This does not establish the absence of advisories; centralized
Nextcloud advisories and affected-version ranges remain to be reviewed.
Sources checked on 2026-10-06:
https://api.github.com/repos/nextcloud/server/releases/latest and
https://api.github.com/repos/nextcloud/server/security-advisories?per_page=3

Talk web (`nextcloud/spreed`) has no tracked checkout or verified runtime pin
in this review. Its baseline, support ref and tip remain unknown. Existing
historical version statements are not evidence of an installed source pin.

## Recommendations and adoption evidence

Defer upstream adoption until full stable34 history and official advisory
coverage can be assessed against extension APIs and the actual Server/Talk pair.
Do not adopt master or the latest major release as a consolidation side effect.
Revisit both streams at the next upstream review and before release/deployment.

The authorized local change externalizes deployment identity and sanitizes app
author labels. Docker Compose config with dummy inputs passed for both templates;
each required variable was removed in turn and correctly rejected (seven per
template). Loopback-only signaling, read-only push root/binary, missing-bind
failure and network interpolation assertions passed. Both changed app XML files
parsed successfully. A scan of fork additions found no remaining known deployment
identity strings. No service was contacted or deployed; no application logic was
changed. Live acceptance and complete upstream security qualification were not run.

## Ledger update

Create separate Server and Talk streams with explicit gaps. Both complete-review
times and reviewed-through cursors remain null. No adoption decision is marked
merged or released by this report. The local consolidation does not erase private
identities that were present in earlier commits.
