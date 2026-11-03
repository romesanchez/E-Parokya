// Live field guide for the request form (mirrors the original React behaviour).
(function () {
  var guides = window.PSC_GUIDES || {};
  var form = document.getElementById('request-form');
  if (!form) return;

  var svcSel = document.getElementById('service_type');
  var kicker = document.getElementById('guide-kicker');
  var main = document.getElementById('guide-main');
  var tips = document.getElementById('guide-tips');
  var body = document.getElementById('guide-body');
  var current = null;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text) n.textContent = text;
    return n;
  }

  function render(field) {
    var g = guides[svcSel.value];
    if (!g) return;
    current = field;
    body.querySelectorAll('.guide-example, .guide-title').forEach(function (n) { n.remove(); });
    if (field) {
      var f = g.fields[field];
      kicker.textContent = 'Guide · ' + g.label;
      var title = el('h2', 'guide-title', f.title);
      body.insertBefore(title, main);
      main.textContent = f.hint;
      tips.hidden = true;
      if (f.example) {
        var p = el('p', 'guide-example muted small');
        p.append('For example: ');
        p.append(el('span', 'fg strong', f.example));
        body.appendChild(p);
      }
    } else {
      kicker.textContent = g.label;
      main.textContent = g.overview;
      tips.hidden = false;
      tips.innerHTML = '';
      g.tips.forEach(function (t) {
        var li = el('li');
        li.append(el('span', 'dot', '•'), el('span', '', t));
        tips.appendChild(li);
      });
    }
  }

  function updatePlaceholders() {
    var g = guides[svcSel.value];
    if (!g) return;
    [['full_name', 'fullName'], ['phone', 'phone'], ['notes', 'notes']].forEach(function (p) {
      var ph = g.fields[p[1]].placeholder;
      if (ph) document.getElementById(p[0]).placeholder = ph;
    });
  }

  form.addEventListener('focusin', function (e) {
    var f = e.target.getAttribute && e.target.getAttribute('data-field');
    if (f) render(f);
  });
  svcSel.addEventListener('change', function () {
    updatePlaceholders();
    render(current);
  });
})();
