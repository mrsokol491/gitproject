// marketplace.js — предложения, клонирование, копирование, фильтры.
const $ = (id) => document.getElementById(id);
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

function toast(text, ok = true) {
  const el = $('mp-toast');
  if (!el) return;
  el.textContent = text;
  el.className = 'mp-toast ' + (ok ? 'mp-toast--ok' : 'mp-toast--err');
  el.hidden = false;
  clearTimeout(toast._t);
  toast._t = setTimeout(() => { el.hidden = true; }, 4200);
}

async function api(url, options = {}) {
  const res = await fetch(url, options);
  return res.json();
}

const openBtn = $('mp-open-submit');
if (openBtn) {
  openBtn.addEventListener('click', () => {
    const box = $('mp-submit');
    box.hidden = !box.hidden;
    if (!box.hidden) $('mp-url').focus();
  });
}

const submitForm = $('mp-submit-form');
if (submitForm) {
  submitForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = $('mp-send');
    const msg = $('mp-submit-msg');
    btn.disabled = true;
    msg.textContent = 'Отправка…';
    msg.className = 'mp-msg';
    const r = await api('/api/marketplace.php?action=submit', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        csrf,
        url: $('mp-url').value.trim(),
        category: $('mp-cat').value,
        note: $('mp-note').value.trim(),
      }),
    });
    btn.disabled = false;
    if (r.ok) {
      msg.textContent = '✅ ' + r.data.message;
      msg.className = 'mp-msg mp-msg--ok';
      submitForm.reset();
      toast('Предложение отправлено на модерацию');
    } else {
      msg.textContent = '✗ ' + r.error;
      msg.className = 'mp-msg mp-msg--err';
      toast(r.error, false);
    }
  });
}

const sortSel = $('mp-sort');
if (sortSel) {
  sortSel.addEventListener('change', () => $('mp-filters').submit());
}

const cloneBtn = $('mp-clone');
if (cloneBtn) {
  cloneBtn.addEventListener('click', async () => {
    const log = $('mp-clone-log');
    const { host, owner, slug, cloneUrl } = cloneBtn.dataset;
    cloneBtn.disabled = true;
    log.hidden = false;
    log.textContent = 'Клонирование ' + owner + '/' + slug + '…';
    let r;
    try {
      r = await api('/api/clone.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ host, owner, slug, local_name: slug }),
      });
    } catch {
      r = { ok: false, error: 'Сеть недоступна' };
    }
    if (r.ok) {
      log.textContent = '✅ Клонировано: ' + (r.data?.slug || slug) + '\n' + (r.data?.log || '');
      cloneBtn.textContent = '✓ У вас';
      toast('Проект клонирован в projects/');
      const link = document.createElement('a');
      link.className = 'btn btn--primary';
      link.href = '/hub/project/' + encodeURIComponent(slug) + '/';
      link.textContent = 'Открыть в хабе';
      cloneBtn.replaceWith(link);
    } else {
      log.textContent = '✗ ' + (r.error || 'Ошибка') + '\n' + (r.data?.log || '')
        + '\n\nВручную:\ngit clone ' + cloneUrl + ' ~/gitproject/projects/' + slug;
      cloneBtn.disabled = false;
      toast(r.error || 'Не удалось клонировать', false);
    }
  });
}

const copyBtn = $('mp-copy');
if (copyBtn) {
  copyBtn.addEventListener('click', async () => {
    const cmd = copyBtn.dataset.cmd || '';
    try {
      await navigator.clipboard.writeText(cmd);
      toast('Команда скопирована');
    } catch {
      const ta = document.createElement('textarea');
      ta.value = cmd;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      ta.remove();
      toast('Команда скопирована');
    }
  });
}