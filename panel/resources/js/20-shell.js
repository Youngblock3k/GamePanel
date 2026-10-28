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
