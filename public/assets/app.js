/* Focused Meetings — small progressive enhancements. Everything works as plain forms; JS adds convenience. */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const pad = n => String(n).padStart(2, '0');
  const iso = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const base = (window.FM && window.FM.base) || '/';

  // Auto-submit (checkbox toggles, date changes, filter selects)
  document.addEventListener('change', e => {
    const el = e.target;
    if (el.matches('[data-autosubmit]')) { const f = el.closest('form'); if (f) f.requestSubmit ? f.requestSubmit() : f.submit(); }
  });

  // Confirmation for destructive or bulk actions
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-confirm]');
    if (b && !window.confirm(b.getAttribute('data-confirm'))) { e.preventDefault(); e.stopPropagation(); }
  }, true);

  // Dialog close buttons
  document.addEventListener('click', e => {
    const c = e.target.closest('[data-close]');
    if (c) { const d = c.closest('dialog'); if (d) d.close(); }
  });

  // ---- Remind me ----
  const remDlg = $('#reminder-dialog');
  let remDue = '';
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-open-reminder]');
    if (!b || !remDlg) return;
    const t = new Date(); t.setDate(t.getDate() + 1);
    $('#rem-action').value = b.dataset.actionId || '';
    $('#rem-body').value = b.dataset.text || '';
    $('#rem-date').value = iso(t);
    $('#rem-time').value = '09:00';
    remDue = b.dataset.due || '';
    const before = $('#preset-before'); if (before) before.hidden = !remDue;
    remDlg.showModal();
    if (!b.dataset.text) $('#rem-body').focus();
  });
  document.addEventListener('click', e => {
    const p = e.target.closest('[data-preset]');
    if (!p) return;
    const d = new Date(); let t = '09:00';
    switch (p.dataset.preset) {
      case '1h': d.setMinutes(d.getMinutes() + 60); t = pad(d.getHours()) + ':' + pad(d.getMinutes()); break;
      case 'eve': t = '18:00'; if (d.getHours() >= 18) d.setDate(d.getDate() + 1); break;
      case 'tom': d.setDate(d.getDate() + 1); break;
      case 'mon': d.setDate(d.getDate() + (((8 - d.getDay()) % 7) || 7)); break;
      case 'before': if (remDue) { const x = new Date(remDue + 'T00:00:00'); x.setDate(x.getDate() - 1); if (iso(x) > iso(new Date())) { d.setTime(x.getTime()); } else { d.setMinutes(d.getMinutes() + 60); t = pad(d.getHours()) + ':' + pad(d.getMinutes()); } } break;
    }
    $('#rem-date').value = iso(d); $('#rem-time').value = t;
  });

  // ---- Send reminder (nudge) ----
  const nudgeDlg = $('#nudge-dialog');
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-open-nudge]');
    if (!b || !nudgeDlg) return;
    const first = (b.dataset.owner || '').split(' ')[0];
    $('#nudge-form').action = base.replace(/\/?$/, '/') + 'actions/' + b.dataset.actionId + '/nudge';
    $('#nudge-to').textContent = 'To ' + b.dataset.owner;
    $('#nudge-msg').value = 'Hi ' + first + ', a quick reminder about "' + b.dataset.text + '". It is due ' + b.dataset.due + (b.dataset.overdue === '1' ? ' and is now overdue' : '') + '. Please update the status or add a note.\n\nThanks,\n' + ((window.FM && window.FM.me) || '');
    nudgeDlg.showModal();
  });

  // ---- Quick add: icon changes when assigning to someone else ----
  const owner = $('[data-assign-select]');
  if (owner) {
    const btn = $('#qa-submit'); const me = owner.options[0] && owner.querySelector('option[selected]') ? owner.querySelector('option[selected]').value : owner.value;
    const sync = () => {
      const other = owner.value !== me;
      btn.innerHTML = other ? btn.dataset.iconOther : btn.dataset.iconSelf;
      const label = other ? 'Assign task to ' + owner.options[owner.selectedIndex].text : 'Add to-do';
      btn.setAttribute('aria-label', label); btn.title = label;
    };
    owner.addEventListener('change', sync); sync();
  }

  // ---- Attendance / invitee chips ----
  document.addEventListener('change', e => {
    const cb = e.target;
    if (cb.matches('.attend input[type=checkbox]')) cb.closest('label').classList.toggle('off', !cb.checked);
  });

  // ---- Minutes page: add action item without losing typed minutes ----
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-add-action]');
    if (!b) return;
    const box = b.closest('[data-action-form]');
    const f = $('#add-action-form');
    const get = k => box.querySelector('[data-f="' + k + '"]').value.trim();
    if (!get('title')) { box.querySelector('[data-f="title"]').focus(); return; }
    f.agenda_item_id.value = box.dataset.actionForm;
    ['title', 'priority', 'owner_id', 'deadline'].forEach(k => { f[k].value = get(k); });
    $$('#minutes-form textarea[name^="discussion"]').forEach(t => {
      const h = document.createElement('input'); h.type = 'hidden'; h.name = t.name; h.value = t.value; f.appendChild(h);
    });
    f.submit();
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Enter' && e.target.matches('[data-action-form] input')) { e.preventDefault(); e.target.closest('[data-action-form]').querySelector('[data-add-action]').click(); }
  });

  // ---- Copy MoM as text ----
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-copy]');
    if (!b) return;
    const el = $(b.dataset.copy); if (!el) return;
    const done = () => { const o = b.innerHTML; b.textContent = 'Copied'; setTimeout(() => { b.innerHTML = o; }, 1500); };
    if (navigator.clipboard) navigator.clipboard.writeText(el.innerText).then(done, () => { selectEl(el); });
    else selectEl(el);
  });
  function selectEl(el) { const r = document.createRange(); r.selectNodeContents(el); const s = getSelection(); s.removeAllRanges(); s.addRange(r); }

  // ---- Note images preview ----
  const files = $('#note-files');
  if (files) {
    const prev = $('#note-previews');
    const render = () => {
      prev.innerHTML = '';
      Array.from(files.files).forEach(f => {
        if (!f.type.startsWith('image/')) return;
        const url = URL.createObjectURL(f);
        const d = document.createElement('div'); d.className = 'thumb';
        d.innerHTML = '<img alt="">'; d.firstChild.src = url; prev.appendChild(d);
      });
    };
    files.addEventListener('change', render);
    const drop = $('#note-form');
    drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('over'); });
    drop.addEventListener('dragleave', () => drop.classList.remove('over'));
    drop.addEventListener('drop', e => {
      e.preventDefault(); drop.classList.remove('over');
      if (e.dataTransfer.files.length) { const dt = new DataTransfer(); [...files.files, ...e.dataTransfer.files].forEach(f => dt.items.add(f)); files.files = dt.files; render(); }
    });
    document.addEventListener('paste', e => {
      const imgs = [...(e.clipboardData?.files || [])].filter(f => f.type.startsWith('image/'));
      if (!imgs.length) return;
      const dt = new DataTransfer(); [...files.files, ...imgs].forEach(f => dt.items.add(f)); files.files = dt.files; render();
    });
  }

  // ---- SMTP presets ----
  const preset = $('#smtp-preset');
  if (preset) {
    const presets = JSON.parse(preset.dataset.presets);
    preset.addEventListener('change', () => {
      const p = presets[preset.value]; if (!p) return;
      if (p.host) $('#smtp-host').value = p.host;
      $('#smtp-port').value = p.port; $('#smtp-enc').value = p.encryption; $('#smtp-help').textContent = p.help;
    });
  }

  // ---- Chart tooltips ----
  const tip = $('#tip');
  if (tip) {
    document.addEventListener('mousemove', e => {
      const el = e.target.closest && e.target.closest('[data-tip]');
      if (!el) { tip.hidden = true; return; }
      tip.innerHTML = el.getAttribute('data-tip'); tip.hidden = false;
      let x = e.clientX + 14, y = e.clientY - tip.offsetHeight - 10;
      if (x + tip.offsetWidth > innerWidth - 8) x = e.clientX - tip.offsetWidth - 14;
      if (y < 8) y = e.clientY + 16;
      tip.style.left = x + 'px'; tip.style.top = y + 'px';
    });
  }

  // ---- Meeting planner ----
  const planner = $('#planner');
  if (planner) initPlanner(planner);

  function initPlanner(form) {
    const members = window.FM_MEMBERS || [];
    const meId = window.FM_ME;
    const builder = $('#agenda-builder');
    const agendaField = $('#agenda-json');
    const peopleField = $('#new-people-json');
    let uid = 0;
    const blank = () => ({ k: ++uid, title: '', details: '', presenter: '', mins: 10, subs: [] });
    let points = [];
    try { points = JSON.parse(agendaField.value || '[]').map(p => Object.assign(blank(), p, { k: ++uid })); } catch (e) { points = []; }
    if (!points.length) points = [blank(), blank()];
    let people = [];
    try { people = JSON.parse(peopleField.value || '[]'); } catch (e) { people = []; }

    const invitees = () => {
      const list = [{ id: meId, name: (members.find(m => m.id === meId) || { name: 'Me' }).name + ' (you)' }];
      $$('#member-list input:checked').forEach(cb => list.push({ id: +cb.value, name: cb.dataset.name }));
      return list;
    };

    function renderAgenda(focusKey, focusField) {
      const inv = invitees();
      builder.innerHTML = points.map((p, i) => `
        <div class="ag-card" data-k="${p.k}">
          <div class="ag-top"><span class="ag-num">${pad(i + 1)}</span>
            <input type="text" data-f="title" value="${esc(p.title)}" placeholder="${i === 0 ? 'Agenda point, e.g. Review last week’s action items' : 'Next point'}" aria-label="Agenda point ${i + 1}" style="font-weight:500" maxlength="250">
            <div class="ag-tools">
              <button type="button" class="icon-btn" data-move="-1" aria-label="Move up" ${i === 0 ? 'disabled' : ''}>▲</button>
              <button type="button" class="icon-btn" data-move="1" aria-label="Move down" ${i === points.length - 1 ? 'disabled' : ''}>▼</button>
              <button type="button" class="icon-btn" data-remove aria-label="Remove point">✕</button></div></div>
          <div class="ag-body">
            <textarea data-f="details" placeholder="Details, context, pre-reads or links (optional)" style="min-height:54px" aria-label="Details">${esc(p.details)}</textarea>
            <div class="ag-meta">
              <label class="f">Presenter<select data-f="presenter"><option value="">Not set</option>${inv.map(m => `<option value="${m.id}" ${String(p.presenter) === String(m.id) ? 'selected' : ''}>${esc(m.name)}</option>`).join('')}</select></label>
              <label class="f">Time (min)<input type="number" min="0" max="600" step="5" data-f="mins" value="${esc(p.mins)}"></label></div>
            <div class="subs">
              ${p.subs.map((s, j) => `<div class="sub-row"><input type="text" data-sub="${j}" value="${esc(s)}" placeholder="Sub-point" aria-label="Sub-point ${j + 1}" maxlength="250"><button type="button" class="icon-btn" data-rmsub="${j}" aria-label="Remove sub-point">✕</button></div>`).join('')}
              <div><button type="button" class="btn ghost sm" data-addsub>+ Sub-point</button></div></div>
          </div></div>`).join('');
      if (focusKey) {
        const card = builder.querySelector(`[data-k="${focusKey}"]`);
        const el = card && (focusField ? card.querySelector(focusField) : card.querySelector('[data-f="title"]'));
        if (el) el.focus();
      }
      updateAlloc(); preview();
    }
    const find = el => points.find(p => p.k === +el.closest('.ag-card').dataset.k);

    builder.addEventListener('input', e => {
      const p = find(e.target); if (!p) return;
      if (e.target.dataset.f) p[e.target.dataset.f] = e.target.dataset.f === 'mins' ? Math.max(0, +e.target.value || 0) : e.target.value;
      if (e.target.dataset.sub !== undefined) p.subs[+e.target.dataset.sub] = e.target.value;
      updateAlloc(); preview();
    });
    builder.addEventListener('change', e => { const p = find(e.target); if (p && e.target.dataset.f === 'presenter') p.presenter = e.target.value; });
    builder.addEventListener('click', e => {
      const b = e.target.closest('button'); if (!b) return;
      const p = find(b); const i = points.indexOf(p);
      if (b.hasAttribute('data-remove')) { if (points.length > 1) points.splice(i, 1); else points[0] = blank(); renderAgenda(); }
      else if (b.dataset.move) { const j = i + +b.dataset.move; if (j >= 0 && j < points.length) { [points[i], points[j]] = [points[j], points[i]]; renderAgenda(); } }
      else if (b.hasAttribute('data-addsub')) { p.subs.push(''); renderAgenda(p.k, `[data-sub="${p.subs.length - 1}"]`); }
      else if (b.dataset.rmsub !== undefined) { p.subs.splice(+b.dataset.rmsub, 1); renderAgenda(); }
    });
    builder.addEventListener('keydown', e => {
      if (e.key !== 'Enter') return;
      if (e.target.dataset.sub !== undefined) { e.preventDefault(); const p = find(e.target); p.subs.splice(+e.target.dataset.sub + 1, 0, ''); renderAgenda(p.k, `[data-sub="${+e.target.dataset.sub + 1}"]`); }
      else if (e.target.dataset.f === 'title') { e.preventDefault(); const p = find(e.target); const n = blank(); points.splice(points.indexOf(p) + 1, 0, n); renderAgenda(n.k); }
    });
    $('#add-point').addEventListener('click', () => { const n = blank(); points.push(n); renderAgenda(n.k); });

    function updateAlloc() {
      const total = points.reduce((s, p) => s + (+p.mins || 0), 0);
      const dur = +$('#d-dur').value || 30;
      $('#alloc-text').textContent = total + ' / ' + dur + ' min';
      $('#alloc-bar').style.width = Math.min(100, total / dur * 100) + '%';
      $('#alloc').classList.toggle('over', total > dur);
      $('#alloc-over').hidden = total <= dur;
    }

    // Invitees
    $('#member-list').addEventListener('change', () => { renderAgenda(); countInvitees(); });
    function renderPeople() {
      $('#np-list').innerHTML = people.map((p, i) => `<span class="chip">${esc(p.name)} · ${esc(p.email)}<button type="button" data-rmp="${i}" aria-label="Remove">×</button></span>`).join('');
      countInvitees();
    }
    $('#np-list').addEventListener('click', e => { const b = e.target.closest('[data-rmp]'); if (b) { people.splice(+b.dataset.rmp, 1); renderPeople(); } });
    $('#np-add').addEventListener('click', () => {
      const name = $('#np-name').value.trim(), email = $('#np-email').value.trim(), phone = $('#np-phone').value.trim();
      if (!name || !/^\S+@\S+\.\S+$/.test(email)) { $('#np-email').focus(); $('#np-email').setCustomValidity('Enter a name and a valid email'); $('#np-email').reportValidity(); setTimeout(() => $('#np-email').setCustomValidity(''), 2000); return; }
      people.push({ name, email, phone }); $('#np-name').value = $('#np-email').value = $('#np-phone').value = ''; renderPeople(); $('#np-name').focus();
    });
    function countInvitees() { const n = $$('#member-list input:checked').length + people.length; $('#inv-count').textContent = n + (n === 1 ? ' person' : ' people') + ' invited'; }

    // Platform
    const plat = $('#d-plat');
    const syncPlat = () => {
      $('#loc-wrap').hidden = plat.value !== 'inperson';
      const w = $('#plat-warn');
      const missing = (plat.value === 'meet' && w.dataset.google !== '1') || (plat.value === 'teams' && w.dataset.ms !== '1');
      w.hidden = !missing;
      $('#plat-warn-text').textContent = missing ? (plat.value === 'meet' ? 'Google Meet' : 'Microsoft Teams') + ' isn’t connected yet, so no meeting link can be created.' : '';
      preview();
    };
    plat.addEventListener('change', syncPlat);
    $$('[data-preview]', form).forEach(el => el.addEventListener('input', () => { preview(); updateAlloc(); }));
    $('#d-dur').addEventListener('change', updateAlloc);

    function preview() {
      const title = $('#d-title').value.trim() || 'Untitled meeting';
      $('#pv-title').textContent = title; $('#pv-title2').textContent = title;
      const d = $('#d-date').value, t = $('#d-time').value;
      let when = '';
      if (d) { const x = new Date(d + 'T' + (t || '00:00')); when = x.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) + ', ' + x.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }); }
      $('#pv-when').textContent = when + ' (' + $('#d-dur').value + ' min)';
      $('#pv-where').textContent = plat.value === 'inperson' ? ($('#d-loc').value || 'Location to be confirmed') : (plat.value === 'meet' ? 'Google Meet link' : 'Microsoft Teams link') + ' (created when you send)';
      const inv = invitees();
      const pts = points.filter(p => p.title.trim());
      $('#pv-agenda').innerHTML = pts.length ? '<ol>' + pts.map(p => {
        const who = inv.find(m => String(m.id) === String(p.presenter));
        const meta = [who ? who.name.replace(' (you)', '') : '', p.mins ? p.mins + ' min' : ''].filter(Boolean).join(' · ');
        return `<li><b>${esc(p.title)}</b>${meta ? ` <span class="tag">${esc(meta)}</span>` : ''}${p.details.trim() ? `<div class="faint" style="white-space:pre-wrap">${esc(p.details)}</div>` : ''}${p.subs.filter(s => s.trim()).length ? '<ul>' + p.subs.filter(s => s.trim()).map(s => `<li>${esc(s)}</li>`).join('') + '</ul>' : ''}</li>`;
      }).join('') + '</ol>' : '<div class="faint">Add agenda points</div>';
    }

    form.addEventListener('submit', e => {
      const pts = points.filter(p => p.title.trim()).map(p => ({ title: p.title.trim(), details: p.details.trim(), presenter: p.presenter, mins: +p.mins || 0, subs: p.subs.map(s => s.trim()).filter(Boolean) }));
      if (!pts.length) { e.preventDefault(); const first = builder.querySelector('[data-f="title"]'); first.setCustomValidity('Add at least one agenda point'); first.reportValidity(); setTimeout(() => first.setCustomValidity(''), 2000); return; }
      if (!$$('#member-list input:checked').length && !people.length) { e.preventDefault(); $('#np-name').focus(); alert('Add at least one invitee.'); return; }
      agendaField.value = JSON.stringify(pts);
      peopleField.value = JSON.stringify(people);
    });

    renderAgenda(); renderPeople(); syncPlat();
  }
})();
