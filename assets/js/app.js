/* Construction Store — shared front-end behaviour (no dependencies) */
(function () {
  'use strict';

  /* --------------------------------------------------- confirm destructive */
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (f.dataset && f.dataset.confirm) {
      if (!window.confirm(f.dataset.confirm)) { ev.preventDefault(); }
    }
  });

  /* ------------------------------------------------------------ auto print */
  document.querySelectorAll('[data-autoprint]').forEach(function (el) {
    window.addEventListener('load', function () { window.print(); });
  });

  /* ----------------------------------------------- movement type switching */
  var typeSel = document.getElementById('movement_type');
  function syncMovementForm() {
    if (!typeSel) return;
    var t = typeSel.value;
    var rows = {
      projectRow: t === 'IN' || t === 'OUT' || t === 'ADJUST',
      supplierRow: t === 'IN',
      toProjectRow: t === 'TRANSFER_IN',
      fromProjectRow: t === 'TRANSFER_OUT',
      refRow: t !== 'TRANSFER_IN' && t !== 'TRANSFER_OUT'
    };
    Object.keys(rows).forEach(function (id) {
      var el = document.querySelectorAll('[data-row="' + id + '"]');
      el.forEach(function (n) { n.style.display = rows[id] ? '' : 'none'; });
    });
    var lbl = document.getElementById('target-label');
    if (lbl) {
      var names = {
        'IN': 'Receive into (destination store)', 'OUT': 'Issue from (source store)',
        'ADJUST': 'Adjust at (store / project)', 'TRANSFER_IN': '', 'TRANSFER_OUT': ''
      };
      lbl.textContent = names[t] || 'Store location';
    }
  }
  if (typeSel) {
    typeSel.addEventListener('change', syncMovementForm);
    syncMovementForm();
  }

  /* ------------------------------------------------- item picker for forms */
  var lookupInput = document.getElementById('item_search');
  var lookupBox = document.getElementById('lookup_results');
  var hiddenItem = document.getElementById('item_id');
  var infoBox = document.getElementById('item_info');

  if (lookupInput && lookupBox) {
    var timer = null;
    var base = lookupInput.dataset.endpoint || 'item_lookup.php';

    function render(data) {
      data = (data && data.items) || data || [];   // item_lookup.php returns {items:[...], powered_by:"..."}
      lookupBox.innerHTML = '';
      if (!data.length) {
        lookupBox.innerHTML = '<div class="lookup-item text-muted">No matching items</div>';
      }
      data.forEach(function (it) {
        var d = document.createElement('div');
        d.className = 'lookup-item';
        d.innerHTML = '<span class="code">' + it.item_code + '</span> — ' + it.item_name +
          '<br><small class="text-muted">On hand: ' + it.quantity + ' ' + it.uom +
          ' | ' + it.uom + ' | cost ' + it.unit_cost + '</small>';
        d.addEventListener('click', function () { pick(it); });
        lookupBox.appendChild(d);
      });
      lookupBox.classList.add('show');
    }

    function pick(it) {
      lookupInput.value = it.item_code + ' — ' + it.item_name;
      lookupBox.classList.remove('show');
      if (hiddenItem) { hiddenItem.value = it.id; }
      if (infoBox) {
        infoBox.innerHTML = '<strong>' + it.item_code + '</strong> — ' + it.item_name +
          ' <span class="badge bg-secondary">' + it.uom + '</span> ' +
          '<span class="badge bg-info text-dark">On hand: ' + it.quantity + '</span> ' +
          '<span class="text-muted">Unit cost ' + it.unit_cost + '</span>';
        infoBox.dataset.uom = it.uom;
        infoBox.dataset.cost = it.unit_cost;
        infoBox.dataset.qty = it.quantity;
        infoBox.dataset.project = it.project_id;
        infoBox.classList.remove('d-none');
      }
      document.dispatchEvent(new CustomEvent('item:selected', { detail: it }));
    }

    lookupInput.addEventListener('input', function () {
      var v = lookupInput.value.trim();
      if (hiddenItem) hiddenItem.value = '';
      clearTimeout(timer);
      if (v.length < 2) { lookupBox.classList.remove('show'); return; }
      timer = setTimeout(function () {
        fetch(base + '?term=' + encodeURIComponent(v))
          .then(function (r) { return r.json(); })
          .then(render)
          .catch(function () { lookupBox.classList.remove('show'); });
      }, 220);
    });

    document.addEventListener('click', function (ev) {
      if (!lookupBox.contains(ev.target) && ev.target !== lookupInput) {
        lookupBox.classList.remove('show');
      }
    });
  }

  /* ------------------------------------------------ quantity x cost total */
  function refreshTotals() {
    var qty = parseFloat((document.getElementById('quantity') || {}).value || 0);
    var cost = parseFloat((document.getElementById('unit_cost') || {}).value || 0);
    var out = document.getElementById('line_total');
    if (out) { out.textContent = (qty * cost).toFixed(2); }
  }
  ['quantity', 'unit_cost'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', refreshTotals);
  });
  refreshTotals();

  /* ------------------------------------ stock availability hint (item+loc) */
  function refreshAvailability() {
    var box = document.getElementById('availability');
    if (!box) return;
    var itemId = (document.getElementById('item_id') || {}).value;
    var locEl = document.getElementById('project_id') || document.getElementById('from_project_id');
    if (!itemId) { box.className = 'alert alert-light border small mb-0'; box.textContent = 'Select an item to see available stock.'; return; }
    fetch('stock_lookup.php?item_id=' + encodeURIComponent(itemId) +
          '&project_id=' + encodeURIComponent(locEl ? locEl.value : 0))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.error) { box.className = 'alert alert-warning small mb-0'; box.textContent = d.error; return; }
        var cls = d.available <= 0 ? 'alert-danger' : (d.available <= d.reorder_level ? 'alert-warning' : 'alert-success');
        box.className = 'alert ' + cls + ' small mb-0';
        box.innerHTML = 'Available at <strong>' + d.location + '</strong>: <strong>' + d.available + ' ' + d.uom +
          '</strong> &nbsp;|&nbsp; Total on hand: ' + d.total + ' ' + d.uom +
          ' &nbsp;|&nbsp; Reorder level: ' + d.reorder_level;
      })
      .catch(function () {});
  }
  document.addEventListener('item:selected', refreshAvailability);
  ['project_id', 'from_project_id'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('change', refreshAvailability);
  });
  refreshAvailability();

  /* ------------------------------------------------- delivery note builder */
  var dnTable = document.getElementById('dn_lines');
  if (dnTable) {
    var tbody = dnTable.querySelector('tbody');
    var addBtn = document.getElementById('add_line');
    var search = document.getElementById('dn_item_search');
    var results = document.getElementById('dn_lookup_results');
    var t = null;

    function renumber() {
      Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function (tr, i) {
        tr.querySelector('.line-no').textContent = i + 1;
      });
      var total = 0;
      Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function (tr) {
        total += parseFloat(tr.querySelector('input[name="qty[]"]').value || 0);
      });
      var el = document.getElementById('dn_total_qty');
      if (el) el.textContent = total.toFixed(3);
      var cnt = document.getElementById('dn_total_lines');
      if (cnt) cnt.textContent = tbody.querySelectorAll('tr').length;
    }

    function addLine(it) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td class="line-no"></td>' +
        '<td><span class="mono small">' + it.item_code + '</span><div class="small text-muted">' + it.item_name + '</div>' +
        '<input type="hidden" name="item_id[]" value="' + it.id + '">' +
        '<input type="hidden" name="uom[]" value="' + it.uom + '"></td>' +
        '<td class="text-nowrap">' + it.uom + '</td>' +
        '<td><input type="number" step="0.001" min="0.001" name="qty[]" class="form-control form-control-sm qty-in" value="1" required></td>' +
        '<td><input type="text" name="remark[]" class="form-control form-control-sm" placeholder="Remarks"></td>' +
        '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger remove-line"><i class="bi bi-x-lg"></i></button></td>';
      tbody.appendChild(tr);
      tr.querySelector('input[name="qty[]"]').addEventListener('input', renumber);
      tr.querySelector('.remove-line').addEventListener('click', function () { tr.remove(); renumber(); });
      renumber();
    }

    if (search) {
      search.addEventListener('input', function () {
        var v = search.value.trim();
        clearTimeout(t);
        if (v.length < 2) { results.classList.remove('show'); return; }
        t = setTimeout(function () {
          fetch('item_lookup.php?term=' + encodeURIComponent(v))
            .then(function (r) { return r.json(); })
            .then(function (data) {
              data = (data && data.items) || data || [];
              results.innerHTML = '';
              if (!data.length) results.innerHTML = '<div class="lookup-item text-muted">No matching items</div>';
              data.forEach(function (it) {
                var d = document.createElement('div');
                d.className = 'lookup-item';
                d.innerHTML = '<span class="code">' + it.item_code + '</span> — ' + it.item_name +
                  ' <small class="text-muted">(' + it.quantity + ' ' + it.uom + ' on hand)</small>';
                d.addEventListener('click', function () {
                  addLine(it); search.value = ''; results.classList.remove('show'); search.focus();
                });
                results.appendChild(d);
              });
              results.classList.add('show');
            });        }, 220);
      });
      document.addEventListener('click', function (e) {
        if (!results.contains(e.target) && e.target !== search) results.classList.remove('show');
      });
    }
    if (addBtn) addBtn.addEventListener('click', function () { search && search.focus(); });
    renumber();
  }

  /* ---------------------------------------------- report filter auto-submit */
  document.querySelectorAll('select[data-autosubmit]').forEach(function (s) {
    s.addEventListener('change', function () { s.form.submit(); });
  });

  /* ---------------------------------------------------------- export menu */
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var el = document.querySelector(b.dataset.copy);
      if (!el) return;
      navigator.clipboard.writeText(el.innerText).then(function () {
        b.innerHTML = '<i class="bi bi-check2"></i> Copied';
        setTimeout(function () { b.innerHTML = '<i class="bi bi-clipboard"></i> Copy'; }, 1600);
      });
    });
  });
})();
