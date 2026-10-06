---
name: talk-upstream-review
description: Review upstream changes for this customized Talk fork, assess security and compatibility, recommend selective cherry-picks or attributed manual adaptations, and maintain a review ledger. Use for upstream checks, security fixes, release preparation, or a stale session-start review.
---

# Selective upstream maintenance

This fork is an independent product. Upstream is a source of reviewed improvements, not a branch to synchronize blindly. Read [repo-context.md](repo-context.md), root AGENTS.md, and current customization/build guidance before reviewing.

## Trigger and authority

- At the start of repository work, inspect [the ledger](../../../docs/upstream/review-ledger.json). If a relevant stream has never had a complete review, its last complete review is older than seven days, or a deferred decision is due, proactively perform a read-only review and offer the useful candidates in chat.
- Perform a review before preparing a release, including fresh checks of relevant official security advisories, and review promptly when a relevant advisory/fix is reported. A weekly check is not a promise that all security fixes have been discovered.
- Review only once per active session unless new upstream evidence or scope warrants another pass. Keep unrelated urgent work moving; report an incomplete/deferred scan honestly.
- This skill creates no scheduler. It runs only when an agent is active, unless the user separately requests scheduled execution.
- Inspection, fetches and recommendations do not authorize adoption, merging, deployment, release publication or changes to the upstream repository. Use existing user authorization for implementation; do not ask again for a selection already approved. If no adoption scope is authorized, present the choices before implementing them.
- Never push to upstream or open an upstream PR as part of maintaining this fork. Never use GitHub "Sync fork", reset a custom branch to upstream, or bulk merge/rebase upstream as a shortcut. A separately requested broad integration still requires the same compatibility assessment.

## Establish the actual baseline

1. Record the clean/dirty status, fork repository, intended integration branch and exact fork SHA. Preserve unrelated local changes with an isolated worktree when needed. Verify the live default branch and release branch; do not assume a branch named main contains our releases.
2. Verify the upstream repository and release/support line from the profile and source. The upstream default development branch may target a different major version. Record each independently versioned source, such as the server, Talk web frontend or bundled client, as a separate stream.
3. Resolve and record an immutable upstream tip SHA and baseline SHA for each stream. Fetch only from verified official upstreams; never let a fetch overwrite fork branches or local release tags. Use dedicated remote-tracking refs and explicit branch fetches with --no-tags; fetch needed tags into a separate namespace.
4. For an established stream, use its complete-review cursor as the enumeration boundary, not proof of adoption. For the first review, find the fork point/merge base or the actually pinned source release. Verify enough history is present: shallow clones must be deepened or inspected with complete paginated upstream history. Never initialize the cursor to today's upstream tip just to hide the backlog.
5. Enumerate all commits in the defined range, including merge/backport dependencies, and inspect relevant release notes and official security advisories. For a Git stream, use git log --reverse --topo-order <baseline>..<tip>, then git show and targeted file/history reads. Date-only windows, first-page API results, or title-only scans are not complete reviews.
6. If history is rewritten, the cursor is missing/not an ancestor, access fails or the scan is bounded, report the gap and retain the last trustworthy cursor. Record partial reviewed ranges and remaining work without claiming completeness. Do not run untrusted upstream scripts just to inspect their changes.

## Assess behaviour, not only merge conflicts

For each candidate, inspect the full relevant patch, upstream issue/PR rationale, prerequisites and any follow-up fixes. Trace the affected behaviour in our current code and the repository's customization map.

- **Adopt unchanged:** the whole patch is applicable, prerequisites are understood, and our regression obligations are preserved. A clean cherry-pick alone is insufficient evidence.
- **Adapt selected changes:** useful fixes are mixed with incompatible product changes or touch code we customized. Identify exactly which behaviour/hunks to retain and omit, and design an equivalent local implementation.
- **Already covered:** cite the local commit/code and a confirming test; absence of a textual conflict does not establish coverage.
- **Defer:** record missing information/dependency, owner decision needed, and a revisit date or trigger.
- **Reject:** give a concrete applicability/product reason and conditions that would change the decision. Rejected and deferred security candidates remain visible with mitigation or residual risk, not silently discarded.

Use ancestry, git cherry/patch-id comparisons and our ledger to find prior backports; manual adaptations may not match upstream hashes and require code-level checks. Never reapply an already integrated fix just because it appears on a second upstream branch.

Prioritize security and data integrity, then reliability, compatibility and useful features. A security label does not prove our version is affected. Cite the official advisory, affected versions/component, our reachable code/configuration where known, urgency and uncertainty. Do not invent CVEs or claim public commit history reveals embargoed fixes.

Review changes to custom authentication, permissions, bots/actions, notifications/audio, calls/windows, branding, telemetry, update feeds and release consent. Also inspect dependency/lockfile changes, build tooling, platform minimums, database/API migrations, cross-client/server compatibility and downgrade effects. Preserve the repo-specific invariants. Escalate security-sensitive conflicts with a proposed adaptation rather than rejecting a necessary fix solely because it conflicts.

## Offer a decision-ready report

Use [REVIEW_TEMPLATE.md](../../../docs/upstream/REVIEW_TEMPLATE.md). For each meaningful candidate include:

- Full upstream SHA and direct commit/PR/advisory links; source repository/support line.
- What it fixes and the concrete benefit for our fork.
- Affected local paths/custom behaviour, prerequisites, conflict and regression risk.
- Recommended disposition and reason; for adaptations, included and deliberately omitted behaviour.
- Proposed tests, platform/environment requirements, rollout implications and remaining unknowns.

Lead the chat summary with urgent items and a short recommendation table. Clearly distinguish inspected evidence from assumptions and a complete scan from a partial one. Do not bury critical findings inside a committed file. Keep real deployment names, hosts, credentials, user data and private screenshots out of public reports, branches and commits.

## Implement only the authorized selection

1. Use a focused branch from the verified fork integration branch and follow its issue/PR/review/check workflow. Preserve our changes; no automatic conflict resolution using all-ours/all-theirs. Include prerequisites only if within the authorized scope, otherwise explain the expansion.
2. For a whole unchanged commit use git cherry-pick -x where appropriate, preserving original attribution and license notices. For a manual adaptation make a new local commit with the actual author; describe the retained fix, omitted upstream behaviour and why adaptation was needed.
3. Include provenance in an adapted commit body, for example:

       Adapt the upstream validation fix to the fork's existing authorization path.
       Preserve the custom interaction flow; omit the upstream UI replacement.
       Upstream-Commit: https://github.com/<upstream>/<repo>/commit/<full-sha>
       Upstream-PR: https://github.com/<upstream>/<repo>/pull/<number>
       Adaptation: explain the intentional differences and their tests

   Include every source commit when several inspired the change. Preserve applicable authorship/copyright/license attribution. Follow the repo's AI disclosure rules; never fabricate a human sign-off.
4. Test both the upstream bug/security property and the affected custom behaviour. Run the repo-required checks and review gates. Explicitly report missing device/OS/signing/live qualification; a desktop check cannot qualify mobile or another OS. Review dependency changes with the same care as application changes.
5. Keep merge, packaging and publication separate. Retain approved branding/configuration externally. Existing release-note approval rules apply unchanged. A merged fix is not a deployed fix.

## Durable tracking

[review-ledger.json](../../../docs/upstream/review-ledger.json) begins empty on purpose: installing this skill does not mean upstream has been reviewed.

Maintain one stream per source repository + support ref + local component. Append a report under docs/upstream/reviews/ with UTC timestamp and fork/upstream immutable SHAs. Advance lastCompleteReviewAt and reviewedThrough only when every commit in that declared range is accounted for (individual decisions or explicitly justified grouped exclusions). Deferred candidates can be enumerated without being resolved; retain them in the open decisions list and revisit them even if the tip has not changed.

Each stream has id, sourceRepository, supportRef, localComponent, baselineSha, reviewedThrough, lastCompleteReviewAt, report and gaps. Use null for unknown cursors/times, never guessed values. Cadence is per stream: missing required streams or any stale stream makes that part due; there is no repository-wide timestamp that can hide an unreviewed component.

Each decision records a stable id, sourceRepository, upstreamShas, disposition, rationale, localPaths, dependencies, advisoryLinks, report path, reviewedAt, revisitAt/revisitTrigger, and adoption state. Adoption state is separate: proposed, authorized, in-progress, merged, or released. Record exact local commit/PR links and check evidence when known. Mark merged/released only after verification; preserve prior reasoning rather than deleting history. Audit reports and ledger changes follow the fork's normal PR workflow.
