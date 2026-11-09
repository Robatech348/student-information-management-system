(function () {
  'use strict';
  var store = {
    get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  };
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ---------- Theme ---------- */
  $$('[data-theme-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      store.set('us-theme', next);
      renderCharts();
    });
  });

  /* ---------- Sidebar ---------- */
  var mobile = function () { return window.matchMedia('(max-width: 960px)').matches; };
  if (store.get('us-sidebar') === 'collapsed' && !mobile()) document.body.classList.add('sidebar-collapsed');
  $$('[data-sidebar-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (mobile()) {
        document.body.classList.toggle('sidebar-open');
      } else {
        document.body.classList.toggle('sidebar-collapsed');
        store.set('us-sidebar', document.body.classList.contains('sidebar-collapsed') ? 'collapsed' : 'open');
        setTimeout(renderCharts, 220);
      }
    });
  });
  $$('[data-sidebar-close]').forEach(function (el) {
    el.addEventListener('click', function () { document.body.classList.remove('sidebar-open'); });
  });

  /* ---------- Global search shortcut ---------- */
  document.addEventListener('keydown', function (e) {
    var tag = (e.target.tagName || '').toLowerCase();
    if (e.key === '/' && tag !== 'input' && tag !== 'textarea' && tag !== 'select') {
      var input = $('.global-search input');
      if (input) { e.preventDefault(); input.focus(); }
    }
    if (e.key === 'Escape') {
      document.body.classList.remove('sidebar-open');
      $$('details.user-menu[open]').forEach(function (d) { d.removeAttribute('open'); });
    }
  });
  document.addEventListener('click', function (e) {
    $$('details.user-menu[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
  });

  /* ---------- Alerts ---------- */
  $$('.alert-close').forEach(function (b) {
    b.addEventListener('click', function () { b.parentNode.remove(); });
  });

  /* ---------- Confirmation dialogs for sensitive operations ---------- */
  var dialog = $('#confirm-dialog');
  document.addEventListener('submit', function (e) {
    var form = e.target;
    var msg = form.getAttribute('data-confirm');
    if (!msg || form.dataset.confirmed === '1') return;
    e.preventDefault();
    if (!dialog || typeof dialog.showModal !== 'function') {
      if (window.confirm(msg)) { form.dataset.confirmed = '1'; form.requestSubmit ? form.requestSubmit(e.submitter) : form.submit(); }
      return;
    }
    $('[data-confirm-title]', dialog).textContent = form.getAttribute('data-confirm-title') || 'Are you sure?';
    $('[data-confirm-message]', dialog).textContent = msg;
    $('[data-confirm-ok]', dialog).textContent = form.getAttribute('data-confirm-button') || 'Confirm';
    var submitter = e.submitter;
    dialog.returnValue = '';
    dialog.showModal();
    dialog.addEventListener('close', function onClose() {
      dialog.removeEventListener('close', onClose);
      if (dialog.returnValue === 'ok') {
        form.dataset.confirmed = '1';
        if (form.requestSubmit) form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
        else form.submit();
      }
    });
  }, true);

  /* ---------- Auto-submit filters ---------- */
  $$('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
  });

  /* ---------- Fill demo login ---------- */
  $$('[data-fill-login]').forEach(function (b) {
    b.addEventListener('click', function () {
      $('#email').value = b.getAttribute('data-fill-login');
      $('#password').value = b.getAttribute('data-password');
      $('#password').focus();
    });
  });

  /* ---------- Password visibility ---------- */
  $$('[data-toggle-password]').forEach(function (b) {
    b.addEventListener('click', function () {
      var input = document.getElementById(b.getAttribute('data-toggle-password'));
      input.type = input.type === 'password' ? 'text' : 'password';
      b.textContent = input.type === 'password' ? 'Show' : 'Hide';
    });
  });

  /* ---------- Dashboard widgets: show / hide, remembered per browser ---------- */
  var panelBtn = $('[data-widget-customize]');
  var panel = $('[data-widget-panel]');
  var hidden = [];
  try { hidden = JSON.parse(store.get('us-hidden-widgets') || '[]'); } catch (e) { hidden = []; }
  function applyWidgets() {
    $$('[data-widget]').forEach(function (w) { w.hidden = hidden.indexOf(w.getAttribute('data-widget')) !== -1; });
  }
  if (panel) {
    $$('[data-widget]').forEach(function (w) {
      var id = w.getAttribute('data-widget');
      var label = document.createElement('label');
      label.className = 'checkbox';
      var cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.checked = hidden.indexOf(id) === -1;
      cb.addEventListener('change', function () {
        hidden = hidden.filter(function (h) { return h !== id; });
        if (!cb.checked) hidden.push(id);
        store.set('us-hidden-widgets', JSON.stringify(hidden));
        applyWidgets();
        renderCharts();
      });
      label.appendChild(cb);
      label.appendChild(document.createTextNode(' ' + w.getAttribute('data-widget-title')));
      panel.appendChild(label);
    });
    panelBtn.addEventListener('click', function (e) { e.stopPropagation(); panel.hidden = !panel.hidden; });
    document.addEventListener('click', function (e) { if (!panel.contains(e.target) && e.target !== panelBtn) panel.hidden = true; });
    applyWidgets();
  }

  /* ---------- Charts (Chart.js) ---------- */
  var charts = [];
  function cssVar(name) { return getComputedStyle(document.documentElement).getPropertyValue(name).trim(); }
  function renderCharts() {
    if (typeof Chart === 'undefined') return;
    charts.forEach(function (c) { c.destroy(); });
    charts = [];
    var palette = [1, 2, 3, 4, 5, 6].map(function (i) { return cssVar('--chart-' + i); });
    var text = cssVar('--text-2'), grid = cssVar('--chart-grid'), surface = cssVar('--surface');
    Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
    Chart.defaults.color = text;
    $$('canvas[data-chart]').forEach(function (canvas) {
      if (canvas.closest('[hidden]')) return;
      var cfg = JSON.parse(document.getElementById(canvas.getAttribute('data-chart')).textContent);
      var type = cfg.type;
      var isRound = type === 'doughnut';
      var datasets = cfg.datasets.map(function (d, i) {
        var color = d.colors ? d.colors.map(function (c) { return palette[c]; }) : palette[d.color != null ? d.color : i];
        return {
          label: d.label, data: d.data,
          backgroundColor: isRound ? color : (type === 'line' ? hexA(palette[d.color || 0], .12) : color),
          borderColor: isRound ? surface : (type === 'line' ? palette[d.color || 0] : color),
          borderWidth: isRound ? 2 : (type === 'line' ? 2.5 : 0),
          borderRadius: type === 'bar' ? 6 : 0,
          maxBarThickness: 38,
          fill: type === 'line', tension: .35, pointRadius: type === 'line' ? 3 : 0,
          pointBackgroundColor: palette[d.color || 0]
        };
      });
      charts.push(new Chart(canvas, {
        type: type,
        data: { labels: cfg.labels, datasets: datasets },
        options: {
          responsive: true, maintainAspectRatio: false,
          indexAxis: cfg.horizontal ? 'y' : 'x',
          cutout: isRound ? '68%' : undefined,
          plugins: {
            legend: { display: isRound || datasets.length > 1, position: isRound ? 'right' : 'top', labels: { usePointStyle: true, boxWidth: 8, padding: 14 } },
            tooltip: { backgroundColor: cssVar('--navy') || '#10243A', padding: 10, cornerRadius: 8 }
          },
          scales: isRound ? {} : {
            x: { grid: { display: !!cfg.horizontal, color: grid }, border: { display: false }, stacked: !!cfg.stacked },
            y: { grid: { display: !cfg.horizontal, color: grid }, border: { display: false }, beginAtZero: true, stacked: !!cfg.stacked, ticks: { precision: 0 } }
          }
        }
      }));
    });
  }
  function hexA(hex, a) {
    var m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex || '');
    return m ? 'rgba(' + parseInt(m[1], 16) + ',' + parseInt(m[2], 16) + ',' + parseInt(m[3], 16) + ',' + a + ')' : hex;
  }
  window.addEventListener('load', renderCharts);

  /* ---------- Print ---------- */
  $$('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });

  /* ---------- Real-time notification count (polling) ---------- */
  var dot = $('[data-unread-count]');
  if (dot) {
    setInterval(function () {
      if (document.hidden) return;
      fetch('api_notifications.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d) return;
          dot.textContent = d.unread;
          dot.hidden = d.unread === 0;
        }).catch(function () {});
    }, 60000);
  }
})();

/* ---------- Programme form: show audience / repeat fields as needed ---------- */
(function () {
  var aud = document.querySelector('[data-audience]');
  if (aud) {
    var sync = function () {
      document.querySelectorAll('[data-aud-show]').forEach(function (el) {
        var show = el.getAttribute('data-aud-show').split(' ').indexOf(aud.value) !== -1;
        el.hidden = !show;
        el.querySelectorAll('select').forEach(function (s) { s.disabled = !show; });
      });
      document.querySelectorAll('[data-aud-only]').forEach(function (o) {
        o.hidden = o.disabled = o.getAttribute('data-aud-only') !== aud.value;
      });
    };
    aud.addEventListener('change', sync);
    sync();
  }
  var rep = document.querySelector('[data-repeat]');
  var until = document.querySelector('[data-repeat-until]');
  if (rep && until) {
    var s2 = function () { until.hidden = rep.value === 'none'; };
    rep.addEventListener('change', s2);
    s2();
  }
})();
