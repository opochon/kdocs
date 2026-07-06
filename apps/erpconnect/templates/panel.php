<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Liaison ERP — Document <?= (int) $documentId ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8') ?>/css/design-system.css">
<style>
  body { font-family: system-ui, sans-serif; background: var(--bg, #f5f5f5); color: var(--ink, #1a1a1a); margin: 0; padding: 1.5rem; }
  .erp-panel { max-width: 900px; margin: 0 auto; }
  .ds-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e5e5); border-radius: 8px; padding: 1rem; margin-bottom: 1rem; }
  .erp-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
  .erp-table th { text-align: left; padding: .5rem .75rem; background: var(--surface-alt, #f9f9f9); font-weight: 600; border-bottom: 2px solid var(--border, #e5e5e5); }
  .erp-table td { padding: .5rem .75rem; border-bottom: 1px solid var(--border, #e5e5e5); vertical-align: middle; }
  .erp-table tr:last-child td { border-bottom: none; }
  .ds-chip--accent   { background: var(--accent-muted, #e8f0fe); color: var(--accent, #1a56db); padding: 2px 8px; border-radius: 999px; font-size: .75rem; font-weight: 500; }
  .ds-chip--neutral  { background: var(--muted, #f0f0f0); color: var(--dim, #555); padding: 2px 8px; border-radius: 999px; font-size: .75rem; font-weight: 500; }
  .ds-chip--amber    { background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 999px; font-size: .75rem; font-weight: 500; }
  .ds-chip--red      { background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 999px; font-size: .75rem; font-weight: 500; }
  .ds-chip--green    { background: #d1fae5; color: #065f46; padding: 2px 8px; border-radius: 999px; font-size: .75rem; font-weight: 500; }
  .btn-primary { background: var(--primary, #1a56db); color: #fff; border: none; padding: .5rem 1.25rem; border-radius: 6px; cursor: pointer; font-size: .875rem; font-weight: 500; }
  .btn-primary:disabled { opacity: .5; cursor: not-allowed; }
  .btn-secondary { background: transparent; color: var(--ink, #1a1a1a); border: 1px solid var(--border, #e5e5e5); padding: .5rem 1rem; border-radius: 6px; cursor: pointer; font-size: .875rem; }
  .erp-alert { padding: .75rem 1rem; border-radius: 6px; margin-bottom: 1rem; }
  .erp-alert--warning { background: #fef3c7; border: 1px solid #fcd34d; color: #78350f; }
  .erp-alert--info    { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
  .erp-alert--success { background: #d1fae5; border: 1px solid #6ee7b7; color: #065f46; }
  .erp-alert--error   { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; }
  select.erp-select { font-size: .8rem; border: 1px solid var(--border, #e5e5e5); border-radius: 4px; padding: 2px 6px; background: var(--surface, #fff); }
  #erp-loading { text-align: center; padding: 2rem; color: var(--dim, #555); }
  #erp-validation-status { display: none; }
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

    <!-- Tableau lignes -->
    <div class="ds-card">
      <div style="font-weight:600;margin-bottom:.75rem;">Lignes de facture</div>
      <table class="erp-table" id="erp-lines-table">
        <thead>
          <tr>
            <th>Description</th>
            <th style="width:70px">Qté</th>
            <th style="width:100px">Prix unit.</th>
            <th style="width:140px">Ventilation</th>
            <th style="width:200px">Action</th>
          </tr>
        </thead>
        <tbody id="erp-lines-body">
          <tr><td colspan="5" style="color:var(--dim,#555);font-style:italic">Chargement&hellip;</td></tr>
        </tbody>
      </table>
    </div>

    <!-- Bouton introduction -->
    <div style="display:flex;gap:.75rem;align-items:center;margin-bottom:1rem;">
      <button id="erp-submit-btn" class="btn-primary" onclick="erpSubmit()">
        Introduire dans K-Time
      </button>
      <span id="erp-submit-status" style="font-size:.875rem;"></span>
    </div>

    <!-- Bandeau statut validation (affiché après introduction) -->
    <div id="erp-validation-status" class="erp-alert erp-alert--info">
      <div id="erp-validation-detail"></div>
      <button id="erp-refresh-btn" class="btn-secondary" onclick="erpRefresh()" style="margin-top:.5rem;">
        Actualiser le statut
      </button>
    </div>

  </div><!-- #erp-content -->
  <div id="erp-error" style="display:none;" class="erp-alert erp-alert--error"></div>

</div><!-- .erp-panel -->

<script>
(function () {
  const docId   = <?= (int) $documentId ?>;
  const base    = <?= json_encode(rtrim($appUrl, '/'), JSON_UNESCAPED_SLASHES) ?>;
  let proposal  = null;

  // Libellés de ventilation
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
  const actionLabels = {
    'stock'           : 'Mettre en stock',
    'fiche_travail'   : 'Affecter à une fiche de travail',
    'article_recu'    : 'Article reçu',
  };

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

      let actionHtml = '';
      if (vent === 'non_introduit' && line.options && line.options.length > 0) {
        const opts = line.options.map(function (o) {
          return '<option value="' + esc(o) + '">' + esc(actionLabels[o] || o) + '</option>';
        }).join('');
        actionHtml =
          '<select class="erp-select erp-line-action" data-line-id="' + esc(String(lineId)) + '">' +
          '<option value="">— Choisir —</option>' + opts + '</select>';
      }

      const tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + esc(line.description || '') + '</td>' +
        '<td>' + (line.quantity !== null && line.quantity !== undefined ? line.quantity : '—') + '</td>' +
        '<td>' + (line.unit_price !== null && line.unit_price !== undefined ? line.unit_price : '—') + '</td>' +
        '<td><span class="erp-line-ventilation ' + vClass + '">' + vLabel + '</span></td>' +
        '<td>' + actionHtml + '</td>';
      tbody.appendChild(tr);
    });
  }

  function renderProposal(p) {
    document.getElementById('erp-loading').style.display = 'none';
    document.getElementById('erp-content').style.display = 'block';
    renderSupplier(p);
    renderInvoiceExists(p);
    renderLines(p);

    // Si K-Time non disponible, désactiver le bouton
    if (!p.ktime_available) {
      document.getElementById('erp-submit-btn').disabled = true;
    }
  }

  // Chargement initial de la proposition
  fetch(base + '/erpconnect/proposal/' + docId, { headers: { 'Accept': 'application/json' } })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.error) { showError('Erreur : ' + data.error); return; }
      proposal = data;
      renderProposal(data);
    })
    .catch(function (e) { showError('Impossible de charger la proposition : ' + e.message); });

  // Introduction dans K-Time
  window.erpSubmit = function () {
    if (!proposal) { return; }
    const btn = document.getElementById('erp-submit-btn');
    const statusEl = document.getElementById('erp-submit-status');
    btn.disabled = true;
    statusEl.textContent = 'Introduction en cours…';

    // Collecte des choix utilisateur
    const lineChoices = {};
    document.querySelectorAll('.erp-line-action').forEach(function (sel) {
      const lid = sel.getAttribute('data-line-id');
      if (lid && sel.value) { lineChoices[lid] = { action: sel.value }; }
    });

    const body = {
      supplier_id : proposal.supplier && proposal.supplier.match ? proposal.supplier.match.id : null,
      total_ht    : proposal.total_ttc || 0,
      currency    : 'CHF',
      lines       : lineChoices,
    };

    fetch(base + '/erpconnect/submit/' + docId, {
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
    } else if (vStatus === 'rejected') {
      el.className = 'erp-alert erp-alert--error';
      det.innerHTML = '<strong>Facture rejetée</strong> par K-Time.';
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
    fetch(base + '/erpconnect/refresh/' + docId, {
      method  : 'POST',
      headers : { 'Accept': 'application/json' },
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        showValidationStatus(d);
        btn.disabled = false;
      })
      .catch(function (e) {
        document.getElementById('erp-validation-detail').textContent = 'Erreur : ' + e.message;
        btn.disabled = false;
      });
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
