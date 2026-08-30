// A tiny headless client for the NimbusCMS demo. Served same-origin from the
// demo, so it reads /api/v1 with a read-only token (public here on purpose).
(() => {
  'use strict';
  const API = '/api/v1';
  const TOKEN = 'nbt_aa20ef2b7116e23d513a0011226894c2b21456ed'; // demo public read-only (*:read)
  const auth = { Authorization: 'Bearer ' + TOKEN };
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  async function api(path, useAuth) {
    const res = await fetch(API + path, useAuth === false ? {} : { headers: auth });
    let json = null;
    try { json = await res.json(); } catch (_) { /* non-JSON */ }
    return { ok: res.ok, status: res.status, json };
  }

  async function loadList() {
    $('list').textContent = 'Loading…';
    const { ok, status, json } = await api('/collections/menu/entries');
    if (!ok) { $('list').innerHTML = '<p class="err">Error ' + status + '</p>'; return; }
    const items = (json && json.data) || [];
    if (items.length === 0) { $('list').innerHTML = '<p class="muted">No entries.</p>'; return; }
    $('list').innerHTML = '<ul>' + items.map((e) =>
      '<li><button class="link" data-slug="' + esc(e.slug) + '">' + esc(e.title) + '</button> <code>' + esc(e.slug) + '</code></li>'
    ).join('') + '</ul>';
    $('list').querySelectorAll('button[data-slug]').forEach((b) =>
      b.addEventListener('click', () => loadOne(b.getAttribute('data-slug'))));
  }

  async function loadOne(slug) {
    $('req').textContent = 'GET ' + API + '/collections/menu/entries/' + slug;
    $('detail').textContent = 'Loading…';
    const { ok, status, json } = await api('/collections/menu/entries/' + encodeURIComponent(slug));
    $('detail').innerHTML = ok
      ? '<pre>' + esc(JSON.stringify(json.data, null, 2)) + '</pre>'
      : '<p class="err">Error ' + status + '</p>';
  }

  async function loadPreview() {
    const t = $('ptoken').value.trim();
    if (t === '') { $('preview').innerHTML = '<p class="muted">Paste a preview token first.</p>'; return; }
    $('preview').textContent = 'Loading…';
    // The preview endpoint authorises via the entry-scoped token itself — no API token.
    const { ok, status, json } = await api('/preview?token=' + encodeURIComponent(t), false);
    $('preview').innerHTML = ok
      ? '<pre>' + esc(JSON.stringify(json.data, null, 2)) + '</pre>'
      : '<p class="err">Error ' + status + ' — the preview token may be wrong or expired.</p>';
  }

  document.addEventListener('DOMContentLoaded', () => {
    loadList();
    $('reload').addEventListener('click', loadList);
    $('pgo').addEventListener('click', loadPreview);
  });
})();
