(function () {
  var d = document, root = d.documentElement;
  function $(s, c) { return (c || d).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || d).querySelectorAll(s)); }

  /* theme */
  d.addEventListener('click', function (e) {
    if (e.target.closest('[data-theme-toggle]')) {
      var t = root.classList.toggle('dark') ? 'dark' : 'light';
      try { localStorage.setItem('ecoconnect_theme', t); } catch (x) {}
    }
  });

  /* mobile drawer */
  var drawer = $('#drawer'), bg = $('#drawerBg');
  function drawerSet(open) {
    if (!drawer) return;
    drawer.classList.toggle('hidden', !open); drawer.classList.toggle('flex', open);
    if (bg) bg.classList.toggle('hidden', !open);
  }
  d.addEventListener('click', function (e) {
    if (e.target.closest('[data-drawer-open]')) drawerSet(true);
    else if (e.target.closest('[data-drawer-close]') || e.target === bg) drawerSet(false);
  });

  /* toasts */
  $$('[data-toast]').forEach(function (t) { setTimeout(function () { t.remove(); }, 4500); });
  d.addEventListener('click', function (e) { var b = e.target.closest('[data-toast-close]'); if (b) b.closest('[data-toast]').remove(); });

  /* modals */
  function closeModal(m) {
    if (m.dataset.closeHref) location.href = m.dataset.closeHref; else m.classList.add('hidden'), m.classList.remove('flex');
  }
  d.addEventListener('click', function (e) {
    var o = e.target.closest('[data-modal-open]');
    if (o) { var m = d.getElementById(o.dataset.modalOpen); if (m) { m.classList.remove('hidden'); m.classList.add('flex'); } return; }
    var c = e.target.closest('[data-modal-close]');
    if (c) { var mm = c.closest('[data-modal]'); if (mm) closeModal(mm); return; }
    if (e.target.hasAttribute && e.target.hasAttribute('data-modal')) closeModal(e.target);
  });
  d.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var ms = $$('[data-modal]').filter(function (m) { return !m.classList.contains('hidden'); });
    if (ms.length) closeModal(ms[ms.length - 1]);
  });

  /* privacy "accept" ticks the consent box */
  d.addEventListener('click', function (e) {
    if (e.target.closest('[data-accept-privacy]')) { var c = $('#consent'); if (c) { c.checked = true; c.dispatchEvent(new Event('change', { bubbles: true })); } }
  });

  /* show / hide password */
  d.addEventListener('click', function (e) {
    var b = e.target.closest('[data-toggle-pw]'); if (!b) return;
    var i = d.getElementById(b.dataset.togglePw), show = i.type === 'password';
    i.type = show ? 'text' : 'password';
    $('[data-eye]', b).classList.toggle('hidden', show); $('[data-eye-off]', b).classList.toggle('hidden', !show);
    b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  });

  /* confirm dialog (same look as the React ConfirmDialog) */
  function confirmBox(opts, ok) {
    var w = d.createElement('div');
    w.className = 'fixed inset-0 z-[2500] flex items-center justify-center bg-slate-900/40 p-4'; w.setAttribute('role', 'alertdialog');
    w.innerHTML = '<div class="w-full max-w-sm rounded-xl border border-slate-200 bg-white p-6 shadow-xl"><h2 class="text-lg font-bold tracking-tight text-slate-900"></h2><p class="mt-2 text-sm leading-relaxed text-slate-600"></p>'
      + '<div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><button type="button" data-no class="eco-btn eco-btn-outline"></button><button type="button" data-yes class="eco-btn"></button></div></div>';
    $('h2', w).textContent = opts.title; $('p', w).textContent = opts.text || ''; if (!opts.text) $('p', w).remove();
    $('[data-no]', w).textContent = opts.cancel || 'Cancel';
    var y = $('[data-yes]', w); y.textContent = opts.label || 'Confirm';
    y.className += opts.danger ? ' bg-red-600 text-white hover:bg-red-700 focus-visible:ring-red-500/25' : ' eco-btn-green';
    function close() { w.remove(); d.removeEventListener('keydown', esc, true); }
    function esc(e) { if (e.key === 'Escape') { e.stopImmediatePropagation(); close(); } }
    d.addEventListener('keydown', esc, true);
    w.addEventListener('mousedown', function (e) { if (e.target === w) close(); });
    $('[data-no]', w).onclick = close; y.onclick = function () { close(); ok(); };
    d.body.appendChild(w); y.focus();
  }
  function opts(el) { return { title: el.dataset.confirmTitle || 'Are you sure?', text: el.dataset.confirmText || '', label: el.dataset.confirmLabel, cancel: el.dataset.confirmCancel, danger: !!el.dataset.confirmDanger }; }
  d.addEventListener('submit', function (e) {
    var f = e.target; if (!f.dataset || !f.dataset.confirmTitle || f.__ok) return;
    e.preventDefault(); confirmBox(opts(f), function () { f.__ok = true; f.requestSubmit ? f.requestSubmit() : f.submit(); });
  });
  d.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-confirm-title]'); if (!a) return;
    e.preventDefault(); confirmBox(opts(a), function () { location.href = a.href; });
  });

  /* password strength (sign-up) */
  var pw = $('[data-strength-for]');
  if (pw) {
    var src = d.getElementById(pw.dataset.strengthFor), bars = $$('[data-bar]', pw), label = $('[data-strength-label]', pw);
    var L = [['Too weak', 'bg-red-500', 'text-red-600'], ['Weak', 'bg-red-500', 'text-red-600'], ['Fair', 'bg-amber-500', 'text-amber-600'], ['Good', 'bg-brand-500', 'text-brand-700'], ['Strong', 'bg-brand-700', 'text-brand-800']];
    src.addEventListener('input', function () {
      var p = src.value, s = 0;
      if (p.length >= 8) s++; if (p.length >= 12) s++; if (/[a-z]/.test(p) && /[A-Z]/.test(p)) s++; if (/\d/.test(p)) s++; if (/[^A-Za-z0-9]/.test(p)) s++; s = Math.min(s, 4);
      pw.classList.toggle('hidden', !p); var lv = L[s];
      bars.forEach(function (b, i) { b.className = 'h-1 flex-1 rounded-full ' + (i < s ? lv[1] : 'bg-slate-200'); });
      label.className = 'mt-1 text-xs font-medium ' + lv[2]; label.textContent = 'Password strength: ' + lv[0];
    });
  }

  /* file picker preview + consent gating (sign-up step 3) */
  var file = $('[data-id-file]'), consent = $('#consent'), submit = $('[data-needs-consent]');
  function gate() { if (submit) submit.disabled = !(consent && consent.checked && file && file.files && file.files.length); }
  if (file) file.addEventListener('change', function () {
    var f = file.files[0], box = $('[data-id-preview]'), pick = $('[data-id-picker]');
    if (f) { var u = URL.createObjectURL(f); $('img', box).src = u; $('[data-id-name]', box).textContent = f.name; $('[data-id-size]', box).textContent = (f.size / 1048576).toFixed(1) + ' MB'; box.classList.remove('hidden'); pick.classList.add('hidden'); }
    gate();
  });
  var clr = $('[data-id-clear]'); if (clr) clr.addEventListener('click', function () { file.value = ''; $('[data-id-preview]').classList.add('hidden'); $('[data-id-picker]').classList.remove('hidden'); gate(); });
  if (consent) consent.addEventListener('change', gate); gate();

  /* live client-side filter: <input data-filter="#list"> hides [data-item] rows whose text does not match */
  $$('[data-filter]').forEach(function (inp) {
    inp.addEventListener('input', function () {
      var q = inp.value.trim().toLowerCase();
      $$('[data-item]', $(inp.dataset.filter)).forEach(function (r) { r.classList.toggle('hidden', q && r.textContent.toLowerCase().indexOf(q) < 0); });
    });
  });

  /* QR codes: <canvas data-qr="TEXT"> (uses the qrcode library when it loaded) */
  function qr() { $$('[data-qr]').forEach(function (c) { if (window.QRCode) window.QRCode.toCanvas(c, c.dataset.qr, { width: 256, margin: 2 }, function () {}); }); }
  if ($('[data-qr]')) { var s = d.createElement('script'); s.src = 'qrcode.min.js'; s.onload = qr; d.head.appendChild(s); }

  /* map pin picker for the event form */
  var pick = $('[data-map-pick]');
  if (pick) {
    var css = d.createElement('link'); css.rel = 'stylesheet'; css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'; d.head.appendChild(css);
    var js = d.createElement('script'); js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    js.onload = function () {
      var inp = d.getElementById(pick.dataset.mapPick), lab = $('[data-map-label]'), submitBtn = $('[data-needs-pin]');
      var m = /^\s*(-?\d+\.?\d*)\s*,\s*(-?\d+\.?\d*)\s*$/.exec(inp.value), start = m ? [+m[1], +m[2]] : [9.7407, 118.7353];
      var map = L.map(pick).setView(start, m ? 15 : 13), mk = null;
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>' }).addTo(map);
      var icon = L.icon({ iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png', shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png', iconSize: [25, 41], iconAnchor: [12, 41] });
      function set(ll) { inp.value = ll.lat.toFixed(6) + ',' + ll.lng.toFixed(6); lab.textContent = ll.lat.toFixed(5) + ', ' + ll.lng.toFixed(5); if (mk) mk.setLatLng(ll); else mk = L.marker(ll, { icon: icon }).addTo(map); if (submitBtn) submitBtn.disabled = false; }
      if (m) set({ lat: start[0], lng: start[1] });
      map.on('click', function (e) { set(e.latlng); });
    };
    d.head.appendChild(js);
  }
  var evMap = $('[data-event-map]');
  if (evMap) {
    var c2 = d.createElement('link'); c2.rel = 'stylesheet'; c2.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'; d.head.appendChild(c2);
    var j2 = d.createElement('script'); j2.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    j2.onload = function () {
      var p = evMap.dataset.eventMap.split(',').map(Number), map = L.map(evMap, { scrollWheelZoom: false }).setView(p, 15);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>' }).addTo(map);
      L.marker(p, { icon: L.icon({ iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png', shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png', iconSize: [25, 41], iconAnchor: [12, 41] }) }).addTo(map).bindPopup(evMap.dataset.title || '');
    };
    d.head.appendChild(j2);
  }

  /* quantity stepper in the redeem popup */
  var rf = $('#redeemForm');
  if (rf) {
    var qi = $('input[name=quantity]', rf), cost = +rf.dataset.cost, av = +rf.dataset.avail;
    function upd() { var n = +qi.value; $('[data-total]', rf).textContent = cost * n; $('[data-total2]', rf).textContent = cost * n; $('[data-after]', rf).textContent = av - cost * n; }
    $$('[data-qty]', rf).forEach(function (b) { b.addEventListener('click', function () { var n = +qi.value + (+b.dataset.qty); qi.value = Math.max(+qi.min, Math.min(+qi.max, n)); upd(); }); });
  }
  /* submit a form as soon as its file is chosen */
  $$('[data-autosubmit-input]').forEach(function (i) { i.addEventListener('change', function () { i.form.submit(); }); });
})();
