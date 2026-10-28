(() => {
'use strict';
const csrf = document.querySelector('meta[name=csrf]')?.content || '';
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
function el(tag, attrs = {}, text = '') {
const n = document.createElement(tag);
for (const [k, v] of Object.entries(attrs)) n.setAttribute(k, v);
if (text !== '') n.textContent = text;
return n;
}
async function api(url, method = 'GET', data = null) {
const opt = { method, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch', 'X-CSRF-Token': csrf, Accept: 'application/json' } };
if (data) {
opt.body = new URLSearchParams(data);
opt.headers['Content-Type'] = 'application/x-www-form-urlencoded';
}
const r = await fetch(url, opt);
let j = {};
try { j = await r.json(); } catch { /* non-JSON error page */ }
if (r.status === 401) { location.href = '/login'; throw new Error('Session expired'); }
if (!r.ok) throw new Error(j.error || 'Request failed (' + r.status + ')');
return j;
}
function toast(msg, bad = false) {
const t = el('div', { class: 'toast' + (bad ? ' bad' : ''), role: 'status' }, msg);
document.body.appendChild(t);
setTimeout(() => t.remove(), 4000);
}
const mb = (n) => (n >= 1024 ? (n / 1024).toFixed(1) + ' GB' : Math.round(n) + ' MB');
const bytes = (n) => (n >= 1073741824 ? (n / 1073741824).toFixed(2) + ' GB' : n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.round(n / 1024) + ' KB');
$$('[data-w]').forEach((i) => { i.style.width = i.dataset.w + '%'; });
$$('form[data-confirm]').forEach((f) => f.addEventListener('submit', (e) => { if (!confirm(f.dataset.confirm)) e.preventDefault(); }));
function setBadge(b, state) {
const label = { running: 'Running', exited: 'Stopped', created: 'Stopped', offline: 'Offline', missing: 'Not installed', restarting: 'Restarting', paused: 'Paused' }[state] || state;
b.dataset.state = state === 'exited' || state === 'created' ? 'stopped' : state;
b.textContent = label;
}
function applyStats(root, d, memLimit) {
$$('[data-stat=cpu]', root).forEach((n) => { n.textContent = (d.cpu || 0).toFixed(1) + '%'; });
$$('[data-stat=mem]', root).forEach((n) => { n.textContent = mb(d.mem || 0); });
$$('[data-stat=disk]', root).forEach((n) => { n.textContent = mb(d.disk || 0); });
$$('[data-stat=net]', root).forEach((n) => { n.textContent = bytes(d.rx || 0) + ' / ' + bytes(d.tx || 0); });
const pct = (v, max) => Math.min(100, max ? (v / max) * 100 : 0);
$$('[data-bar=mem]', root).forEach((i) => { i.style.width = pct(d.mem || 0, memLimit || d.mem_limit) + '%'; });
$$('[data-bar=disk]', root).forEach((i) => { i.style.width = pct(d.disk || 0, d.disk_limit) + '%'; });
$$('.bar i:not([data-bar])', root).forEach((i) => { i.style.width = Math.min(100, d.cpu || 0) + '%'; });
}
$$('.server-card[data-state-url]').forEach((card) => {
const tick = async () => {
try {
const d = await api(card.dataset.stateUrl);
setBadge($('.badge', card), d.state);
applyStats(card, d);
} catch { setBadge($('.badge', card), 'offline'); }
};
tick();
setInterval(tick, 8000);
});
const srv = $('#srv');
if (srv) {
const base = srv.dataset.base;
const con = $('#console');
const badge = $('#state-badge');
const ansi = /\x1b\[[0-9;?]*[ -/]*[@-~]/g;
const series = { cpu: [], mem: [] };
let lastText = null;
function spark(name, max) {
const svg = $('[data-chart=' + name + ']');
if (!svg) return;
const pts = series[name];
const w = 120, h = 32;
const line = pts.map((v, i) => (i * w / 59).toFixed(1) + ',' + (h - Math.min(1, v / max) * (h - 2) - 1).toFixed(1)).join(' ');
svg.replaceChildren();
const p = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
p.setAttribute('points', line);
p.setAttribute('fill', 'none');
p.setAttribute('stroke', 'currentColor');
p.setAttribute('stroke-width', '1.5');
svg.appendChild(p);
}
async function pollState() {
try {
const d = await api(base + '/state');
setBadge(badge, d.state);
applyStats(srv, d, +srv.dataset.memory);
series.cpu.push(d.cpu || 0); series.mem.push(d.mem || 0);
for (const k of Object.keys(series)) if (series[k].length > 60) series[k].shift();
spark('cpu', Math.max(100, ...series.cpu));
spark('mem', +srv.dataset.memory);
const note = $('#install-note');
note.textContent = d.status === 'installing' ? 'Installing… this can take a few minutes.'
: d.status === 'install_failed' ? 'Installation failed: ' + (d.install_error || 'see node logs')
: d.status === 'suspended' ? 'This server is suspended.' : '';
$$('[data-power]').forEach((b) => { b.disabled = d.status !== 'active'; });
} catch { setBadge(badge, 'offline'); }
}
async function pollLogs() {
try {
const d = await api(base + '/logs');
const text = (d.logs || '').replace(ansi, '').replace(/\r/g, '');
if (text !== lastText) {
const stick = con.scrollHeight - con.scrollTop - con.clientHeight < 40;
con.textContent = text;
if (stick) con.scrollTop = con.scrollHeight;
lastText = text;
}
} catch { /* node offline */ }
}
$$('[data-power]').forEach((b) => b.addEventListener('click', async () => {
const a = b.dataset.power;
if (a === 'kill' && !confirm('Force-kill the server? Unsaved data may be lost.')) return;
b.disabled = true;
try { await api(base + '/power', 'POST', { action: a }); toast('Sent: ' + a); } catch (e) { toast(e.message, true); }
b.disabled = false;
pollState();
}));
const history = [];
let hi = 0;
const cmd = $('#cmd');
$('#cmd-form').addEventListener('submit', async (e) => {
e.preventDefault();
const v = cmd.value.trim();
if (!v) return;
history.push(v); hi = history.length;
cmd.value = '';
try { await api(base + '/command', 'POST', { command: v }); setTimeout(pollLogs, 500); } catch (er) { toast(er.message, true); }
});
cmd.addEventListener('keydown', (e) => {
if (e.key === 'ArrowUp' && hi > 0) { cmd.value = history[--hi]; e.preventDefault(); }
if (e.key === 'ArrowDown') { cmd.value = hi < history.length - 1 ? history[++hi] : ''; if (hi >= history.length) hi = history.length; }
});
pollState(); pollLogs();
setInterval(pollState, 4000);
setInterval(pollLogs, 2000);
}
const fm = $('#files');
if (fm) {
const base = fm.dataset.base;
const tbody = $('#file-table tbody');
const empty = $('#file-empty');
const editor = $('#editor');
const text = $('#editor-text');
let cwd = '';
let editing = null;
const join = (n) => (cwd ? cwd + '/' + n : n);
function crumbs() {
const c = $('#crumbs');
c.replaceChildren();
const parts = cwd ? cwd.split('/') : [];
const add = (label, path) => { const a = el('a', { href: '#' }, label); a.addEventListener('click', (e) => { e.preventDefault(); cwd = path; load(); }); c.appendChild(a); };
add('/home/container', '');
parts.forEach((p, i) => { c.appendChild(document.createTextNode(' / ')); add(p, parts.slice(0, i + 1).join('/')); });
}
function act(label, fn, cls = 'btn sm ghost') {
const b = el('button', { class: cls, type: 'button' }, label);
b.addEventListener('click', fn);
return b;
}
async function load() {
crumbs();
try {
const d = await api(base + '/files/list?path=' + encodeURIComponent(cwd));
tbody.replaceChildren();
empty.hidden = d.files.length > 0;
for (const f of d.files) {
const tr = el('tr');
const name = el('td');
const a = el('a', { href: '#' }, f.name);
a.addEventListener('click', (e) => { e.preventDefault(); if (f.dir) { cwd = join(f.name); load(); } else openFile(join(f.name)); });
const NS = 'http://www.w3.org/2000/svg';
const ic = document.createElementNS(NS, 'svg'); ic.setAttribute('class', 'ico');
const us = document.createElementNS(NS, 'use'); us.setAttribute('href', f.dir ? '#i-folder' : '#i-file'); ic.appendChild(us);
name.append(ic, ' ', a);
const actions = el('td', { class: 'r' });
actions.appendChild(act('Rename', async () => {
const to = prompt('New name', f.name);
if (!to || to === f.name || to.includes('/')) return;
try { await api(base + '/files/rename', 'POST', { path: join(f.name), to: join(to) }); load(); } catch (e) { toast(e.message, true); }
}));
actions.appendChild(act('Delete', async () => {
if (!confirm('Delete ' + f.name + '? This cannot be undone.')) return;
try { await api(base + '/files/delete', 'POST', { path: join(f.name) }); load(); } catch (e) { toast(e.message, true); }
}, 'btn sm danger'));
tr.append(name, el('td', { class: 'r' }, f.dir ? '—' : bytes(f.size)), el('td', {}, new Date(f.mtime * 1000).toLocaleString()), actions);
tbody.appendChild(tr);
}
} catch (e) { tbody.replaceChildren(); toast(e.message, true); }
}
async function openFile(path) {
try {
const d = await api(base + '/files/read?path=' + encodeURIComponent(path));
editing = path;
$('#editor-name').textContent = path;
text.value = d.content;
editor.hidden = false;
editor.scrollIntoView({ behavior: 'smooth' });
} catch (e) { toast(e.message, true); }
}
$('#editor-save').addEventListener('click', async () => {
try { await api(base + '/files/write', 'POST', { path: editing, content: text.value }); toast('Saved'); } catch (e) { toast(e.message, true); }
});
$('#editor-close').addEventListener('click', () => { editor.hidden = true; editing = null; text.value = ''; });
$('#refresh').addEventListener('click', load);
$('#new-dir').addEventListener('click', async () => {
const n = prompt('Folder name');
if (!n || n.includes('/')) return;
try { await api(base + '/files/mkdir', 'POST', { path: join(n) }); load(); } catch (e) { toast(e.message, true); }
});
$('#new-file').addEventListener('click', async () => {
const n = prompt('File name');
if (!n || n.includes('/')) return;
try { await api(base + '/files/write', 'POST', { path: join(n), content: '' }); load(); openFile(join(n)); } catch (e) { toast(e.message, true); }
});
$('#pull-url').addEventListener('click', async () => {
const url = prompt('HTTPS URL to download into this folder');
if (!url) return;
const guess = decodeURIComponent((url.split('?')[0].split('/').pop() || '')).replace(/[^A-Za-z0-9_.\-]/g, '_');
const n = prompt('Save as (file name)', guess);
if (!n || n.includes('/')) return;
toast('Downloading… large files can take a while');
try { const r = await api(base + '/files/pull', 'POST', { path: join(n), url }); toast('Downloaded ' + bytes(r.bytes || 0)); load(); } catch (e) { toast(e.message, true); }
});
load();
}
const cs = $('#create-server');
if (cs) {
const free = JSON.parse(cs.dataset.free || '[]');
const eggs = JSON.parse(cs.dataset.eggs || '{}');
const nodes = JSON.parse(cs.dataset.nodes || '[]');
const selNode = $('#sel-node'), selAlloc = $('#sel-alloc'), box = $('#egg-vars');
const fMem = $('#f-mem'), fCpu = $('#f-cpu'), fDisk = $('#f-disk'), nameIn = $('#srv-name');
const eggRadio = () => cs.querySelector('input[name=egg_id]:checked');
const mb = (n) => (n >= 1024 ? (n / 1024).toFixed(n % 1024 ? 1 : 0) + ' GB' : n + ' MB');
const fillAlloc = () => {
selAlloc.replaceChildren(el('option', { value: 'auto' }, 'Automatic (first free port)'));
free.filter((a) => String(a.node_id) === selNode.value).forEach((a) => selAlloc.appendChild(el('option', { value: a.id }, a.ip + ':' + a.port)));
selAlloc.appendChild(el('option', { value: 'custom' }, 'Custom port…'));
};
const fillVars = () => {
box.replaceChildren();
const r = eggRadio();
let vars = [];
try { vars = JSON.parse(eggs[r ? r.value : ''] || '[]'); } catch { /* ignore */ }
vars.forEach((v) => {
const label = el('label', {}, v.name + ' ');
if (v.options) {
const sel = el('select', { name: 'env[' + v.key + ']' });
Object.keys(v.options).forEach((k) => { const o = el('option', { value: k }, v.options[k]); if (k === (v.default || '')) o.selected = true; sel.appendChild(o); });
label.appendChild(sel);
} else label.appendChild(el('input', { name: 'env[' + v.key + ']', value: v.default || '', maxlength: '64' }));
box.appendChild(label);
});
const ver = box.querySelector('[name="env[MC_VERSION]"]'), typ = box.querySelector('[name="env[SERVER_TYPE]"]');
if (ver && typ) {
const msg = el('small', { class: 'ver-msg' }); ver.parentNode.appendChild(msg);
let tm;
const check = () => {
clearTimeout(tm);
tm = setTimeout(async () => {
if (!ver.value) return;
try {
const d = await api('/admin/servers/check-version?' + new URLSearchParams({ type: typ.value, v: ver.value }));
msg.textContent = d.ok ? 'Version found.' : d.error; msg.className = 'ver-msg ' + (d.ok ? 'good' : 'bad'); ver.setCustomValidity(d.ok ? '' : d.error);
} catch { msg.textContent = ''; ver.setCustomValidity(''); }
}, 350);
};
ver.addEventListener('input', check); typ.addEventListener('change', check); check();
}
};
const bar = (id, used, add, total) => {
const pct = total ? Math.min(100, Math.round(((used + add) / total) * 100)) : 0;
$('#' + id).style.width = pct + '%';
return pct;
};
const summary = () => {
const r = eggRadio(), n = nodes.find((x) => String(x.id) === selNode.value);
const mem = +fMem.value || 0, disk = +fDisk.value || 0, cpu = +fCpu.value || 0;
$('#s-egg').textContent = r ? r.dataset.name : '–';
$('#s-name').textContent = nameIn.value || '–';
$('#s-size').textContent = mb(mem) + ' · ' + (cpu ? cpu + '% CPU' : 'unlimited CPU') + ' · ' + mb(disk);
const opt = selAlloc.selectedOptions[0], cp = $('#custom-port');
$('#custom-port-row').hidden = selAlloc.value !== 'custom'; cp.required = selAlloc.value === 'custom';
$('#s-addr').textContent = selAlloc.value === 'custom' ? ':' + (cp.value || '?') : opt ? opt.textContent : '–';
if (n) {
const pm = bar('s-mem', +n.used_mem, mem, +n.memory * (100 + +n.mem_over) / 100), pd = bar('s-disk', +n.used_disk, disk, +n.disk * (100 + +n.disk_over) / 100);
$('#s-mem-t').textContent = pm + '% of ' + mb(+n.memory);
$('#s-disk-t').textContent = pd + '% of ' + mb(+n.disk);
$('#s-warn').hidden = !(+n.used_mem + mem > +n.memory * (100 + +n.mem_over) / 100 || +n.used_disk + disk > +n.disk * (100 + +n.disk_over) / 100);
}
};
cs.querySelectorAll('.size').forEach((b) => b.addEventListener('click', () => {
fMem.value = b.dataset.mem; fCpu.value = b.dataset.cpu; fDisk.value = b.dataset.disk;
cs.querySelectorAll('.size').forEach((x) => x.classList.toggle('on', x === b));
summary();
}));
const words = ['Ember', 'Harbor', 'Quartz', 'Willow', 'Falcon', 'Cobalt', 'Maple', 'Tundra', 'Nova', 'Basalt'];
const rn = $('#rand-name');
if (rn) rn.addEventListener('click', () => { nameIn.value = words[Math.floor(Math.random() * words.length)] + '-' + (100 + Math.floor(Math.random() * 900)); summary(); });
cs.addEventListener('input', summary);
cs.addEventListener('change', (e) => { if (e.target.name === 'egg_id') fillVars(); if (e.target === selNode) fillAlloc(); summary(); });
const nestSel = $('#nest-sel'), eggSel = $('#egg-sel'), eggDesc = $('#egg-desc');
const syncEgg = () => {
const r = eggRadio(); if (!r) return;
eggSel.value = r.value; eggDesc.textContent = r.dataset.desc || '';
};
const filterNest = () => {
const n = nestSel.value;
cs.querySelectorAll('.tile.game').forEach((t) => { t.hidden = !!n && t.querySelector('input').dataset.nest !== n; });
[...eggSel.options].forEach((o) => { o.hidden = !!n && o.dataset.nest !== n; });
const r = eggRadio();
if (r && n && r.dataset.nest !== n) { const f = [...cs.querySelectorAll('input[name=egg_id]')].find((x) => x.dataset.nest === n); if (f) { f.checked = true; fillVars(); summary(); } }
syncEgg();
};
if (nestSel) {
nestSel.addEventListener('change', filterNest);
eggSel.addEventListener('change', () => { const f = cs.querySelector('input[name=egg_id][value="' + eggSel.value + '"]'); if (f) { f.checked = true; fillVars(); summary(); syncEgg(); } });
cs.querySelectorAll('input[name=egg_id]').forEach((x) => x.addEventListener('change', syncEgg));
syncEgg();
}
fillAlloc(); fillVars(); summary();
}
document.querySelectorAll('i[data-fill]').forEach((b) => { b.style.width = b.dataset.fill + '%'; });
document.querySelectorAll('a[data-open]').forEach((a) => a.addEventListener('click', (e) => {
const d = document.getElementById(a.dataset.open);
if (d) { e.preventDefault(); d.open = true; d.scrollIntoView({ block: 'start' }); }
}));
document.querySelectorAll('time.lt').forEach((t) => {
const d = new Date(Number(t.dataset.ts) * 1000);
if (!isNaN(d)) {
t.textContent = d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
t.title = Intl.DateTimeFormat().resolvedOptions().timeZone;
}
});
const sg = $('#srv-grid');
if (sg) {
const apply = (v) => {
sg.classList.toggle('list', v === 'list');
document.querySelectorAll('.seg-btn').forEach((b) => b.classList.toggle('on', b.dataset.view === v));
};
let saved = 'grid';
try { saved = localStorage.getItem('srv-view') || 'grid'; } catch { /* storage blocked */ }
apply(saved);
document.querySelectorAll('.seg-btn').forEach((b) => b.addEventListener('click', () => {
apply(b.dataset.view);
try { localStorage.setItem('srv-view', b.dataset.view); } catch { /* ignore */ }
}));
}
const eq = $('#egg-q');
if (eq) {
let cat = '';
const run = () => {
const q = eq.value.trim().toLowerCase();
document.querySelectorAll('#egg-grid .egg').forEach((g) => {
g.hidden = !((!cat || g.dataset.cat === cat) && (!q || g.dataset.text.includes(q)));
});
};
eq.addEventListener('input', run);
document.querySelectorAll('#egg-chips .chip').forEach((c) => c.addEventListener('click', () => {
cat = c.dataset.cat;
document.querySelectorAll('#egg-chips .chip').forEach((x) => x.classList.toggle('on', x === c));
run();
}));
const ef = $('#egg-file');
if (ef) ef.addEventListener('change', () => {
const f = ef.files[0];
if (f && f.size < 400000) f.text().then((t) => { $('#egg-json').value = t; });
});
}
const lfile = $('#logo-file');
if (lfile) lfile.addEventListener('change', () => {
$('#logo-name').textContent = lfile.files[0] ? lfile.files[0].name : 'No file chosen';
$('#logo-up').disabled = !lfile.files[0];
});
const lf = $('#look-form');
if (lf) {
const F = (n) => lf.querySelector('[name=' + n + ']');
const checked = (n) => { const e = lf.querySelector('input[name=' + n + ']:checked'); return e ? e.value : ''; };
const looks = JSON.parse(lf.dataset.looks || '{}');
lf.querySelectorAll('.sw i').forEach((i) => { i.style.background = i.dataset.c; });
const names = ['rtl', 'rtr', 'rbr', 'rbl'], all = $('#r-all'), split = $('#r-split'), prev = $('#look-prev');
const lum = (h) => { const c = [1, 3, 5].map((i) => parseInt(h.substr(i, 2), 16) / 255).map((v) => (v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4))); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; };
const setCheck = (n, v) => { const e = lf.querySelector('input[name=' + n + '][value="' + v + '"]'); if (e) e.checked = true; };
const paint = () => {
const c = names.map((n) => +F(n).value), s = (f) => c.map((x) => Math.round(x * f) + 'px').join(' ');
names.forEach((n) => { lf.querySelector('[data-rv=' + n + ']').textContent = F(n).value; });
$('#r-val').textContent = c[0];
const bg = F('bg').value, panel = F('panel').value, text = F('text').value, acc = F('accent').value;
const raised = checked('card') === 'raised', btn = checked('btn'), ink = '#000000';
const on = lum(acc) > 0.35 ? '#0d1613' : '#f4f7f5';
const mix = (a, b, p) => 'color-mix(in srgb, ' + a + ' ' + (100 - p) + '%, ' + b + ' ' + p + '%)';
const tint = mix(panel, acc, 20);
const B = btn === 'soft' ? [tint, text, mix(panel, acc, 50), '1px'] : btn === 'outline' ? ['transparent', text, acc, '1.5px'] : [acc, on, raised ? ink : mix(acc, '#000', 20), raised ? '2px' : '1px'];
const dens = { compact: '11px 14px', cozy: '18px 20px', roomy: '24px 26px' }[checked('dens')] || '18px 20px';
const fonts = { sans: 'system-ui,sans-serif', grotesk: '"Bahnschrift","Franklin Gothic Medium","Arial Narrow",sans-serif', mono: 'ui-monospace,Consolas,monospace' };
const caps = checked('caps') === '1';
const v = { bg, panel, text, accent: acc, '--panel2': mix(panel, text, 7), '--edge': mix(panel, text, 26), '--muted': mix(text, bg, 42), '--tint': tint, '--ink': ink,
'--rb': s(1), '--rm': s(0.7), '--rs': s(0.5), '--pill': Math.max.apply(null, c) > 0 ? '99px' : '0', '--cbw': { flat: '0px', line: '1px', raised: '2px' }[checked('card')] || '1px',
'--csh': raised ? '4px 4px 0 ' + ink : 'none', '--cpad': dens, '--btn-bg': B[0], '--btn-fg': B[1], '--btn-bd': B[2], '--btn-bw': B[3], '--btn-sh': raised && btn === 'solid' ? '2px 2px 0 ' + ink : 'none',
'--caps': caps ? 'uppercase' : 'none', '--caps-ls': caps ? '.06em' : '0', '--font': fonts[checked('font')] || fonts.sans };
Object.keys(v).forEach((k) => prev.style.setProperty(k.charAt(0) === '-' ? k : '--' + k, v[k]));
prev.style.fontFamily = v['--font'];
};
const custom = () => { $('#look-key').value = 'custom'; setCheck('look_pick', 'custom'); };
const apply = (key) => {
const l = looks[key]; if (!l) return;
['btn', 'card', 'dens', 'font'].forEach((k) => setCheck(k, l[k]));
setCheck('caps', String(l.caps)); all.value = l.radius; names.forEach((n) => { F(n).value = l.radius; });
split.checked = false; $('#r-corners').hidden = true; $('#look-key').value = key; paint();
};
lf.querySelectorAll('input[name=look_pick]').forEach((r) => r.addEventListener('change', () => { if (r.value === 'custom') $('#look-key').value = 'custom'; else apply(r.value); }));
lf.querySelectorAll('input[name=preset]').forEach((r) => r.addEventListener('change', () => {
['bg', 'panel', 'text', 'accent'].forEach((k) => { F(k).value = r.dataset[k]; }); paint();
}));
all.addEventListener('input', () => { names.forEach((n) => { F(n).value = all.value; }); custom(); paint(); });
names.forEach((n) => F(n).addEventListener('input', () => { custom(); paint(); }));
split.addEventListener('change', () => { $('#r-corners').hidden = !split.checked; if (!split.checked) { names.forEach((n) => { F(n).value = all.value; }); paint(); } });
['btn', 'card', 'dens', 'font', 'caps'].forEach((k) => lf.querySelectorAll('input[name=' + k + ']').forEach((r) => r.addEventListener('change', () => { custom(); paint(); })));
['bg', 'panel', 'text', 'accent'].forEach((k) => F(k).addEventListener('input', paint));
paint();
}
const nw = $('#node-wizard');
if (nw) {
const q = (n) => nw.querySelector('[name=' + n + ']');
const mbf = (n) => (n >= 1024 ? (n / 1024).toFixed(n % 1024 ? 1 : 0) + ' GB' : n + ' MB');
const sum = () => {
const mem = +q('memory').value || 0, disk = +q('disk').value || 0, mo = +q('mem_over').value || 0, dov = +q('disk_over').value || 0;
$('#nv-mo').textContent = mo + '%'; $('#nv-do').textContent = dov + '%';
$('#ns-name').textContent = q('name').value || '–';
$('#ns-addr').textContent = (q('scheme').value + '://' + (q('fqdn').value || '…') + ':' + (q('daemon_port').value || '8443'));
$('#ns-mem').textContent = mbf(mem) + (mo ? ' → sells ' + mbf(Math.round(mem * (100 + mo) / 100)) : '');
$('#ns-disk').textContent = mbf(disk) + (dov ? ' → sells ' + mbf(Math.round(disk * (100 + dov) / 100)) : '');
const p = q('pool_ports').value.match(/^(\d+)(?:-(\d+))?$/);
$('#ns-ports').textContent = p ? ((+(p[2] || p[1])) - (+p[1]) + 1) + (((+(p[2] || p[1])) - (+p[1])) === 0 ? ' port on ' : ' ports on ') + (q('pool_ip').value || '?') : 'none yet';
$('#ns-max').textContent = +q('max_servers').value ? q('max_servers').value + ' servers' : 'unlimited';
$('#ns-maint').textContent = q('maintenance').checked ? 'Yes, closed to new servers' : 'No, accepting servers';
};
nw.querySelectorAll('.nsize').forEach((b) => b.addEventListener('click', () => {
q('memory').value = b.dataset.mem; q('disk').value = b.dataset.disk;
nw.querySelectorAll('.nsize').forEach((x) => x.classList.toggle('on', x === b)); sum();
}));
nw.querySelectorAll('.pool').forEach((b) => b.addEventListener('click', () => {
q('pool_ports').value = b.dataset.ports; q('pool_ip').value = q('pool_ip').value || '0.0.0.0';
nw.querySelectorAll('.pool').forEach((x) => x.classList.toggle('on', x === b)); sum();
}));
nw.addEventListener('input', sum); nw.addEventListener('change', sum); sum();
}
$$('[data-copy]').forEach((b) => b.addEventListener('click', () => {
navigator.clipboard?.writeText(b.dataset.copy).then(() => { b.classList.add('done'); setTimeout(() => b.classList.remove('done'), 1200); });
}));
const pg = $('#plugins');
if (pg) {
const base = pg.dataset.base; let source = 'modrinth';
const grid = $('#plug-grid'), empty = $('#plug-empty'), q = $('#plug-q'), any = $('#plug-any');
const fmt = (n) => n >= 1e6 ? (n / 1e6).toFixed(1) + 'M' : n >= 1e3 ? (n / 1e3).toFixed(1) + 'k' : String(n);
const toast = (msg, ok) => { const t = el('div', { class: 'flash-toast ' + (ok ? 'ok' : 'err'), role: 'status' }); t.append(el('div', {}, msg)); document.body.append(t); setTimeout(() => t.remove(), 4200); };
const search = async () => {
grid.replaceChildren(el('p', { class: 'muted' }, 'Searching…')); empty.hidden = true;
try {
const r = await api(base + '/plugins/search?' + new URLSearchParams({ source, q: q.value, mc: any.checked ? 'any' : '' }));
grid.replaceChildren(); empty.hidden = r.results.length > 0;
r.results.forEach((p) => {
const card = el('div', { class: 'plug' });
const ic = el('div', { class: 'plug-ic' });
if (p.icon) ic.append(el('img', { src: p.icon, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer' })); else ic.textContent = p.name.slice(0, 1).toUpperCase();
const btn = el('button', { class: 'btn sm', type: 'button' }, 'Install');
btn.addEventListener('click', async () => {
btn.disabled = true; btn.textContent = 'Installing…';
try {
const d = await api(base + '/plugins/install', 'POST', { source, id: p.id, mc: any.checked ? 'any' : '' });
btn.textContent = 'Installed'; toast(d.file + ' added to /plugins. Restart the server to load it.', true); installed();
} catch (e) { btn.disabled = false; btn.textContent = 'Install'; toast(e.message, false); }
});
const body = el('div', { class: 'plug-body' });
body.append(el('b', {}, p.name), el('small', { class: 'muted' }, (p.author ? 'by ' + p.author + ' · ' : '') + fmt(p.downloads) + ' downloads'), el('p', {}, p.desc));
card.append(ic, body, btn);
grid.append(card);
});
} catch (e) { grid.replaceChildren(); toast(e.message, false); }
};
const installed = async () => {
const tb = $('#plug-installed'); tb.replaceChildren();
try {
const r = await api(base + '/files/list?path=' + encodeURIComponent('/plugins'));
const list = Array.isArray(r) ? r : (r.files || r.entries || []);
const files = list.filter((f) => !f.dir && /\.jar$/i.test(f.name));
$('#plug-none').hidden = files.length > 0;
files.forEach((f) => {
const del = el('button', { class: 'btn sm danger', type: 'button' }, 'Delete');
del.addEventListener('click', async () => { if (!confirm('Delete ' + f.name + '?')) return; try { await api(base + '/files/delete', 'POST', { path: '/plugins/' + f.name }); installed(); } catch (e) { toast(e.message, false); } });
const act = el('td', { class: 'r' }); act.append(del);
const tr = el('tr'); tr.append(el('td', { class: 'mono' }, f.name), el('td', { class: 'r muted small' }, bytes(f.size)), act); tb.append(tr);
});
} catch (e) { $('#plug-none').hidden = false; }
};
$$('.src-seg .seg-btn').forEach((b) => b.addEventListener('click', () => { source = b.dataset.source; $$('.src-seg .seg-btn').forEach((x) => x.classList.toggle('on', x === b)); search(); }));
$('#plug-form').addEventListener('submit', (e) => { e.preventDefault(); search(); });
any.addEventListener('change', search); $('#plug-refresh').addEventListener('click', installed);
search(); installed();
}
})();
(() => {
'use strict';
document.querySelectorAll('i[data-c]').forEach((i) => { i.style.background = i.dataset.c; });
document.querySelectorAll('[data-vars]').forEach((el) => {
el.dataset.vars.split(';').forEach((kv) => { const i = kv.indexOf(':'); if (i > 0) el.style.setProperty(kv.slice(0, i).trim(), kv.slice(i + 1).trim()); });
el.style.background = 'var(--bg)';
});
document.querySelectorAll('[data-rc]').forEach((e) => e.style.setProperty('--rc', e.dataset.rc));
const rf = document.getElementById('role-form');
if (rf) {
const col = document.getElementById('role-color'), pill = document.getElementById('role-pill'), nm = rf.querySelector('[name=name]');
const paint = () => { pill.style.setProperty('--rc', col.value); pill.textContent = nm.value || 'Role name'; rf.querySelectorAll('.swatch').forEach((b) => b.classList.toggle('on', b.dataset.color.toLowerCase() === col.value.toLowerCase())); };
rf.querySelectorAll('.swatch').forEach((b) => b.addEventListener('click', () => { col.value = b.dataset.color; paint(); }));
col.addEventListener('input', paint); nm.addEventListener('input', paint); paint();
rf.addEventListener('change', (e) => {
const t = e.target;
if (t.name !== 'perms[]') return;
const base = t.value.split('.')[0];
if (t.checked && t.value.indexOf('.') > 0) { const b = rf.querySelector('input[value="' + base + '"]'); if (b) b.checked = true; }
if (!t.checked && t.value.indexOf('.') < 0) rf.querySelectorAll('input[name="perms[]"]').forEach((c) => { if (c.value.indexOf(base + '.') === 0) c.checked = false; });
});
}
document.querySelectorAll('.perm-preset').forEach((b) => b.addEventListener('click', () => {
const all = b.dataset.perms === '*', on = b.dataset.perms ? b.dataset.perms.split(',') : [];
document.querySelectorAll('#role-form input[name="perms[]"]').forEach((c) => { c.checked = all || on.indexOf(c.value) !== -1; });
}));
const burger = document.getElementById('burger');
const scrim = document.getElementById('scrim');
if (burger) burger.addEventListener('click', () => document.body.classList.toggle('nav-open'));
if (scrim) scrim.addEventListener('click', () => document.body.classList.remove('nav-open'));
document.addEventListener('click', (e) => {
const d = document.querySelector('details.acct[open]');
if (d && !d.contains(e.target)) d.removeAttribute('open');
});
document.addEventListener('keydown', (e) => {
if (e.key === 'Escape') document.querySelector('details.acct[open]')?.removeAttribute('open');
});
})();