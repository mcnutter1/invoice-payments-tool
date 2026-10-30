/* McNutt Finance — progressive enhancement only. Every form works without JavaScript. */
(function () {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const money = (cents) => {
    const neg = cents < 0; const abs = Math.abs(Math.round(cents));
    return (neg ? '-$' : '$') + Math.floor(abs / 100).toLocaleString('en-US') + '.' + String(abs % 100).padStart(2, '0');
  };
  const num = (v) => { const n = parseFloat(String(v || '').replace(/[,$\s]/g, '')); return Number.isFinite(n) ? n : 0; };
  const halfUp = (x) => Math.sign(x) * Math.round(Math.abs(x) + 1e-9);
  const toCents = (v) => halfUp(num(v) * 100);

  // ---------- Menus (details) ----------
  document.addEventListener('click', (e) => {
    $$('details[data-menu][open]').forEach((d) => { if (!d.contains(e.target)) d.removeAttribute('open'); });
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') $$('details[data-menu][open]').forEach((d) => { d.removeAttribute('open'); $('summary', d)?.focus(); });
  });
  $$('details[data-menu]').forEach((d) => d.addEventListener('toggle', () => { if (d.open) $('input[data-filter]', d)?.focus(); }));

  // ---------- Generic helpers ----------
  $$('[data-filter]').forEach((input) => input.addEventListener('input', () => {
    const q = input.value.toLowerCase();
    $$(input.dataset.filter).forEach((el) => { el.hidden = q && !el.textContent.toLowerCase().includes(q); });
  }));
  $$('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form && el.form.submit()));
  $$('[data-back]').forEach((a) => a.addEventListener('click', (e) => { if (history.length > 1) { e.preventDefault(); history.back(); } }));
  $$('[data-check-all]').forEach((all) => all.addEventListener('change', () => {
    $$('input[name="' + all.dataset.checkAll + '"]').forEach((c) => { c.checked = all.checked; c.dispatchEvent(new Event('change', { bubbles: true })); });
  }));
  document.addEventListener('submit', (e) => {
    if (e.defaultPrevented) return;
    const form = e.target;
    const btn = e.submitter;
    const msg = (btn && btn.dataset.confirm) || form.dataset.confirm;
    if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
    if (btn && !btn.name) { setTimeout(() => { btn.disabled = true; }, 0); }
  });
  $$('[data-copy]').forEach((b) => b.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(b.dataset.copy); const t = b.textContent; b.textContent = 'Copied'; setTimeout(() => { b.textContent = t; }, 1500); } catch (_) { /* clipboard unavailable */ }
    if (b.dataset.trackUrl) {
      const body = new FormData();
      body.append('event', 'payment_details_copied');
      body.append('detail', b.dataset.track || '');
      body.append('_token', $('meta[name="csrf-token"]')?.content || '');
      fetch(b.dataset.trackUrl, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } }).catch(() => {});
    }
  }));

  // ---------- Repeating line tables ----------
  function wireLines(scope, onChange) {
    const table = $('[data-lines]', scope);
    const tpl = $('#line-template', scope) || $('#line-template');
    if (!table || !tpl) return;
    const tbody = $('tbody', table);
    let next = $$('tr[data-line]', tbody).length + 1000;
    const add = () => {
      const html = tpl.innerHTML.replace(/__I__/g, String(next++));
      tbody.insertAdjacentHTML('beforeend', html);
      const row = tbody.lastElementChild;
      onChange && onChange(row);
      $('input, select, textarea', row)?.focus();
      return row;
    };
    $$('[data-add-line]', scope).forEach((b) => b.addEventListener('click', add));
    tbody.addEventListener('click', (e) => {
      const rm = e.target.closest('[data-remove-line]');
      if (!rm) return;
      if ($$('tr[data-line]', tbody).length > 1) rm.closest('tr').remove(); else $$('input, textarea', rm.closest('tr')).forEach((i) => { if (i.type !== 'hidden') i.value = ''; });
      onChange && onChange();
    });
    scope.addEventListener('keydown', (e) => { if (e.altKey && e.key === 'Enter') { e.preventDefault(); add(); } });
    return { add };
  }

  // ---------- Invoice editor ----------
  const editor = $('[data-invoice-editor]');
  if (editor) {
    const catalog = JSON.parse($('#catalog-data')?.textContent || '{}');
    const taxes = JSON.parse($('#tax-data')?.textContent || '{}');
    const clientSel = $('[data-client-select]', editor);
    const out = (k, v) => { const el = $('[data-out="' + k + '"]', editor); if (el) el.textContent = v; };
    const recalc = () => {
      const rows = $$('tr[data-line]', editor);
      const exempt = clientSel?.selectedOptions[0]?.dataset.exempt === '1';
      const amounts = rows.map((r) => halfUp(num($('[data-qty]', r)?.value) * num($('[data-price]', r)?.value) * 100));
      const subtotal = amounts.reduce((a, b) => a + b, 0);
      const type = $('[name="discount_type"]', editor)?.value;
      const dv = $('[name="discount_value"]', editor)?.value;
      let discount = type === 'percent' ? halfUp(subtotal * num(dv) / 100) : type === 'amount' ? Math.min(toCents(dv), subtotal) : 0;
      // Largest-remainder allocation, ties by order (mirrors the server).
      const alloc = amounts.map(() => 0);
      if (subtotal > 0 && discount > 0) {
        const exact = amounts.map((a) => (discount * a) / subtotal);
        exact.forEach((x, i) => { alloc[i] = Math.floor(x + 1e-9); });
        let left = discount - alloc.reduce((a, b) => a + b, 0);
        exact.map((x, i) => [x - Math.floor(x + 1e-9), i]).sort((a, b) => b[0] - a[0]).forEach(([, i]) => { if (left > 0) { alloc[i]++; left--; } });
      }
      let tax = 0;
      rows.forEach((r, i) => {
        const net = amounts[i] - alloc[i];
        const rate = exempt ? 0 : num(taxes[$('[data-tax]', r)?.value] || 0);
        tax += halfUp(net * rate / 100);
        const cell = $('[data-amount]', r); if (cell) cell.textContent = money(amounts[i]);
      });
      out('subtotal', money(subtotal)); out('discount', discount ? '−' + money(discount) : money(0)); out('tax', money(tax)); out('total', money(subtotal - discount + tax));
    };
    const wireRow = (row) => {
      if (!row) return recalc();
      $('[data-product]', row)?.addEventListener('change', (e) => {
        const p = catalog[e.target.value]; if (!p) return recalc();
        $('[data-name]', row).value = p.name || '';
        if (!$('[data-desc]', row).value) $('[data-desc]', row).value = p.description || '';
        $('[data-price]', row).value = p.price;
        const tax = $('[data-tax]', row); if (tax) tax.value = p.tax_rate_id || '';
        const inc = $('[data-income]', row); if (inc && p.income_account_id) inc.value = p.income_account_id;
        recalc();
      });
      recalc();
    };
    wireLines(editor, wireRow);
    $$('tr[data-line]', editor).forEach(wireRow);
    editor.addEventListener('input', () => { recalc(); markDirty(); });
    editor.addEventListener('change', () => { recalc(); markDirty(); });

    // Autosave existing drafts.
    let dirty = false; let saving = false;
    const note = $('[data-dirty-note]', editor);
    function markDirty() { dirty = true; if (note) { note.hidden = false; note.textContent = 'Unsaved changes.'; } }
    const isExisting = /\/invoices\/\d+$/.test(editor.getAttribute('action') || '');
    if (isExisting) {
      setInterval(async () => {
        if (!dirty || saving || !editor.checkValidity()) return;
        saving = true; dirty = false;
        try {
          const res = await fetch(editor.action, { method: 'POST', body: new FormData(editor), headers: { 'X-Autosave': '1', Accept: 'application/json' }, credentials: 'same-origin' });
          const data = await res.json().catch(() => ({}));
          if (res.ok && data.ok) {
            const lv = $('[name="lock_version"]', editor); if (lv) lv.value = data.lock_version;
            if (note) note.textContent = 'Draft autosaved at ' + new Date().toLocaleTimeString();
          } else { dirty = true; if (note) note.textContent = data.error || 'Autosave failed — use Save draft.'; }
        } catch (_) { dirty = true; } finally { saving = false; }
      }, 20000);
      window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
      editor.addEventListener('submit', () => { dirty = false; });
    }
    recalc();
  }

  // ---------- Expense lines ----------
  $$('form').forEach((form) => {
    if ($('[data-expense-amount]', form)) {
      const total = () => { const el = $('[data-expense-total]', form); if (el) el.textContent = money($$('[data-expense-amount]', form).reduce((a, i) => a + toCents(i.value), 0)); };
      wireLines(form, total); form.addEventListener('input', total); total();
    }
    if (form.hasAttribute('data-journal-form')) {
      const sum = () => {
        const dr = $$('[data-jd]', form).reduce((a, i) => a + toCents(i.value), 0);
        const cr = $$('[data-jc]', form).reduce((a, i) => a + toCents(i.value), 0);
        $('[data-jdr]', form).textContent = money(dr); $('[data-jcr]', form).textContent = money(cr);
        const d = $('[data-jdiff]', form); d.textContent = dr === cr ? (dr ? '✓ Balanced' : '') : 'Out of balance by ' + money(Math.abs(dr - cr));
        d.className = dr === cr ? 'font-semibold text-emerald-700' : 'font-semibold text-red-700';
      };
      wireLines(form, sum); form.addEventListener('input', sum); sum();
    }
  });

  // ---------- Payment allocation ----------
  const pay = $('[data-payment-form]');
  if (pay) {
    const amount = $('[data-amount]', pay);
    const update = () => {
      const applied = $$('[data-alloc]', pay).reduce((a, i) => a + toCents(i.value), 0);
      const el = $('[data-unapplied]', pay); if (el) { const u = toCents(amount.value) - applied; el.textContent = money(u); el.className = u < 0 ? 'text-red-700' : ''; }
    };
    $('[data-auto-apply]', pay)?.addEventListener('click', () => {
      let left = toCents(amount.value);
      $$('[data-alloc]', pay).forEach((i) => { const take = Math.max(0, Math.min(left, parseInt(i.dataset.balance, 10))); i.value = take ? (take / 100).toFixed(2) : ''; left -= take; });
      update();
    });
    pay.addEventListener('input', update); update();
  }

  // ---------- Reconciliation ----------
  const rec = $('[data-reconcile]');
  if (rec) {
    const opening = parseInt(rec.dataset.opening, 10); const closing = parseInt(rec.dataset.closing, 10); const sign = parseInt(rec.dataset.sign, 10);
    const update = () => {
      const cleared = opening + sign * $$('input[name="lines[]"]:checked', rec).reduce((a, c) => a + parseInt(c.dataset.amount, 10), 0);
      const c = $('[data-cleared]', rec); const d = $('[data-difference]', rec);
      if (c) c.textContent = money(cleared);
      if (d) { d.textContent = money(closing - cleared); d.className = closing === cleared ? 'text-emerald-700' : 'text-red-700'; }
    };
    rec.addEventListener('change', update); update();
  }

  // ---------- Money movement ----------
  const mv = $('[data-movement-form]');
  if (mv) {
    const kind = $('[data-movement-kind]', mv);
    const sync = () => {
      const k = kind.value;
      $('[data-from]', mv).hidden = ['owner_contribution', 'loan_proceeds'].includes(k);
      $('[data-to]', mv).hidden = ['owner_draw', 'loan_payment'].includes(k);
    };
    kind.addEventListener('change', sync); sync();
  }

  // ---------- Contract form: prefill signer ----------
  const signerSrc = $('[data-signer-source]');
  if (signerSrc) signerSrc.addEventListener('change', () => {
    const o = signerSrc.selectedOptions[0]; const n = $('[data-signer-name-target]'); const m = $('[data-signer-email-target]');
    if (n && !n.value) n.value = o?.dataset.signerName || ''; if (m && !m.value) m.value = o?.dataset.signerEmail || '';
  });

  // ---------- Report range ----------
  const range = $('[data-range]');
  if (range) { const sync = () => $$('[data-custom]').forEach((i) => { i.closest('div').hidden = range.value !== 'custom'; }); range.addEventListener('change', sync); sync(); }

  // ---------- Rich text editor (contracts & templates) ----------
  const RTE_TAGS = { P: 1, BR: 1, HR: 1, H1: 1, H2: 1, H3: 1, H4: 1, STRONG: 1, B: 1, EM: 1, I: 1, U: 1, S: 1, SUB: 1, SUP: 1, UL: 1, OL: 1, LI: 1, BLOCKQUOTE: 1, TABLE: 1, THEAD: 1, TBODY: 1, TR: 1, TH: 1, TD: 1, A: 1 };
  const RTE_DROP = { SCRIPT: 1, STYLE: 1, IFRAME: 1, OBJECT: 1, EMBED: 1, META: 1, LINK: 1, TITLE: 1, HEAD: 1, IMG: 1, SVG: 1 };
  // Paste cleanup only; the server sanitizes again with a strict whitelist.
  const cleanHtml = (html) => {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const walk = (node) => {
      Array.from(node.childNodes).forEach((n) => {
        if (n.nodeType === 8) { n.remove(); return; }
        if (n.nodeType !== 1) return;
        if (RTE_DROP[n.tagName]) { n.remove(); return; }
        walk(n);
        if (!RTE_TAGS[n.tagName]) { n.replaceWith(...Array.from(n.childNodes)); return; }
        Array.from(n.attributes).forEach((a) => {
          const keep = (n.tagName === 'A' && a.name === 'href' && /^(https?:|mailto:)/i.test(a.value)) || ((n.tagName === 'TD' || n.tagName === 'TH') && /^(colspan|rowspan)$/.test(a.name));
          if (!keep) n.removeAttribute(a.name);
        });
      });
    };
    walk(doc.body);
    return doc.body.innerHTML;
  };
  $$('[data-rte]').forEach((wrap) => {
    const src = $('[data-rte-source]', wrap); const bar = $('[data-rte-toolbar]', wrap);
    if (!src || !bar || !document.execCommand) return;
    const disabled = wrap.hasAttribute('data-rte-disabled');
    const ed = document.createElement('div');
    ed.className = 'rte-content prose-doc';
    ed.contentEditable = disabled ? 'false' : 'true';
    ed.setAttribute('role', 'textbox'); ed.setAttribute('aria-multiline', 'true');
    ed.setAttribute('aria-label', src.getAttribute('aria-label') || $('label[for="' + src.id + '"]')?.textContent || 'Document text');
    ed.innerHTML = src.value.trim() ? cleanHtml(src.value) : '<p><br></p>';
    const required = src.required; src.required = false;
    src.hidden = true; src.insertAdjacentElement('beforebegin', ed);
    if (!disabled) bar.hidden = false;
    const sync = () => { src.value = ed.textContent.trim() || ed.querySelector('hr,li') ? ed.innerHTML : ''; };
    const exec = (cmd, val = null) => { ed.focus(); document.execCommand(cmd, false, val); sync(); refresh(); };
    const refresh = () => {
      $$('[data-cmd]', bar).forEach((b) => { try { b.setAttribute('aria-pressed', document.queryCommandState(b.dataset.cmd) ? 'true' : 'false'); } catch (_) { /* unsupported */ } });
      const block = (document.queryCommandValue('formatBlock') || 'p').toLowerCase().replace(/[<>]/g, '');
      const sel = $('[data-rte-block]', bar); if (sel) sel.value = ['h2', 'h3', 'blockquote'].includes(block) ? block : 'p';
    };
    try { document.execCommand('defaultParagraphSeparator', false, 'p'); document.execCommand('styleWithCSS', false, false); } catch (_) { /* older browsers */ }
    // Keep the editor's selection when toolbar buttons are tapped.
    bar.addEventListener('mousedown', (e) => { if (e.target.closest('button')) e.preventDefault(); });
    bar.addEventListener('click', (e) => {
      const b = e.target.closest('[data-cmd]'); if (!b) return;
      if (b.dataset.cmd === 'createLink') {
        const url = window.prompt('Link address (https://…)', 'https://');
        if (url && /^(https?:\/\/|mailto:)/i.test(url)) exec('createLink', url);
        return;
      }
      exec(b.dataset.cmd);
    });
    $('[data-rte-block]', bar)?.addEventListener('change', (e) => exec('formatBlock', '<' + e.target.value + '>'));
    $('[data-rte-field]', bar)?.addEventListener('change', (e) => { if (e.target.value) exec('insertText', '{{' + e.target.value + '}}'); e.target.value = ''; });
    ed.addEventListener('input', sync);
    ed.addEventListener('keyup', refresh); ed.addEventListener('mouseup', refresh);
    document.addEventListener('selectionchange', () => { if (document.activeElement === ed) refresh(); });
    ed.addEventListener('paste', (e) => {
      const cd = e.clipboardData; if (!cd) return;
      e.preventDefault();
      const html = cd.getData('text/html');
      if (html) document.execCommand('insertHTML', false, cleanHtml(html));
      else document.execCommand('insertText', false, cd.getData('text/plain'));
      sync();
    });
    ed.addEventListener('drop', (e) => e.preventDefault());
    src.form?.addEventListener('submit', (e) => {
      sync();
      if (required && !src.value) { e.preventDefault(); alert('Please enter the document text.'); ed.focus(); }
    }, true);
    wrap.rte = { set: (html) => { ed.innerHTML = cleanHtml(html) || '<p><br></p>'; sync(); }, isEmpty: () => !ed.textContent.trim() };
  });
  // Choosing a template loads its text into the agreement editor.
  const tplSel = $('[data-template-select]');
  if (tplSel) tplSel.addEventListener('change', () => {
    const body = tplSel.selectedOptions[0]?.dataset.body; const wrap = $('[data-rte]', tplSel.form);
    if (!body || !wrap?.rte) return;
    if (!wrap.rte.isEmpty() && !window.confirm('Replace the agreement text with this template?')) return;
    wrap.rte.set(body);
  });

  // ---------- Signature ----------
  $$('[data-sign-form]').forEach((form) => {
    const typed = $('[data-typed-name]', form); const preview = $('[data-sig-preview]', form);
    typed?.addEventListener('input', () => { preview.textContent = typed.value; });
    const canvas = $('[data-sig-canvas]', form); const data = $('[data-sig-data]', form);
    const ctx = canvas.getContext('2d'); let drawing = false; let drawn = false; let last = null;
    // Size the backing store to the displayed size (crisp on high-DPI phones); only while empty so rotation never loses a signature.
    const fit = () => {
      const r = canvas.getBoundingClientRect(); if (!r.width || drawn) return;
      const dpr = Math.min(window.devicePixelRatio || 1, 3);
      canvas.width = Math.round(r.width * dpr); canvas.height = Math.round(r.height * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.lineWidth = 2.6; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111827'; ctx.fillStyle = '#111827';
    };
    const pos = (e) => { const r = canvas.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
    canvas.addEventListener('pointerdown', (e) => {
      e.preventDefault(); fit(); drawing = true; canvas.setPointerCapture(e.pointerId);
      last = pos(e); ctx.beginPath(); ctx.arc(last[0], last[1], ctx.lineWidth / 2, 0, Math.PI * 2); ctx.fill(); drawn = true;
    });
    canvas.addEventListener('pointermove', (e) => {
      if (!drawing) return; e.preventDefault();
      const events = e.getCoalescedEvents ? e.getCoalescedEvents() : [e];
      events.forEach((ev) => {
        const p = pos(ev); const mid = [(last[0] + p[0]) / 2, (last[1] + p[1]) / 2];
        ctx.beginPath(); ctx.moveTo(last[0], last[1]); ctx.quadraticCurveTo(last[0], last[1], mid[0], mid[1]); ctx.lineTo(p[0], p[1]); ctx.stroke();
        last = p;
      });
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach((ev) => canvas.addEventListener(ev, () => { drawing = false; }));
    const clear = () => { drawn = false; ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.clearRect(0, 0, canvas.width, canvas.height); ctx.restore(); data.value = ''; fit(); };
    $('[data-sig-clear]', form).addEventListener('click', clear);
    window.addEventListener('resize', fit);
    const kinds = $$('[data-sig-kind]', form);
    const sync = () => { const draw = kinds.find((k) => k.checked)?.value === 'draw'; $('[data-sig-draw]', form).hidden = !draw; $('[data-sig-type]', form).hidden = draw; if (draw) requestAnimationFrame(fit); };
    kinds.forEach((k) => k.addEventListener('change', sync)); sync();
    form.addEventListener('submit', (e) => {
      if (kinds.find((k) => k.checked)?.value === 'draw') {
        if (!drawn) { e.preventDefault(); e.stopImmediatePropagation(); alert('Please draw your signature or switch to a typed signature.'); return; }
        // Export at a fixed size so the PNG stays small regardless of screen density.
        const out = document.createElement('canvas'); out.width = 600; out.height = Math.max(120, Math.round(600 * canvas.height / canvas.width));
        out.getContext('2d').drawImage(canvas, 0, 0, out.width, out.height);
        data.value = out.toDataURL('image/png');
      }
    }, true);
  });
})();
