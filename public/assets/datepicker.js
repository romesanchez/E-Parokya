/*
 * Parish date picker.
 * Turns every <input type="date"> into a calendar pop-up that matches the site theme and font.
 * - The visible input keeps its id, class, required and data-* attributes.
 * - A hidden input carries the real value (YYYY-MM-DD) under the original name, so forms and PHP
 *   work exactly as before. min / max attributes are respected.
 * - On phones (narrow screens) the calendar opens as a centred card with a dimmed background.
 * - Add data-native-date to an input to leave it as a normal browser date field.
 */
(function () {
  'use strict';
  if (window.__parishDatePicker) { return; }
  window.__parishDatePicker = true;

  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  var DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  var MOBILE_MAX = 600;

  /* ---------- helpers ---------- */
  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function parse(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '');
    if (!m) { return null; }
    var d = new Date(+m[1], +m[2] - 1, +m[3]);
    return (d.getFullYear() === +m[1] && d.getMonth() === +m[2] - 1 && d.getDate() === +m[3]) ? d : null;
  }
  function label(d) { return MONTHS[d.getMonth()].slice(0, 3) + ' ' + d.getDate() + ', ' + d.getFullYear(); }
  function addMonths(d, n) {
    var t = new Date(d.getFullYear(), d.getMonth() + n, 1);
    var dim = new Date(t.getFullYear(), t.getMonth() + 1, 0).getDate();
    return new Date(t.getFullYear(), t.getMonth(), Math.min(d.getDate(), dim));
  }
  function outOfRange(rec, s) { return (rec.min && s < rec.min) || (rec.max && s > rec.max); }

  /* ---------- styles (inserted once) ---------- */
  var css = [
    '.dp-field{cursor:pointer;padding-right:2.5rem !important;background-image:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%236b2d26\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\'%3E%3Crect x=\'3\' y=\'4\' width=\'18\' height=\'18\' rx=\'2\'/%3E%3Cpath d=\'M16 2v4M8 2v4M3 10h18\'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .8rem center;background-size:1.1rem}',
    '.dp-backdrop{position:fixed;inset:0;z-index:9998;background:rgb(30 18 14 / .5)}',
    '.dp{position:fixed;z-index:9999;width:20rem;max-width:calc(100vw - 1rem);max-height:calc(100vh - 1rem);overflow-y:auto;background:var(--card,#fff);color:var(--foreground,#3a2a22);border-radius:.9rem;box-shadow:0 18px 50px rgb(40 20 14 / .3),0 2px 6px rgb(40 20 14 / .15);font-family:var(--font-sans,"Poppins",system-ui,sans-serif);font-size:.9rem;line-height:1.4;-webkit-user-select:none;user-select:none;animation:dp-in .14s ease-out}',
    '.dp[hidden],.dp-backdrop[hidden]{display:none}',
    '.dp.dp-modal{left:50%;top:50%;transform:translate(-50%,-50%);width:min(92vw,22rem)}',
    '@keyframes dp-in{from{opacity:0}to{opacity:1}}',
    '@media (prefers-reduced-motion:reduce){.dp{animation:none}}',
    '.dp-head{background:var(--primary,#6b2d26);color:var(--primary-foreground,#fbf3e6);padding:1rem 1.25rem 1.1rem}',
    '.dp-year{font-size:.85rem;opacity:.8}',
    '.dp-date{font-size:1.7rem;font-weight:500;line-height:1.2;margin-top:.15rem}',
    '.dp-nav{display:flex;align-items:center;justify-content:space-between;padding:.6rem .75rem .15rem}',
    '.dp-nav button{width:2.5rem;height:2.5rem;border:0;border-radius:50%;background:transparent;color:inherit;display:grid;place-items:center;cursor:pointer}',
    '.dp-nav button:hover:not(:disabled){background:var(--secondary,#f1ebe0)}',
    '.dp-nav button:disabled{opacity:.3;cursor:not-allowed}',
    '.dp-nav svg{width:1.1rem;height:1.1rem;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}',
    '.dp-title{font-weight:600}',
    '.dp-dow,.dp-grid{display:grid;grid-template-columns:repeat(7,1fr);padding:0 .75rem}',
    '.dp-dow span{text-align:center;font-size:.75rem;color:var(--muted-foreground,#8b7d72);padding:.45rem 0}',
    '.dp-grid{gap:2px 0;padding-bottom:.4rem}',
    '.dp-day{height:2.6rem;border:0;border-radius:.55rem;background:transparent;color:inherit;font:inherit;cursor:pointer;padding:0}',
    '.dp-day:hover:not(:disabled){background:var(--secondary,#f1ebe0)}',
    '.dp-day.is-today{box-shadow:inset 0 0 0 1.5px var(--accent,#c9a24b)}',
    '.dp-day[aria-selected="true"]{background:var(--primary,#6b2d26);color:var(--primary-foreground,#fbf3e6);font-weight:600;box-shadow:none}',
    '.dp-day:disabled{opacity:.3;cursor:not-allowed}',
    '.dp button:focus-visible{outline:2px solid var(--accent,#c9a24b);outline-offset:1px}',
    '.dp-foot{display:flex;justify-content:space-between;padding:.2rem .75rem .75rem}',
    '.dp-foot button{border:0;background:transparent;color:var(--primary,#6b2d26);font:inherit;font-weight:600;padding:.45rem .8rem;border-radius:.5rem;cursor:pointer}',
    '.dp-foot button:hover:not(:disabled){background:var(--secondary,#f1ebe0)}',
    '.dp-foot button:disabled{opacity:.35;cursor:not-allowed}',
    '@media (max-height:520px){.dp-head{padding:.6rem 1.1rem}.dp-date{font-size:1.3rem}.dp-day{height:2.2rem}}'
  ].join('\n');
  var styleEl = document.createElement('style');
  styleEl.id = 'dp-style';
  styleEl.textContent = css;
  document.head.appendChild(styleEl);

  /* ---------- the pop-up (built on first use) ---------- */
  var ui = null;     // elements
  var cur = null;    // field that is currently open
  var view = null;   // first day of the month being shown
  var focusDay = null;

  var CHEV_L = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>';
  var CHEV_R = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>';

  function ensureUI() {
    if (ui) { return; }
    var back = document.createElement('div');
    back.className = 'dp-backdrop';
    back.hidden = true;

    var pop = document.createElement('div');
    pop.className = 'dp';
    pop.hidden = true;
    pop.setAttribute('role', 'dialog');
    pop.setAttribute('aria-label', 'Choose a date');
    pop.innerHTML =
      '<div class="dp-head"><div class="dp-year"></div><div class="dp-date"></div></div>' +
      '<div class="dp-nav"><button type="button" class="dp-prev" aria-label="Previous month">' + CHEV_L + '</button>' +
      '<div class="dp-title" aria-live="polite"></div>' +
      '<button type="button" class="dp-next" aria-label="Next month">' + CHEV_R + '</button></div>' +
      '<div class="dp-dow" aria-hidden="true"><span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span></div>' +
      '<div class="dp-grid" role="group"></div>' +
      '<div class="dp-foot"><button type="button" class="dp-clear">Clear</button><button type="button" class="dp-today">Today</button></div>';

    document.body.appendChild(back);
    document.body.appendChild(pop);

    ui = {
      back: back, pop: pop,
      year: pop.querySelector('.dp-year'), date: pop.querySelector('.dp-date'),
      title: pop.querySelector('.dp-title'), grid: pop.querySelector('.dp-grid'),
      prev: pop.querySelector('.dp-prev'), next: pop.querySelector('.dp-next'),
      clear: pop.querySelector('.dp-clear'), today: pop.querySelector('.dp-today')
    };

    ui.prev.addEventListener('click', function () { step(-1); });
    ui.next.addEventListener('click', function () { step(1); });
    ui.clear.addEventListener('click', function () { var rec = cur; setValue(rec, '', true); close(true); });
    ui.today.addEventListener('click', function () {
      var t = new Date();
      if (!outOfRange(cur, iso(t))) { choose(iso(t)); }
    });
    ui.back.addEventListener('click', function () { close(true); });
    ui.grid.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('.dp-day') : null;
      if (b && !b.disabled) { choose(b.getAttribute('data-iso')); }
    });
    ui.grid.addEventListener('keydown', onGridKey);

    document.addEventListener('keydown', function (e) {
      if (cur && e.key === 'Escape') { e.preventDefault(); close(true); }
    });
    document.addEventListener('pointerdown', function (e) {
      if (!cur) { return; }
      if (ui.pop.contains(e.target) || e.target === cur.input) { return; }
      close(false);
    });
    document.addEventListener('focusin', function (e) {
      if (cur && !ui.pop.contains(e.target) && e.target !== cur.input) { close(false); }
    });
    window.addEventListener('resize', function () { if (cur) { applyMode(); position(); } });
    window.addEventListener('scroll', function () { if (cur) { position(); } }, true);
  }

  function step(n) {
    var d = addMonths(focusDay, n);
    focusDay = d;
    view = new Date(d.getFullYear(), d.getMonth(), 1);
    render();
  }

  function onGridKey(e) {
    var d = new Date(focusDay.getTime()), k = e.key;
    if (k === 'ArrowLeft') { d.setDate(d.getDate() - 1); }
    else if (k === 'ArrowRight') { d.setDate(d.getDate() + 1); }
    else if (k === 'ArrowUp') { d.setDate(d.getDate() - 7); }
    else if (k === 'ArrowDown') { d.setDate(d.getDate() + 7); }
    else if (k === 'PageUp') { d = addMonths(d, -1); }
    else if (k === 'PageDown') { d = addMonths(d, 1); }
    else if (k === 'Home') { d.setDate(d.getDate() - d.getDay()); }
    else if (k === 'End') { d.setDate(d.getDate() + (6 - d.getDay())); }
    else { return; }
    e.preventDefault();
    if (outOfRange(cur, iso(d))) { return; }
    focusDay = d;
    view = new Date(d.getFullYear(), d.getMonth(), 1);
    render();
    focusActive();
  }

  function render() {
    var y = view.getFullYear(), m = view.getMonth();
    var sel = parse(cur.hidden.value);
    ui.year.textContent = sel ? sel.getFullYear() : y;
    ui.date.textContent = sel ? DAYS[sel.getDay()].slice(0, 3) + ', ' + MONTHS[sel.getMonth()].slice(0, 3) + ' ' + sel.getDate() : 'Select a date';
    ui.title.textContent = MONTHS[m] + ' ' + y;

    var first = new Date(y, m, 1).getDay();
    var count = new Date(y, m + 1, 0).getDate();
    var todayIso = iso(new Date()), selIso = sel ? iso(sel) : '', focusIso = iso(focusDay);
    var html = '', i;
    for (i = 0; i < first; i++) { html += '<span></span>'; }
    for (i = 1; i <= count; i++) {
      var dt = new Date(y, m, i), s = iso(dt);
      html += '<button type="button" class="dp-day' + (s === todayIso ? ' is-today' : '') + '" data-iso="' + s + '"' +
        ' tabindex="' + (s === focusIso ? '0' : '-1') + '"' +
        (s === selIso ? ' aria-selected="true"' : '') +
        (outOfRange(cur, s) ? ' disabled' : '') +
        ' aria-label="' + DAYS[dt.getDay()] + ', ' + MONTHS[m] + ' ' + i + ', ' + y + '">' + i + '</button>';
    }
    ui.grid.innerHTML = html;
    if (!ui.grid.querySelector('[tabindex="0"]')) {
      var f = ui.grid.querySelector('.dp-day:not(:disabled)');
      if (f) { f.setAttribute('tabindex', '0'); }
    }
    ui.prev.disabled = !!(cur.min && iso(new Date(y, m, 0)) < cur.min);
    ui.next.disabled = !!(cur.max && iso(new Date(y, m + 1, 1)) > cur.max);
    ui.today.disabled = !!outOfRange(cur, todayIso);
  }

  function focusActive() {
    var b = ui.grid.querySelector('[tabindex="0"]');
    if (b) { try { b.focus({ preventScroll: true }); } catch (err) { b.focus(); } }
  }

  function applyMode() {
    var mobile = window.innerWidth < MOBILE_MAX;
    ui.pop.classList.toggle('dp-modal', mobile);
    ui.back.hidden = !mobile;
  }

  function position() {
    if (!cur || ui.pop.hidden) { return; }
    if (ui.pop.classList.contains('dp-modal')) {
      ui.pop.style.left = ''; ui.pop.style.top = '';
      return;
    }
    var r = cur.input.getBoundingClientRect();
    var pw = ui.pop.offsetWidth, ph = ui.pop.offsetHeight;
    var left = Math.min(Math.max(8, r.left), Math.max(8, window.innerWidth - pw - 8));
    var top = r.bottom + 6;
    if (top + ph > window.innerHeight - 8 && r.top - ph - 6 > 8) { top = r.top - ph - 6; }
    ui.pop.style.left = left + 'px';
    ui.pop.style.top = Math.max(8, top) + 'px';
  }

  function open(input) {
    var rec = input.__dp;
    if (!rec || input.disabled) { return; }
    ensureUI();
    if (cur && cur !== rec) { close(false); }
    if (cur === rec && !ui.pop.hidden) { return; }
    cur = rec;

    var base = parse(rec.hidden.value) || new Date();
    var b = iso(base);
    if (rec.min && b < rec.min) { base = parse(rec.min); }
    else if (rec.max && b > rec.max) { base = parse(rec.max); }
    focusDay = base;
    view = new Date(base.getFullYear(), base.getMonth(), 1);

    ui.clear.hidden = !!input.required;
    ui.pop.hidden = false;
    applyMode();
    render();
    position();
    input.setAttribute('aria-expanded', 'true');
    focusActive();
  }

  function close(returnFocus) {
    if (!cur) { return; }
    var rec = cur;
    cur = null;
    ui.pop.hidden = true;
    ui.back.hidden = true;
    rec.input.setAttribute('aria-expanded', 'false');
    if (returnFocus) { rec.input.focus(); }
  }

  function choose(isoStr) {
    var rec = cur;
    setValue(rec, isoStr, true);
    close(true);
  }

  function setValue(rec, v, fire) {
    var d = parse(v);
    rec.hidden.value = d ? iso(d) : '';
    rec.shown = d ? label(d) : '';
    rec.input.value = rec.shown;
    if (fire) { rec.input.dispatchEvent(new Event('change', { bubbles: true })); }
  }

  /* ---------- turn a date input into a picker field ---------- */
  function enhance(input) {
    if (input.__dp || input.hasAttribute('data-native-date')) { return; }
    var initial = input.value;                       // read before the type changes
    var rec = {
      input: input,
      hidden: document.createElement('input'),
      min: parse(input.getAttribute('min')) ? input.getAttribute('min') : '',
      max: parse(input.getAttribute('max')) ? input.getAttribute('max') : '',
      shown: ''
    };
    rec.hidden.type = 'hidden';
    rec.hidden.name = input.name;
    rec.hidden.disabled = input.disabled;
    input.removeAttribute('name');
    input.type = 'text';
    input.setAttribute('autocomplete', 'off');
    input.setAttribute('inputmode', 'none');       // no on-screen keyboard on phones
    input.setAttribute('aria-haspopup', 'dialog');
    input.setAttribute('aria-expanded', 'false');
    input.classList.add('dp-field');
    if (!input.getAttribute('placeholder')) { input.setAttribute('placeholder', 'Select a date'); }
    input.parentNode.insertBefore(rec.hidden, input.nextSibling);
    input.__dp = rec;
    setValue(rec, initial, false);

    input.addEventListener('click', function () { open(input); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Tab' || e.ctrlKey || e.metaKey || e.altKey) { return; }
      if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') { e.preventDefault(); open(input); return; }
      if (e.key === 'Backspace' || e.key === 'Delete') { e.preventDefault(); setValue(rec, '', true); return; }
      if (e.key === 'Escape') { return; }
      if (e.key && e.key.length === 1) { e.preventDefault(); }
    });
    input.addEventListener('beforeinput', function (e) {
      e.preventDefault();
      if (e.inputType && e.inputType.indexOf('delete') === 0) { setValue(rec, '', true); }
    });
    input.addEventListener('input', function () { if (input.value !== rec.shown) { input.value = rec.shown; } });
    if (input.form) {
      input.form.addEventListener('reset', function () { setTimeout(function () { setValue(rec, initial, false); }, 0); });
    }
  }

  function scan(root) {
    var list = (root || document).querySelectorAll('input[type="date"]');
    for (var i = 0; i < list.length; i++) { enhance(list[i]); }
  }

  function start() {
    scan(document);
    if (window.MutationObserver) {
      new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
          for (var j = 0; j < muts[i].addedNodes.length; j++) {
            var n = muts[i].addedNodes[j];
            if (n.nodeType === 1) { if (n.matches && n.matches('input[type="date"]')) { enhance(n); } else { scan(n); } }
          }
        }
      }).observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();