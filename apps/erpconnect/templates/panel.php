<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Liaison ERP — Document <?= (int) $documentId ?></title>
<!-- Chemin relatif : le panneau doit fonctionner quel que soit host:port servi (dev 8770, harness, prod). -->
<link rel="stylesheet" href="../../css/design-system.css">
<style>
  body { font-family: system-ui, sans-serif; background: var(--bg, #f5f5f5); color: var(--ink, #1a1a1a); margin: 0; padding: 1.5rem; }
  .erp-panel { max-width: 960px; margin: 0 auto; }
  .ds-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e5e5); border-radius: 8px; padding: 1rem; margin-bottom: 1rem; }
  .erp-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
  .erp-table th { text-align: left; padding: .5rem .75rem; background: var(--surface-alt, #f9f9f9); font-weight: 600; border-bottom: 2px solid var(--border, #e5e5e5); }
  .erp-table td { padding: .5rem .75rem; border-bottom: 1px solid var(--border, #e5e5e5); vertical-align: top; }
  .erp-table tr:last-child td { border-bottom: none; }
  .ds-chip--accent   { background: var(--accent-muted, #e8f0fe); color: var(--accent, #1a56db); padding: 2px 8px; border-radius: 999px; font-size: .75rem; font-weight: 500; }
  .ds-chip--neutral  { background: var(--muted, #f0f0f0); color: var(--dim, #555); padding: 2px 8px; border-radius: 999px; font-size: .75rem; font-weight: 500; }
  .ds-chip--amber    { background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 999px; font-size: .75rem; font-weight: 500; }
  .ds-chip--red      { background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 999px; font-size: .75rem; font-weight: 500; }
  .ds-chip--green    { background: #d1fae5; color: #065f46; padding: 2px 8px; border-radius: 999px; font-size: .75rem; font-weight: 500; }
  .btn-primary { background: var(--primary, #1a56db); color: #fff; border: none; padding: .5rem 1.25rem; border-radius: 6px; cursor: pointer; font-size: .875rem; font-weight: 500; }
  .btn-primary:disabled { opacity: .5; cursor: not-allowed; }
  .btn-secondary { background: transparent; color: var(--ink, #1a1a1a); border: 1px solid var(--border, #e5e5e5); padding: .5rem 1rem; border-radius: 6px; cursor: pointer; font-size: .875rem; }
  .btn-danger { background: #b91c1c; color: #fff; border: none; padding: .45rem 1rem; border-radius: 6px; cursor: pointer; font-size: .8rem; }
  .erp-alert { padding: .75rem 1rem; border-radius: 6px; margin-bottom: 1rem; }
  .erp-alert--warning { background: #fef3c7; border: 1px solid #fcd34d; color: #78350f; }
  .erp-alert--info    { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
  .erp-alert--success { background: #d1fae5; border: 1px solid #6ee7b7; color: #065f46; }
  .erp-alert--error   { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; }
  select.erp-select, input.erp-input { font-size: .8rem; border: 1px solid var(--border, #e5e5e5); border-radius: 4px; padding: 3px 6px; background: var(--surface, #fff); }
  .erp-alloc-row { display: flex; gap: .4rem; align-items: center; margin-bottom: .3rem; }
  .erp-alloc-qty { width: 64px; text-align: right; }
  .erp-alloc-type { min-width: 150px; }
  .erp-alloc-del { border: none; background: transparent; color: #b91c1c; cursor: pointer; font-size: 1rem; line-height: 1; padding: 0 .3rem; }
  .erp-alloc-sum { font-size: .75rem; font-weight: 600; }
  .erp-alloc-sum--ok { color: #065f46; }
  .erp-alloc-sum--bad { color: #991b1b; }
  #erp-loading { text-align: center; padding: 2rem; color: var(--dim, #555); }
  #erp-post-intro { display: none; }
</style>
</head>
<body>
<div class="erp-panel">

  <h2 style="font-size:1.125rem;font-weight:600;margin-bottom:1rem;">
    Liaison ERP K-Time &mdash; Document #<?= (int) $documentId ?>
  </h2>

  <div id="erp-loading">Chargement de la proposition&hellip;</div>
  <div id="erp-content" style="display:none;">

    <!-- Bloc fournisseur -->
    <div class="ds-card" id="erp-supplier-status">
      <div style="font-weight:600;margin-bottom:.5rem;">Fournisseur</div>
      <div id="erp-supplier-detail"></div>
    </div>

    <!-- Bloc facture (existe déjà ?) -->
    <div class="ds-card" id="erp-invoice-exists" style="display:none;">
      <div style="font-weight:600;margin-bottom:.5rem;">Déduplication facture</div>
      <div id="erp-invoice-exists-detail"></div>
    </div>

    <!-- Tableau lignes + ventilation fractionnée -->
    <div class="ds-card">
      <div style="font-weight:600;margin-bottom:.75rem;">Lignes de facture &mdash; ventilation</div>
      <div class="ds-table-wrap"><table class="erp-table ds-table" id="erp-lines-table">
        <thead>
          <tr>
            <th>Description</th>
            <th style="width:60px">Qté</th>
            <th style="width:90px">Prix unit.</th>
            <th style="width:130px">Ventilation</th>
            <th style="width:340px">Répartition (Σ = qté)</th>
          </tr>
        </thead>
        <tbody id="erp-lines-body">
          <tr><td colspan="5" style="color:var(--dim,#555);font-style:italic">Chargement&hellip;</td></tr>
        </tbody>
      </table></div>
      <p style="font-size:.75rem;color:var(--dim,#555);margin:.5rem 0 0;">
        Répartissez chaque ligne : stock, facture, fiche de travail, vente au comptant,
        reçu/contesté ou non attribué. La somme doit égaler la quantité de la ligne.
      </p>
    </div>

    <!-- Bouton introduction -->
    <div style="display:flex;gap:.75rem;align-items:center;margin-bottom:1rem;">
      <button id="erp-submit-btn" class="btn-primary" onclick="erpSubmit()">
        Introduire dans K-Time
      </button>
      <span id="erp-submit-status" style="font-size:.875rem;"></span>
    </div>

    <!-- Zone post-introduction : statut + blocage + refresh -->
    <div id="erp-post-intro">
      <!-- Bandeau statut validation -->
      <div id="erp-validation-status" class="erp-alert erp-alert--info">
        <div id="erp-validation-detail"></div>
        <button id="erp-refresh-btn" class="btn-secondary" onclick="erpRefresh()" style="margin-top:.5rem;">
          Actualiser le statut
        </button>
      </div>

      <!-- Demande de blocage avec cause (K-Time exécute le cycle) -->
      <div class="ds-card" id="erp-block-card">
        <div style="font-weight:600;margin-bottom:.5rem;">Bloquer la facture (avec cause)</div>
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
          <select id="erp-block-kind" class="erp-select">
            <option value="note_credit">Demande de note de crédit</option>
            <option value="correction_facture">Demande de correction de facture</option>
            <option value="blocage_paiement">Blocage du paiement</option>
          </select>
          <input id="erp-block-cause" class="erp-input" type="text" placeholder="Cause (obligatoire)" style="flex:1;min-width:220px;">
          <button id="erp-block-btn" class="btn-danger" onclick="erpBlock()">Demander le blocage</button>
        </div>
        <span id="erp-block-status" style="font-size:.8rem;"></span>
      </div>
    </div>

  </div><!-- #erp-content -->
  <div id="erp-error" style="display:none;" class="erp-alert erp-alert--error"></div>

</div><!-- .erp-panel -->

<script>
(function () {
  const docId   = <?= (int) $documentId ?>;
  // Base = chemin de l'app dérivé de l'URL courante (« /kdocs ») — jamais d'origine
  // absolue : APP_URL peut pointer un autre host:port que celui qui sert la page.
  const base    = location.pathname.replace(/\/erpconnect\/panel\/\d+.*$/, '');
  let proposal  = null;

  // Libellés de ventilation dominante (badge par ligne)
  const ventLabels = {
    'facture'          : 'Facturé ici',
    'vente_comptant'   : 'Vente au comptant',
    'stock'            : 'Stock',
    'fiche_travail'    : 'Fiche de travail',
    'non_introduit'    : 'Non introduit',
  };
  const ventClass = {
    'facture'        : 'ds-chip--accent',
    'vente_comptant' : 'ds-chip--amber',
    'stock'          : 'ds-chip--neutral',
    'fiche_travail'  : 'ds-chip--neutral',
    'non_introduit'  : 'ds-chip--red',
  };
  // Types d'allocation (répartition fractionnée)
  const allocLabels = {
    'stock'          : 'Stock',
    'facture'        : 'Facture',
    'fiche_travail'  : 'Fiche de travail',
    'vente_comptant' : 'Vente au comptant',
    'recu_conteste'  : 'Reçu / contesté',
    'non_attribue'   : 'Non attribué',
  };
  const allocTypes = ['stock','facture','fiche_travail','vente_comptant','recu_conteste','non_attribue'];

  function showError(msg) {
    document.getElementById('erp-loading').style.display = 'none';
    document.getElementById('erp-content').style.display = 'none';
    const el = document.getElementById('erp-error');
    el.textContent = msg;
    el.style.display = 'block';
  }

  function renderSupplier(p) {
    const el = document.getElementById('erp-supplier-detail');
    if (!p.ktime_available) {
      el.innerHTML = '<span class="ds-chip--amber">K-Time indisponible</span>';
      return;
    }
    if (p.supplier && p.supplier.known) {
      const m = p.supplier.match || {};
      el.innerHTML =
        '<span class="ds-chip--accent">Fournisseur reconnu</span> ' +
        '<strong>' + esc(m.name || p.supplier_name || '') + '</strong>' +
        (m.confidence ? ' &mdash; confiance&nbsp;' + Math.round(m.confidence * 100) + '%' : '');
    } else {
      el.innerHTML =
        '<span class="ds-chip--neutral">Fournisseur inconnu</span> ' +
        (p.supplier_name ? '&mdash; ' + esc(p.supplier_name) : '(nom non disponible)') +
        ' <em style="font-size:.8rem;color:var(--dim,#555)">Non trouvé dans K-Time</em>';
    }
  }

  function renderInvoiceExists(p) {
    const card = document.getElementById('erp-invoice-exists');
    const det  = document.getElementById('erp-invoice-exists-detail');
    if (!p.invoice_exists) { card.style.display = 'none'; return; }
    card.style.display = 'block';
    const ie = p.invoice_exists;
    if (ie.exists) {
      const m = ie.match || {};
      det.innerHTML =
        '<div class="erp-alert erp-alert--warning" style="margin:0">' +
        '<strong>Cette facture existe déjà dans K-Time</strong>' +
        (m.id ? ' (ID&nbsp;' + m.id + ')' : '') +
        (m.status ? ' &mdash; statut&nbsp;<code>' + esc(m.status) + '</code>' : '') +
        (m.invoice_date ? ' &mdash; date&nbsp;' + esc(m.invoice_date) : '') +
        '</div>';
    } else {
      det.innerHTML = '<span class="ds-chip--accent">Nouvelle facture</span>';
    }
  }

  // Construit l'éditeur d'allocations d'une ligne (pré-rempli depuis line.allocations).
  function allocEditorHtml(line, lineId, qty) {
    const allocs = (line.allocations && line.allocations.length) ? line.allocations
                 : [{ type: 'non_attribue', qty: qty }];
    let rows = '';
    allocs.forEach(function (a) {
      rows += allocRowHtml(a.type || 'non_attribue', a.qty != null ? a.qty : 0);
    });
    return (
      '<div class="erp-alloc-editor" data-line-id="' + esc(String(lineId)) + '" data-qty="' + esc(String(qty)) + '">' +
        '<div class="erp-alloc-rows">' + rows + '</div>' +
        '<div style="display:flex;gap:.5rem;align-items:center;margin-top:.2rem;">' +
          '<button type="button" class="btn-secondary erp-alloc-add" style="padding:2px 8px;font-size:.75rem;">+ répartition</button>' +
          '<span class="erp-alloc-sum"></span>' +
        '</div>' +
      '</div>'
    );
  }

  function allocRowHtml(type, qty) {
    const opts = allocTypes.map(function (t) {
      return '<option value="' + t + '"' + (t === type ? ' selected' : '') + '>' + esc(allocLabels[t]) + '</option>';
    }).join('');
    return (
      '<div class="erp-alloc-row">' +
        '<select class="erp-select erp-alloc-type">' + opts + '</select>' +
        '<input class="erp-input erp-alloc-qty" type="number" min="0" step="0.001" value="' + esc(String(qty)) + '">' +
        '<button type="button" class="erp-alloc-del" title="Retirer">&times;</button>' +
      '</div>'
    );
  }

  function refreshSum(editor) {
    const qtyTarget = parseFloat(editor.getAttribute('data-qty')) || 0;
    let sum = 0;
    editor.querySelectorAll('.erp-alloc-qty').forEach(function (i) { sum += parseFloat(i.value) || 0; });
    const el = editor.querySelector('.erp-alloc-sum');
    const ok = Math.abs(sum - qtyTarget) < 0.001;
    el.textContent = 'Σ ' + round3(sum) + ' / ' + round3(qtyTarget);
    el.className = 'erp-alloc-sum ' + (ok ? 'erp-alloc-sum--ok' : 'erp-alloc-sum--bad');
  }

  function round3(n) { return Math.round(n * 1000) / 1000; }

  function wireEditor(editor) {
    editor.addEventListener('input', function () { refreshSum(editor); });
    editor.addEventListener('click', function (e) {
      if (e.target.classList.contains('erp-alloc-del')) {
        const rows = editor.querySelectorAll('.erp-alloc-row');
        if (rows.length > 1) { e.target.closest('.erp-alloc-row').remove(); refreshSum(editor); }
      } else if (e.target.classList.contains('erp-alloc-add')) {
        editor.querySelector('.erp-alloc-rows').insertAdjacentHTML('beforeend', allocRowHtml('non_attribue', 0));
        refreshSum(editor);
      }
    });
    refreshSum(editor);
  }

  function renderLines(p) {
    const tbody = document.getElementById('erp-lines-body');
    if (!p.lines || p.lines.length === 0) {
      tbody.innerHTML = '<tr><td colspan="5" style="color:var(--dim,#555);font-style:italic">Aucune ligne détectée</td></tr>';
      return;
    }
    tbody.innerHTML = '';
    p.lines.forEach(function (line, idx) {
      const vent   = line.ventilation || 'non_introduit';
      const vClass = ventClass[vent] || 'ds-chip--neutral';
      const vLabel = ventLabels[vent] || vent;
      const lineId = line.id || ('idx-' + idx);
      const qty    = (line.quantity !== null && line.quantity !== undefined) ? line.quantity : 0;

      const tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + esc(line.description || '') + '</td>' +
        '<td>' + (qty !== 0 ? qty : '—') + '</td>' +
        '<td>' + (line.unit_price !== null && line.unit_price !== undefined ? line.unit_price : '—') + '</td>' +
        '<td><span class="erp-line-ventilation ' + vClass + '">' + vLabel + '</span></td>' +
        '<td>' + allocEditorHtml(line, lineId, qty) + '</td>';
      tbody.appendChild(tr);
    });
    document.querySelectorAll('.erp-alloc-editor').forEach(wireEditor);
  }

  function renderProposal(p) {
    document.getElementById('erp-loading').style.display = 'none';
    document.getElementById('erp-content').style.display = 'block';
    renderSupplier(p);
    renderInvoiceExists(p);
    renderLines(p);
    if (!p.ktime_available) {
      document.getElementById('erp-submit-btn').disabled = true;
    }
  }

  // Chargement initial de la proposition
  fetch(base + '/erpconnect/api/proposal/' + docId, { headers: { 'Accept': 'application/json' } })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.error) { showError('Erreur : ' + data.error); return; }
      proposal = data;
      renderProposal(data);
    })
    .catch(function (e) { showError('Impossible de charger la proposition : ' + e.message); });

  // Collecte des allocations fractionnées par ligne
  function collectLineChoices() {
    const lineChoices = {};
    document.querySelectorAll('.erp-alloc-editor').forEach(function (ed) {
      const lid = ed.getAttribute('data-line-id');
      const allocs = [];
      ed.querySelectorAll('.erp-alloc-row').forEach(function (row) {
        const type = row.querySelector('.erp-alloc-type').value;
        const qty  = parseFloat(row.querySelector('.erp-alloc-qty').value);
        if (type && qty > 0) { allocs.push({ type: type, qty: qty }); }
      });
      if (lid) { lineChoices[lid] = { allocations: allocs }; }
    });
    return lineChoices;
  }

  // Introduction dans K-Time
  window.erpSubmit = function () {
    if (!proposal) { return; }
    const btn = document.getElementById('erp-submit-btn');
    const statusEl = document.getElementById('erp-submit-status');
    btn.disabled = true;
    statusEl.textContent = 'Introduction en cours…';

    const body = {
      supplier_id : proposal.supplier && proposal.supplier.match ? proposal.supplier.match.id : null,
      total_ht    : proposal.total_ttc || 0,
      currency    : 'CHF',
      lines       : collectLineChoices(),
    };

    fetch(base + '/erpconnect/api/submit/' + docId, {
      method  : 'POST',
      headers : { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body    : JSON.stringify(body),
    })
      .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
      .then(function (res) {
        if (res.status === 503 || (res.data && res.data.error)) {
          statusEl.textContent = res.data.error || 'K-Time indisponible';
          btn.disabled = false;
          return;
        }
        statusEl.textContent = '';
        document.getElementById('erp-post-intro').style.display = 'block';
        showValidationStatus(res.data);
      })
      .catch(function (e) {
        statusEl.textContent = 'Erreur : ' + e.message;
        btn.disabled = false;
      });
  };

  function showValidationStatus(resp) {
    const el  = document.getElementById('erp-validation-status');
    const det = document.getElementById('erp-validation-detail');
    el.style.display = 'block';

    const vStatus = resp.validation_status || 'pending';
    if (vStatus === 'validated') {
      el.className = 'erp-alert erp-alert--success';
      det.innerHTML =
        '<strong>Bon pour accord</strong>' +
        (resp.validated_by ? ' par <strong>' + esc(String(resp.validated_by.name || resp.validated_by)) + '</strong>' : '') +
        (resp.validated_at ? ' le ' + esc(String(resp.validated_at)) : '') +
        ' <span class="ds-chip--green">Validé</span>';
    } else if (vStatus === 'partially_validated') {
      el.className = 'erp-alert erp-alert--warning';
      const s = resp.allocations_summary || {};
      det.innerHTML =
        '<strong>Partiellement validée</strong> ' +
        '<span class="ds-chip--amber">Partiel</span>' +
        (s.confirmed != null ? ' &mdash; ' + s.confirmed + ' confirmée(s), ' + (s.pending || 0) + ' en attente' : '');
    } else if (vStatus === 'blocked') {
      el.className = 'erp-alert erp-alert--error';
      const b = resp.block || {};
      const kindLabel = {
        'note_credit'        : 'Demande de note de crédit',
        'correction_facture' : 'Demande de correction',
        'blocage_paiement'   : 'Blocage du paiement',
      }[b.kind || resp.block_kind] || 'Bloquée';
      det.innerHTML =
        '<strong>Facture bloquée</strong> <span class="ds-chip--red">Bloquée</span>' +
        ' &mdash; ' + esc(kindLabel) +
        ((b.cause || resp.block_cause) ? ' : ' + esc(String(b.cause || resp.block_cause)) : '');
    } else if (vStatus === 'rejected') {
      el.className = 'erp-alert erp-alert--error';
      det.innerHTML = '<strong>Facture invalidée</strong> par K-Time.';
    } else {
      el.className = 'erp-alert erp-alert--info';
      det.innerHTML =
        '<strong>En attente de validation</strong> dans K-Time' +
        (resp.id ? ' (ID&nbsp;' + resp.id + ')' : '') + '.';
    }
  }

  // Rafraîchir le statut
  window.erpRefresh = function () {
    const btn = document.getElementById('erp-refresh-btn');
    btn.disabled = true;
    fetch(base + '/erpconnect/api/refresh/' + docId, {
      method  : 'POST',
      headers : { 'Accept': 'application/json' },
    })
      .then(function (r) { return r.json(); })
      .then(function (d) { showValidationStatus(d); btn.disabled = false; })
      .catch(function (e) {
        document.getElementById('erp-validation-detail').textContent = 'Erreur : ' + e.message;
        btn.disabled = false;
      });
  };

  // Demande de blocage avec cause
  window.erpBlock = function () {
    const kind   = document.getElementById('erp-block-kind').value;
    const cause  = document.getElementById('erp-block-cause').value.trim();
    const status = document.getElementById('erp-block-status');
    if (!cause) { status.textContent = 'La cause est obligatoire.'; return; }
    const btn = document.getElementById('erp-block-btn');
    btn.disabled = true;
    status.textContent = 'Envoi…';
    fetch(base + '/erpconnect/api/block/' + docId, {
      method  : 'POST',
      headers : { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body    : JSON.stringify({ kind: kind, cause: cause }),
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        if (d.error) { status.textContent = d.error; return; }
        status.textContent = '';
        showValidationStatus(d);
      })
      .catch(function (e) { status.textContent = 'Erreur : ' + e.message; btn.disabled = false; });
  };

  function esc(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
})();
</script>
</body>
</html>
