# Notification message compatibility

<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

Managed-integration webhooks accept legacy `text` (or `message`), a documented
Slack message subset, and structured cards. The connection determines bot and
conversation. Keep the webhook URL and bearer credential private. `eventId` is
the delivery idempotency key: reuse it only for identical content. Changing any
rich content conflicts; plain-text fingerprints preserve the previous SHA-256.

## Supported subset

| Input | Supported content |
| --- | --- |
| `header` | Plain-text heading |
| `section` | `plain_text`/`mrkdwn` text and up to 10 fields |
| `context` | Up to 10 text elements |
| `divider` | Separator |
| `image` | Public HTTP(S) URL, required alt text, optional title |
| `rich_text` | Sections, bullet/ordered lists, quote, preformatted text |
| Rich inline | Text, links, named emoji; bold/italic/strike/code styles |
| `table` | Equal-width raw-text/rich-text cells; column alignment/wrapping |
| Legacy attachments | Color, pretext, author/title links/icons, text, fields, images, footer/timestamp, supported nested blocks |

Slack markdown is retained for the shared renderer. Slack special identity,
channel, broadcast and date references require mapping and are rejected. Standard
emoji aliases can render as emoji; unknown names remain visible. Unsupported
block types/properties are rejected, including actions, accessories, buttons,
selects, inputs, callbacks, video/file blocks, context images and unsupported
layout hints. This is intentionally not complete Block Kit compatibility.

Legacy colors and content are preserved, without promising Slack's exact layout.
Author/footer icons and thumbnails become ordinary card images. Non-short fields
become full-width sections; short fields use the responsive field grid.

Private Slack file URLs are rejected; use an accessible image URL instead.
URLs must be HTTP(S) without credentials, whitespace or backslashes. The
normalizer does not fetch remote resources. Text is never executable HTML.

## Inputs

```json
{
  "eventId": "audit:operation-123:complete",
  "text": "Operation completed",
  "blocks": [
    { "type": "header", "text": { "type": "plain_text", "text": "Operation completed" } },
    { "type": "section", "fields": [
      { "type": "mrkdwn", "text": "*Decision:*\neligible" },
      { "type": "mrkdwn", "text": "*Amount:*\n10.00 EUR" }
    ] },
    { "type": "context", "elements": [
      { "type": "mrkdwn", "text": "predicates: `{\"eligible\":true}`" }
    ] }
  ]
}
```

The alternative custom format maps to the same renderer. Do not mix its layout
properties with Slack blocks/attachments:

```json
{
  "eventId": "audit:operation-124:complete",
  "text": "Operation completed (preview)",
  "title": "Operation completed",
  "status": "preview",
  "fields": [{ "label": "Amount", "value": "10.00 EUR" }],
  "context": { "predicates": { "eligible": true } }
}
```

Context becomes pretty-printed JSON. Status is generic text, not a worker-specific
hardcoded label. Fallback text includes all fields/context, even if a sender
provides only a summary in top-level `text`. Exact leading summary duplication is
avoided. Thus clients without card rendering retain the information. Slack
formatting delimiters may remain visible in plain-text fallback.

## Bounds and contract

- At most 65,536 JSON bytes, 32 JSON nesting levels, 1,000 validated nodes.
- Nonempty UTF-8 fallback, at most 16,000 bytes, including derived card contents.
  Oversized fallback is rejected rather than truncated.
- Up to 50 root blocks, 10 attachments, 4 rich-text nesting levels.
- Header: 150 bytes; section: 3,000; fields/context: 2,000; URLs: 2,048.
- Tables: up to 100 equal-width rows and 20 columns, subject to total bounds.
- Custom format: 30 fields, 12,000 formatted JSON bytes.
- Control characters, malformed UTF-8, null properties and unknown keys rejected.

`MessageNormalizer::normalize()` returns `text`, `card`, `fingerprint`. Card is
null for plain messages or `{ "schemaVersion": 1, "blocks": [...] }` otherwise.
Canonical blocks retain supported Slack-like properties. Attachments become
`{ "type": "attachment", "color": "good", "blocks": [...] }` (optional color
and fallback). Custom title/status/fields/context map to header/section/field
sections/rich-text-preformatted. Sorted object keys make hashing stable; list
order matters. Event ID is excluded from the content fingerprint.

Authenticated conversation membership must protect every card read; shared
reference metadata contains only card ID. Native mobile layout parity is not
established by server implementation. Full text and the authenticated card page
are its fallback.

Standalone tests (PHP 8.2+):

```sh
php custom_apps/workspace_integrations/tests/message-normalizer.php
```

## Client presentation

Talk web displays the full fallback message followed by the rich reference card.
The fallback is intentionally retained for clients without this widget; it is
not currently collapsed on card-capable clients. Desktop requires a build that
bundles the matching reference widget. Existing installed clients do not gain
that renderer through a server deployment.

Rich-message fallbacks translate common Slack emoji aliases and bold markup for
Talk, leaving code contents unchanged. Valid inline JSON and preformatted data
use fenced code blocks. Card JSON uses syntax colours with exact original copy
contents. These presentation adjustments retain existing delivery fingerprints;
retrying the same event after an upgrade does not create a new message. Existing
posted fallback text is not rewritten. Text-only webhook messages are unchanged.

Protocol references: [Slack section blocks](https://docs.slack.dev/reference/block-kit/blocks/section-block/)
and [context blocks](https://docs.slack.dev/reference/block-kit/blocks/context-block/).
The compatibility subset and bounds above are authoritative for this endpoint.
