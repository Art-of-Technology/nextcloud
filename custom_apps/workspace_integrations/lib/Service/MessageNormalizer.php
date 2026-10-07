<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\WorkspaceIntegrations\Service;

/** Strict, loss-aware transport compatibility; no Slack actions or remote fetches. */
final class MessageNormalizer {
	private int $nodes = 0;
	public function normalize(array $payload): array {
		$this->nodes = 0;
		try {
			$encoded = json_encode($payload, JSON_THROW_ON_ERROR, 32);
		} catch (\JsonException) {
			$this->fail('Invalid JSON text or nesting depth.');
		}
		if (strlen($encoded) > 65536) {
			$this->fail('Payload exceeds 65536 bytes.');
		}
		$this->keys($payload, ['text','message','eventId','blocks','attachments','title','status','fields','context']);
		if (isset($payload['text'], $payload['message'])) {
			$this->fail('Choose text or message.');
		}
		$text = $payload['text'] ?? $payload['message'] ?? null;
		if ($text !== null) {
			$text = $this->string($text, 16000);
		}
		$custom = count(array_intersect(array_keys($payload), ['title','status','fields','context'])) > 0;
		if ($custom && (isset($payload['blocks']) || isset($payload['attachments']))) {
			$this->fail('Do not mix custom and Slack layouts.');
		}
		$blocks = [];
		if (isset($payload['attachments']) && !isset($payload['blocks']) && $text !== null) {
			$blocks[] = ['type' => 'section','text' => $this->textObject(['type' => 'mrkdwn','text' => $text], 16000)];
		}
		if (isset($payload['blocks'])) {
			foreach ($this->list($payload['blocks'], 50) as $block) {
				$blocks[] = $this->block($block);
			}
		}
		if (isset($payload['attachments'])) {
			foreach ($this->list($payload['attachments'], 10) as $attachment) {
				$blocks[] = $this->attachment($attachment);
			}
		}
		if ($custom) {
			if (isset($payload['title'])) {
				$blocks[] = ['type' => 'header','text' => ['type' => 'plain_text','text' => $this->string($payload['title'], 150)]];
			}
			if (isset($payload['status'])) {
				$blocks[] = ['type' => 'section','text' => ['type' => 'plain_text','text' => 'Status: ' . $this->string($payload['status'], 100)]];
			}
			if (isset($payload['fields'])) {
				$fields = [];
				foreach ($this->list($payload['fields'], 30) as $field) {
					$this->keys($field, ['label','value']);
					$fields[] = ['type' => 'plain_text','text' => $this->string($field['label'] ?? null, 200) . ":\n" . $this->string($field['value'] ?? null, 1800)];
				}
				foreach (array_chunk($fields, 10) as $chunk) {
					$blocks[] = ['type' => 'section','fields' => $chunk];
				}
			}
			if (array_key_exists('context', $payload)) {
				if (!is_array($payload['context'])) {
					$this->fail('Context must be an object or array.');
				}
				$json = $this->string(json_encode($payload['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 12000);
				$blocks[] = ['type' => 'rich_text','elements' => [['type' => 'rich_text_preformatted','elements' => [['type' => 'text','text' => $json]]]]];
			}
		}
		if (!$blocks) {
			if ($custom || isset($payload['blocks']) || isset($payload['attachments']) || $text === null) {
				$this->fail('Message needs nonempty content.');
			}
			return ['text' => $text,'card' => null,'fingerprint' => hash('sha256', $text)];
		}
		$summary = $text;
		$fallback = implode("\n\n", array_map(fn ($b) => $this->fallback($b), $blocks));
		// Keep every field available to clients without the card renderer. A sender's
		// summary must not erase the structured body from native-client fallback.
		$text = $this->string($text !== null && $text !== $fallback && !str_starts_with($fallback, $text)
		 ? $text . "\n\n" . $fallback : $fallback, 16000);
		$card = ['schemaVersion' => 1,'blocks' => $blocks];
		// Presentation must not change the persisted idempotency identity of retries.
		$fingerprint = hash('sha256', json_encode($this->sort(['text' => $text,'card' => $card]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		$presentation = implode("\n\n", array_map(fn ($b) => $this->fallback($b, true), $blocks));
		if ($summary !== null && $summary !== $fallback && !str_starts_with($fallback, $summary)) {
			$presentation = ($custom ? $summary : $this->markdownFallback($summary)) . "\n\n" . $presentation;
		}
		return ['text' => $this->string($presentation, 16000),'card' => $card,'fingerprint' => $fingerprint];
	}
	private function fail(string $message): never {
		throw new ServiceException($message);
	}
	private function keys(mixed $value, array $allowed): void {
		if (!is_array($value) || array_diff(array_keys($value), $allowed)) {
			$this->fail('Unsupported message property.');
		}
		foreach ($value as $item) {
			if ($item === null) {
				$this->fail('Message properties cannot be null.');
			}
		}
		if (++$this->nodes > 1000) {
			$this->fail('Message has too many nodes.');
		}
	}
	private function list(mixed $value, int $max): array {
		if (!is_array($value) || !array_is_list($value) || !$value || count($value) > $max) {
			$this->fail('Invalid message list size.');
		}
		return $value;
	}
	private function string(mixed $value, int $max, bool $allowBlank = false): string {
		if (!is_string($value) || (!$allowBlank && trim($value) === '') || strlen($value) > $max || !preg_match('//u', $value) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
			$this->fail('Invalid or oversized UTF-8 text.');
		}
		return $value;
	}
	private function url(mixed $value): string {
		$value = $this->string($value, 2048);
		$parts = parse_url($value);
		if (!$parts || !isset($parts['host'],$parts['scheme']) || !in_array(strtolower($parts['scheme']), ['https','http'], true) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\s\\\\]/', $value) || preg_match('/(^|\.)(files|files-pri)\.slack\.com$/i', $parts['host'])) {
			$this->fail('Unsupported or private resource URL.');
		}
		return $value;
	}
	private function textObject(mixed $value, int $max = 3000): array {
		$this->keys($value, ['type','text','emoji','verbatim']);
		if (!in_array($value['type'] ?? null, ['plain_text','mrkdwn'], true)) {
			$this->fail('Unsupported text type.');
		}
		$result = ['type' => $value['type'],'text' => $this->string($value['text'] ?? null, $max)];
		foreach (['emoji','verbatim'] as $key) {
			if (isset($value[$key])) {
				if (!is_bool($value[$key]) || ($key === 'emoji') !== ($value['type'] === 'plain_text')) {
					$this->fail('Invalid text option.');
				} $result[$key] = $value[$key];
			}
		}
		if ($value['type'] === 'mrkdwn') {
			preg_match_all('/<([^>]+)>/', $result['text'], $links);
			foreach ($links[1] as $link) {
				$this->url(explode('|', $link, 2)[0]);
			}
		}
		return $result;
	}
	private function block(mixed $b): array {
		if (!is_array($b)) {
			$this->fail('Invalid block.');
		} $type = $b['type'] ?? '';
		if (!is_string($type)) {
			$this->fail('Invalid block type.');
		}
		$allowed = ['header' => ['text'],'section' => ['text','fields'],'context' => ['elements'],'divider' => [],'image' => ['image_url','alt_text','title'],'rich_text' => ['elements'],'table' => ['rows','column_settings']];
		if (!isset($allowed[$type])) {
			$this->fail('Unsupported block type: ' . (is_string($type)?$type:'invalid'));
		}
		$this->keys($b, array_merge(['type','block_id'], $allowed[$type]));
		$out = ['type' => $type];
		if (isset($b['block_id'])) {
			$out['block_id'] = $this->string($b['block_id'], 255);
		}
		if ($type === 'header') {
			$out['text'] = $this->textObject($b['text'] ?? null, 150);
			if ($out['text']['type'] !== 'plain_text') {
				$this->fail('Header must be plain text.');
			}
		}
		if ($type === 'section') {
			if (isset($b['text'])) {
				$out['text'] = $this->textObject($b['text']);
			}
			if (isset($b['fields'])) {
				$out['fields'] = array_map(fn ($v) => $this->textObject($v, 2000), $this->list($b['fields'], 10));
			}
			if (!isset($out['text']) && !isset($out['fields'])) {
				$this->fail('Empty section.');
			}
		}
		if ($type === 'context') {
			$out['elements'] = array_map(fn ($v) => $this->textObject($v, 2000), $this->list($b['elements'] ?? null, 10));
		}
		if ($type === 'image') {
			$out['image_url'] = $this->url($b['image_url'] ?? null);
			$out['alt_text'] = $this->string($b['alt_text'] ?? null, 2000);
			if (isset($b['title'])) {
				$out['title'] = $this->textObject($b['title'], 2000);
			}
		}
		if ($type === 'rich_text') {
			$out['elements'] = array_map(fn ($v) => $this->rich($v), $this->list($b['elements'] ?? null, 100));
		}
		if ($type === 'table') {
			$out['rows'] = [];
			$width = null;
			foreach ($this->list($b['rows'] ?? null, 100) as $row) {
				$cells = [];
				foreach ($this->list($row, 20) as $cell) {
					if (!is_array($cell)) {
						$this->fail('Invalid table cell.');
					}
					if (($cell['type'] ?? null) === 'raw_text') {
						$this->keys($cell, ['type','text']);
						$cells[] = ['type' => 'raw_text','text' => $this->string($cell['text'] ?? null, 2000, true)];
					} elseif (($cell['type'] ?? null) === 'rich_text') {
						$cells[] = $this->block($cell);
					} else {
						$this->fail('Unsupported table cell.');
					}
				} if ($width !== null && $width !== count($cells)) {
					$this->fail('Table rows must have equal widths.');
				}$width = count($cells);
				$out['rows'][] = $cells;
			}
			if (isset($b['column_settings'])) {
				$out['column_settings'] = [];
				foreach ($this->list($b['column_settings'], 20) as $setting) {
					$this->keys($setting, ['align','is_wrapped']);
					if (isset($setting['align']) && !in_array($setting['align'], ['left','center','right'], true)) {
						$this->fail('Invalid alignment.');
					}if (isset($setting['is_wrapped']) && !is_bool($setting['is_wrapped'])) {
						$this->fail('Invalid wrapping.');
					}$out['column_settings'][] = $setting;
				}if (count($out['column_settings']) > $width) {
					$this->fail('Too many column settings.');
				}
			}
		}
		return $out;
	}
	private function rich(mixed $r, int $depth = 0): array {
		if (!is_array($r)) {
			$this->fail('Invalid rich text element.');
		}
		if ($depth > 4) {
			$this->fail('Rich text nesting too deep.');
		}
		$type = $r['type'] ?? '';
		$isList = $type === 'rich_text_list';
		if (!in_array($type, ['rich_text_section','rich_text_quote','rich_text_preformatted','rich_text_list'], true)) {
			$this->fail('Unsupported rich text element.');
		}
		$this->keys($r, $isList?['type','elements','style','indent','offset']:['type','elements']);
		$out = ['type' => $type];
		$out['elements'] = array_map(fn ($v) => $isList?$this->rich($v, $depth + 1):$this->inline($v), $this->list($r['elements'] ?? null, 100));
		if ($isList) {
			if (!in_array($r['style'] ?? null, ['bullet','ordered'], true)) {
				$this->fail('Invalid list style.');
			}$out['style'] = $r['style'];
			foreach (['indent','offset'] as $key) {
				if (isset($r[$key])) {
					if (!is_int($r[$key]) || $r[$key] < 0 || $r[$key] > 100) {
						$this->fail('Invalid list position.');
					}$out[$key] = $r[$key];
				}
			}
		}
		return $out;
	}
	private function inline(mixed $v): array {
		if (!is_array($v)) {
			$this->fail('Invalid inline element.');
		}
		$type = $v['type'] ?? '';
		$allowed = ['text' => ['text','style'],'link' => ['url','text','style'],'emoji' => ['name']];
		if (!is_string($type)) {
			$this->fail('Invalid inline type.');
		}
		if (!isset($allowed[$type])) {
			$this->fail('Unsupported inline rich text.');
		}$this->keys($v, array_merge(['type'], $allowed[$type]));
		$out = ['type' => $type];
		if ($type === 'text') {
			$out['text'] = $this->string($v['text'] ?? null, 12000, true);
		}
		if ($type === 'link') {
			$out['url'] = $this->url($v['url'] ?? null);
			if (isset($v['text'])) {
				$out['text'] = $this->string($v['text'], 2000);
			}
		}
		if ($type === 'emoji') {
			if (!is_string($v['name'] ?? null) || !preg_match('/^[a-zA-Z0-9_+-]{1,64}$/D', $v['name'])) {
				$this->fail('Invalid emoji.');
			}$out['name'] = $v['name'];
		}
		if (isset($v['style'])) {
			$this->keys($v['style'], ['bold','italic','strike','code']);
			foreach ($v['style'] as $flag) {
				if (!is_bool($flag)) {
					$this->fail('Invalid inline style.');
				}
			}$out['style'] = $v['style'];
		}
		return $out;
	}
	private function attachment(mixed $a): array {
		$this->keys($a, ['fallback','color','pretext','author_name','author_link','author_icon','title','title_link','text','fields','image_url','thumb_url','footer','footer_icon','ts','mrkdwn_in','blocks']);
		$out = ['type' => 'attachment','blocks' => []];
		$blocks = &$out['blocks'];
		if (isset($a['color'])) {
			if (!is_string($a['color']) || !preg_match('/^(good|warning|danger|#?[0-9a-fA-F]{6})$/D', $a['color'])) {
				$this->fail('Invalid attachment color.');
			}$out['color'] = $a['color'];
		}
		$markdown = $a['mrkdwn_in'] ?? [];
		if (!is_array($markdown) || !array_is_list($markdown) || array_diff($markdown, ['pretext','text','fields'])) {
			$this->fail('Unsupported attachment formatting.');
		}
		if (isset($a['fallback'])) {
			$out['fallback'] = $this->string($a['fallback'], 3000);
		}
		foreach (['pretext','author_name','title','text'] as $key) {
			if (!isset($a[$key])) {
				continue;
			}$text = $this->string($a[$key], 3000);
			$linkKey = ['author_name' => 'author_link','title' => 'title_link'][$key] ?? null;
			if ($linkKey && isset($a[$linkKey])) {
				$blocks[] = ['type' => 'rich_text','elements' => [['type' => 'rich_text_section','elements' => [['type' => 'link','url' => $this->url($a[$linkKey]),'text' => $text]]]]];
			} else {
				$blocks[] = ['type' => $key === 'footer'?'context':'section',($key === 'footer'?'elements':'text') => $key === 'footer'?[['type' => 'plain_text','text' => $text]]:$this->textObject(['type' => in_array($key, $markdown, true)?'mrkdwn':'plain_text','text' => $text])];
			}
		}
		foreach (['author_link' => 'author_name','title_link' => 'title'] as $link => $label) {
			if (isset($a[$link]) && !isset($a[$label])) {
				$this->fail('Attachment link requires its label.');
			}
		}
		if (isset($a['fields'])) {
			foreach (array_chunk($this->list($a['fields'], 30), 10) as $chunk) {
				$fields = [];
				foreach ($chunk as $f) {
					$this->keys($f, ['title','value','short']);
					if (isset($f['short']) && !is_bool($f['short'])) {
						$this->fail('Invalid attachment field width.');
					}$field = $this->textObject(['type' => in_array('fields', $markdown, true)?'mrkdwn':'plain_text','text' => $this->string($f['title'] ?? null, 200) . ":\n" . $this->string($f['value'] ?? null, 1700)], 2000);
					if (($f['short'] ?? false) === false) {
						if ($fields) {
							$blocks[] = ['type' => 'section','fields' => $fields];
							$fields = [];
						}$blocks[] = ['type' => 'section','text' => $field];
					} else {
						$fields[] = $field;
					}
				}if ($fields) {
					$blocks[] = ['type' => 'section','fields' => $fields];
				}
			}
		}
		foreach (['author_icon','image_url','thumb_url','footer_icon'] as $key) {
			if (isset($a[$key])) {
				$blocks[] = ['type' => 'image','image_url' => $this->url($a[$key]),'alt_text' => str_replace('_', ' ', $key)];
			}
		}
		if (isset($a['blocks'])) {
			foreach ($this->list($a['blocks'], 50) as $block) {
				$blocks[] = $this->block($block);
			}
		}
		if (isset($a['footer'])) {
			$blocks[] = ['type' => 'context','elements' => [['type' => 'plain_text','text' => $this->string($a['footer'], 3000)]]];
		}
		if (isset($a['ts'])) {
			if ((!is_int($a['ts']) && !is_string($a['ts'])) || !preg_match('/^\d{1,12}$/D', (string)$a['ts'])) {
				$this->fail('Invalid attachment timestamp.');
			}$blocks[] = ['type' => 'context','elements' => [['type' => 'plain_text','text' => 'Timestamp: ' . $a['ts']]]];
		}
		if (!$blocks) {
			if (isset($out['fallback'])) {
				$blocks[] = ['type' => 'section','text' => ['type' => 'plain_text','text' => $out['fallback']]];
			} else {
				$this->fail('Empty attachment.');
			}
		}
		return $out;
	}
	private function fallback(array $node, bool $presentation = false): string {
		if (($node['type'] ?? '') === 'link') {
			return ($node['text'] ?? $node['url']) . ' (' . $node['url'] . ')';
		}
		if (isset($node['text']) && is_string($node['text'])) {
			if ($presentation && ($node['type'] ?? '') === 'mrkdwn') {
				return $this->markdownFallback($node['text']);
			}
			if ($presentation && ($node['type'] ?? '') === 'plain_text' && ($node['emoji'] ?? true)) {
				return $this->emojiFallback($node['text']);
			}
			return $node['text'];
		}
		if (($node['type'] ?? '') === 'emoji') {
			return $presentation ? $this->emojiFallback(':' . $node['name'] . ':') : ':' . $node['name'] . ':';
		}
		if (($node['type'] ?? '') === 'image') {
			return $node['alt_text'] . ' ' . $node['image_url'];
		}
		if (in_array($node['type'] ?? '', ['rich_text_section','rich_text_quote','rich_text_preformatted'], true)) {
			$preformatted = $node['type'] === 'rich_text_preformatted';
			$content = implode('', array_map(fn ($inline) => $this->fallback($inline, $presentation && !$preformatted), $node['elements']));
			return $presentation && $preformatted ? $this->codeFallback($content) : $content;
		}
		$parts = [];
		foreach (['text','fields','elements','blocks','rows'] as $key) {
			if (!isset($node[$key])) {
				continue;
			}$value = $node[$key];
			if (isset($value['type'])) {
				$parts[] = $this->fallback($value, $presentation);
			} else {
				foreach ($value as $child) {
					if (isset($child['type'])) {
						$parts[] = $this->fallback($child, $presentation);
					} else {
						foreach ($child as $cell) {
							$parts[] = $this->fallback($cell, $presentation);
						}
					}
				}
			}
		}return implode("\n", $parts);
	}
	private function emojiFallback(string $text): string {
		// Emoji-looking URL path/query values are data, including in plain text.
		$parts = preg_split('~(<https?://[^>\r\n]+>|https?://[^\s<>`]+)~i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
		foreach ($parts as $index => &$part) {
			if ($index % 2 === 0) {
				$part = strtr($part, [':moneybag:' => '💰', ':white_check_mark:' => '✅', ':warning:' => '⚠️', ':x:' => '❌', ':information_source:' => 'ℹ️', ':rocket:' => '🚀', ':tada:' => '🎉', ':bell:' => '🔔', ':eyes:' => '👀', ':thumbsup:' => '👍', ':+1:' => '👍', ':thumbsdown:' => '👎', ':-1:' => '👎']);
			}
		}
		return implode('', $parts);
	}
	private function codeFallback(string $text): string {
		// Choose a fence longer than any payload run so data cannot close it.
		preg_match_all('/`+/', $text, $runs);
		$length = 3;
		foreach ($runs[0] as $run) {
			$length = max($length, strlen($run) + 1);
		}
		$fence = str_repeat('`', $length);
		json_decode($text);
		$language = json_last_error() === JSON_ERROR_NONE && preg_match('/^\s*[\[{]/', $text) ? 'json' : '';
		return $fence . $language . "\n" . $text . "\n" . $fence;
	}
	private function markdownFallback(string $text): string {
		// Preserve code and URLs; Slack link labels stay literal with their target.
		$parts = preg_split('~(`{3,}[\s\S]*?`{3,}|`[^`\n]*`|<https?://[^>\r\n]+>|https?://[^\s<>`]+)~i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
		foreach ($parts as $index => &$part) {
			if ($index % 2 === 1) {
				if (str_starts_with($part, '`') && !str_starts_with($part, '``')) {
					$data = substr($part, 1, -1);
					json_decode($data);
					if (json_last_error() === JSON_ERROR_NONE && preg_match('/^\s*[\[{]/', $data)) {
						$part = "\n" . $this->codeFallback($data) . "\n";
					}
				}
				continue;
			}
			$part = $this->emojiFallback($part);
			$part = preg_replace('/(?<![\\\\*\w])\*(?![\s*])([^*\n]*?\S)\*(?![\w*])/', '**$1**', $part);
		}
		return implode('', $parts);
	}
	private function sort(array $value): array {
		if (!array_is_list($value)) {
			ksort($value);
		}foreach ($value as &$v) {
			if (is_array($v)) {
				$v = $this->sort($v);
			}
		}return $value;
	}
}
