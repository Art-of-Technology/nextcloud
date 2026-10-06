/* SPDX-License-Identifier: AGPL-3.0-or-later */
(function (root, factory) {
	'use strict';
	if (typeof module === 'object' && module.exports) module.exports = factory();
	else root.WorkspaceNotificationCard = factory();
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
	'use strict';
	// Dependency-free common Slack aliases. Unknown/custom aliases stay readable
	// verbatim; this intentionally does not download a workspace emoji catalogue.
	var EMOJI = { moneybag: '💰', white_check_mark: '✅', heavy_check_mark: '✔️', x: '❌', warning: '⚠️', information_source: 'ℹ️', robot_face: '🤖', robot: '🤖', rotating_light: '🚨', hourglass_flowing_sand: '⏳', hourglass: '⌛', rocket: '🚀', tada: '🎉', fire: '🔥', bug: '🐛', bell: '🔔', eyes: '👀', lock: '🔒', unlock: '🔓', chart_with_upwards_trend: '📈', chart_with_downwards_trend: '📉', '+1': '👍', thumbsup: '👍', '-1': '👎', thumbsdown: '👎', red_circle: '🔴', large_green_circle: '🟢', large_yellow_circle: '🟡', blue_circle: '🔵', speech_balloon: '💬', memo: '📝', link: '🔗', package: '📦', construction: '🚧', no_entry: '⛔', stop_sign: '🛑', question: '❓', exclamation: '❗', heart: '❤️', smile: '😄', thinking_face: '🤔' };
	function emojiText(text) { return text.replace(/:([a-zA-Z0-9_+-]{1,64}):/g, function (alias, name) { return Object.prototype.hasOwnProperty.call(EMOJI, name) ? EMOJI[name] : alias; }); }
	function safeUrl(value) {
		if (typeof value !== 'string' || /[\u0000-\u0020\u007f]/.test(value)) return null;
		try {
			var url = new URL(value);
			return /^(https?:)$/.test(url.protocol) && !url.username && !url.password ? url.href : null;
		} catch (_) { return null; }
	}
	function render(card, options) {
		options = options || {};
		var doc = options.document || document;
		function el(tag, cls, text) {
			var node = doc.createElement(tag);
			if (cls) node.className = cls;
			if (text != null) node.textContent = String(text);
			return node;
		}
		function link(url, label) {
			var safe = safeUrl(url);
			if (!safe) return doc.createTextNode(label || url || '');
			var a = el('a', '', label || url);
			a.href = safe;
			a.target = '_blank';
			a.rel = 'noopener noreferrer';
			a.referrerPolicy = 'no-referrer';
			return a;
		}
		function code(text, copiedText) {
			if (copiedText === undefined) copiedText = text;
			var box = el('div', 'wnc-code');
			var pre = el('pre');
			pre.appendChild(el('code', '', text));
			box.appendChild(pre);
			var copy = el('button', 'wnc-copy', 'Copy code');
			copy.type = 'button';
			copy.addEventListener('click', function () {
				var clipboard = options.clipboard || (doc.defaultView && doc.defaultView.navigator.clipboard);
				if (!clipboard || !clipboard.writeText) { copy.textContent = 'Select code to copy'; return; }
				Promise.resolve().then(function () { return clipboard.writeText(copiedText); }).then(function () {
					copy.textContent = 'Copied';
				}, function () { copy.textContent = 'Select code to copy'; });
			});
			box.appendChild(copy);
			return box;
		}
		function decode(text) { return text.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&'); }
		function inlineCode(text, expandJson) {
			if (expandJson) {
				try {
					var pretty = JSON.stringify(JSON.parse(text), null, 2);
					var details = el('details', 'wnc-json');
					details.appendChild(el('summary', '', 'JSON details'));
					details.appendChild(code(pretty, text));
					return details;
				} catch (_) { /* Non-JSON inline code keeps its normal presentation. */ }
			}
			return el('code', 'wnc-inline-code', text);
		}
		function formatted(text, parent, expandJson, depth) {
			depth = depth || 0;
			// Mixed emphasis nesting is supported to eight levels. Excess nesting,
			// unmatched delimiters and custom Slack entities remain literal text.
			if (depth >= 8) { parent.appendChild(doc.createTextNode(emojiText(decode(text)))); return; }
			// Slack's formatting is parsed into DOM nodes; raw HTML is always text.
			var pattern = /```([\s\S]*?)```|`([^`\n]+)`|<(https?:\/\/[^>|\s]+)(?:\|([^>]*))?>|\*([^*\n]+)\*|_([^_\n]+)_|~([^~\n]+)~|:([a-zA-Z0-9_+-]{1,64}):/g;
			var offset = 0, match;
			while ((match = pattern.exec(text))) {
				parent.appendChild(doc.createTextNode(emojiText(decode(text.slice(offset, match.index)))));
				if (match[1] !== undefined) parent.appendChild(code(decode(match[1])));
				else if (match[2] !== undefined) parent.appendChild(inlineCode(decode(match[2]), expandJson));
				else if (match[8]) parent.appendChild(doc.createTextNode(emojiText(match[0])));
				else if (match[3]) parent.appendChild(link(decode(match[3]), decode(match[4] || match[3])));
				else {
					var emphasis = el(match[5] ? 'strong' : match[6] ? 'em' : 's');
					formatted(match[5] || match[6] || match[7], emphasis, expandJson, depth + 1);
					parent.appendChild(emphasis);
				}
				offset = pattern.lastIndex;
			}
			parent.appendChild(doc.createTextNode(emojiText(decode(text.slice(offset)))));
		}
		function textObject(value, parent, expandJson) {
			if (!value || typeof value.text !== 'string') throw new Error('Invalid card text');
			if (value.type === 'mrkdwn') formatted(value.text, parent, expandJson);
			else if (value.type === 'plain_text' || value.type === 'raw_text') parent.appendChild(doc.createTextNode(value.type === 'plain_text' && value.emoji !== false ? emojiText(value.text) : value.text));
			else throw new Error('Unsupported card text type');
		}
		function inline(item) {
			var node;
			if (item.type === 'text') node = doc.createTextNode(item.text || '');
			else if (item.type === 'link') node = link(item.url, item.text);
			else if (item.type === 'emoji') node = doc.createTextNode(emojiText(':' + item.name + ':'));
			else throw new Error('Unsupported rich text element');
			[['code', 'code'], ['bold', 'strong'], ['italic', 'em'], ['strike', 's']].forEach(function (pair) {
				if (item.style && item.style[pair[0]] === true) {
					var wrapper = el(pair[1]); wrapper.appendChild(node); node = wrapper;
				}
			});
			return node;
		}
		function rich(item, depth) {
			if (depth > 8) throw new Error('Card nesting limit exceeded');
			if (item.type === 'rich_text_preformatted') return code((item.elements || []).map(function (x) {
				if (x.type === 'text') return x.text;
				if (x.type === 'link') return x.text ? x.text + ' (' + x.url + ')' : x.url;
				if (x.type === 'emoji') return ':' + x.name + ':';
				throw new Error('Unsupported code element');
			}).join(''));
			var tag = item.type === 'rich_text_list' ? (item.style === 'ordered' ? 'ol' : 'ul')
				: item.type === 'rich_text_quote' ? 'blockquote' : 'div';
			if (['rich_text', 'rich_text_section', 'rich_text_list', 'rich_text_quote'].indexOf(item.type) < 0) throw new Error('Unsupported rich text block');
			var node = el(tag, 'wnc-rich');
			if (tag === 'ol' && Number.isInteger(item.offset) && item.offset >= 0) node.start = item.offset + 1;
			if ((tag === 'ol' || tag === 'ul') && Number.isInteger(item.indent) && item.indent >= 0) node.style.marginInlineStart = Math.min(item.indent, 8) * 12 + 'px';
			(item.elements || []).forEach(function (child) {
				var nested = child.type.indexOf('rich_text') === 0 ? rich(child, depth + 1) : inline(child);
				if (tag === 'ul' || tag === 'ol') { var li = el('li'); li.appendChild(nested); node.appendChild(li); }
				else node.appendChild(nested);
			});
			return node;
		}
		function block(item, depth) {
			if (depth > 8) throw new Error('Card nesting limit exceeded');
			var node = el('div', 'wnc-block wnc-' + String(item.type).replace(/[^a-z_]/g, ''));
			if (item.type === 'divider') return el('hr', 'wnc-divider');
			if (item.type === 'header') { node = el('h3', 'wnc-header'); textObject(item.text, node); }
			else if (item.type === 'section') {
				if (item.text) { var body = el('div', 'wnc-text'); textObject(item.text, body); node.appendChild(body); }
				if (item.fields) {
					var fields = el('div', 'wnc-fields');
					item.fields.forEach(function (field) { var cell = el('div', 'wnc-field'); textObject(field, cell); fields.appendChild(cell); });
					node.appendChild(fields);
				}
			} else if (item.type === 'context') {
				(item.elements || []).forEach(function (value) { var span = el('div'); textObject(value, span, true); node.appendChild(span); });
			} else if (item.type === 'image') {
				var url = safeUrl(item.image_url);
				if (!url) throw new Error('Unsafe image URL');
				if (item.title) { var title = el('div'); textObject(item.title, title); node.appendChild(title); }
				var load = el('button', 'wnc-load-image', 'Load image: ' + item.alt_text);
				load.type = 'button';
				load.addEventListener('click', function () {
					var img = el('img', 'wnc-image'); img.alt = item.alt_text; img.referrerPolicy = 'no-referrer';
					img.addEventListener('error', function () { img.replaceWith(el('span', 'wnc-error', 'Image could not be loaded.')); });
					img.src = url; load.replaceWith(img);
				}, { once: true });
				node.appendChild(load);
			} else if (item.type === 'rich_text') node.appendChild(rich(item, depth + 1));
			else if (item.type === 'table') {
				var table = el('table'), tbody = el('tbody');
				(item.rows || []).forEach(function (row) {
					var tr = el('tr');
					row.forEach(function (cell, index) {
						var td = el('td');
						var setting = item.column_settings && item.column_settings[index];
						if (setting && ['left', 'center', 'right'].indexOf(setting.align) >= 0) td.style.textAlign = setting.align;
						if (setting && setting.is_wrapped === false) td.style.whiteSpace = 'nowrap';
						if (cell.type === 'rich_text') td.appendChild(rich(cell, depth + 1)); else textObject(cell, td);
						tr.appendChild(td);
					}); tbody.appendChild(tr);
				}); table.appendChild(tbody); node.appendChild(table);
			} else if (item.type === 'attachment') {
				var color = { good: '#258548', warning: '#a36b00', danger: '#c12b35' }[item.color] || item.color;
				if (color && /^#?[0-9a-f]{6}$/i.test(color)) node.style.borderInlineStartColor = color.charAt(0) === '#' ? color : '#' + color;
				(item.blocks || []).forEach(function (child) { node.appendChild(block(child, depth + 1)); });
			} else throw new Error('Unsupported notification card block');
			return node;
		}
		if (!card || card.schemaVersion !== 1 || !Array.isArray(card.blocks) || card.blocks.length > 100) throw new Error('Unsupported notification card');
		var root = el('article', 'workspace-notification-card');
		root.setAttribute('aria-label', 'Notification');
		card.blocks.forEach(function (item) { root.appendChild(block(item, 0)); });
		return root;
	}
	return { render: render, safeUrl: safeUrl };
});
