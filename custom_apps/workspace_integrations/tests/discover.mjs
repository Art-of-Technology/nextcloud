/* SPDX-License-Identifier: AGPL-3.0-or-later */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

let navigationReady = false;
let callback;
let disconnects = 0;
const links = [];
const navigation = { append: (link) => links.push(link) };
vm.runInNewContext(fs.readFileSync(new URL('../js/discover.js', import.meta.url), 'utf8'), {
 document: {
  body: { dataset: {} },
  querySelector: (selector) => selector.includes('.app-navigation-entry__utils') ? null
   : selector === '#app-navigation-vue' ? (navigationReady ? navigation : null) : {},
  getElementById: () => links[0],
  createElement: () => ({ style: {} }),
 },
 location: { pathname: '/apps/spreed/' },
 OC: { generateUrl: (url) => url },
 MutationObserver: class { constructor(fn) { callback = fn; } observe() {} disconnect() { disconnects++; } },
 window: { addEventListener() {} },
});
assert.equal(links.length, 0);
assert.equal(disconnects, 0);
navigationReady = true;
callback();
assert.equal(links.length, 1);
assert.equal(disconnects, 1, 'Stop observing chat mutations after navigation entry insertion');
callback();
assert.equal(links.length, 1);
console.log('Talk navigation discovery lifecycle passed');
