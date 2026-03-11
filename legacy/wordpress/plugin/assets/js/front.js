(function(){
  function normalizeDate(s){
    const m = String(s||'').match(/^(\d{4})-(\d{2})-(\d{2})/);
    if(!m) return null;
    return new Date(Number(m[1]), Number(m[2])-1, Number(m[3])).getTime();
  }
  function parseNumber(s){
    const t = String(s||'').replace(/[^0-9\-]/g,'');
    if(t===''||t==='-') return NaN;
    return Number(t);
  }
  function cellText(td){ return (td ? (td.textContent||'').trim() : ''); }

  // Asegura data-label en <td> para el modo "cards" en móviles.
  // Importante: NO sobreescribe si ya existe (evita labels vacíos en algunos Android
  // cuando el <thead> está oculto o el theme manipula el DOM).
  function ensureDataLabels(table){
    try{
      const headRow = table.tHead && table.tHead.rows && table.tHead.rows[0] ? table.tHead.rows[0] : null;
      if(!headRow) return;
      const headers = Array.from(headRow.cells).map(th=>cellText(th));
      if(!headers.length) return;
      const tbody = table.tBodies && table.tBodies[0] ? table.tBodies[0] : null;
      if(!tbody) return;

      Array.from(tbody.rows).forEach(tr=>{
        Array.from(tr.cells).forEach((td, idx)=>{
          // Si ya viene desde PHP, no tocar.
          const existing = (td.getAttribute('data-label')||'').trim();
          if(existing) return;
          const h = (headers[idx]||'').trim();
          if(!h) return;
          td.setAttribute('data-label', h);
        });
      });
    } catch(e){ /* no-op */ }
  }

  function enhanceTable(table){
    if(table.dataset.mlv2Enhanced==='1') return;
    table.dataset.mlv2Enhanced='1';

    // 1) data-label antes de cualquier otra mejora
    ensureDataLabels(table);

    const wrap = table.closest('.mlv2-table-wrap') || table.parentElement;
    if(!wrap) return;

    const tools = document.createElement('div');
    tools.className = 'mlv2-table-tools';
    tools.innerHTML = '<input type="search" class="mlv2-table-search" placeholder="Buscar..." aria-label="Buscar en tabla">';
    wrap.insertBefore(tools, table);

    const searchInput = tools.querySelector('input');
    const tbody = table.tBodies[0];
    const allRows = Array.from(tbody ? tbody.rows : []);

    const debounce = (fn, wait)=>{
      let t;
      return function(){
        const ctx = this, args = arguments;
        clearTimeout(t);
        t = setTimeout(()=>fn.apply(ctx, args), wait);
      };
    };

    function applyFilters(){
      const q = (searchInput.value||'').toLowerCase();
      const selects = Array.from(tools.querySelectorAll('select[data-col]'));
      allRows.forEach(tr=>{
        const txt = tr.textContent.toLowerCase();
        let ok = (!q || txt.includes(q));
        for(const sel of selects){
          if(!ok) break;
          const col = Number(sel.dataset.col);
          const val = sel.value;
          if(!val) continue;
          const td = tr.cells[col];
          const t = cellText(td);
          if(t !== val) ok = false;
        }
        tr.style.display = ok ? '' : 'none';
      });
    }
    searchInput.addEventListener('input', debounce(applyFilters, 120));

    const headers = Array.from(table.tHead ? table.tHead.rows[0].cells : []);
    const wanted = ['Cliente','Nombre Local','Comuna'];
    headers.forEach((th, idx)=>{
      const label = cellText(th);
      if(!wanted.includes(label)) return;
      const values = Array.from(new Set(allRows.map(r=>cellText(r.cells[idx])).filter(v=>v && v!=='—'))).sort((a,b)=>a.localeCompare(b));
      if(values.length < 2) return;
      const sel = document.createElement('select');
      sel.className = 'mlv2-table-filter';
      sel.dataset.col = String(idx);
      const opt0 = document.createElement('option');
      opt0.value = '';
      opt0.textContent = label + ': Todos';
      sel.appendChild(opt0);
      for(const v of values){
        const opt = document.createElement('option');
        opt.value = v;
        opt.textContent = v;
        sel.appendChild(opt);
      }
      tools.appendChild(sel);
      sel.addEventListener('change', applyFilters);
    });

    headers.forEach((th, idx)=>{
      th.style.cursor = 'pointer';
      th.title = 'Ordenar';
      th.addEventListener('click', ()=>{
        const dir = (th.dataset.sortDir === 'asc') ? 'desc' : 'asc';
        headers.forEach(h=>{ if(h!==th) delete h.dataset.sortDir; });
        th.dataset.sortDir = dir;

        const rows = Array.from(tbody.rows);
        const sample = rows.find(r=>r.cells[idx] && cellText(r.cells[idx])!=='') || rows[0];
        const sampleText = sample ? cellText(sample.cells[idx]) : '';
        const isDate = !!normalizeDate(sampleText);
        const num = parseNumber(sampleText);
        const isNum = !Number.isNaN(num) && /\d/.test(sampleText);

        rows.sort((a,b)=>{
          const A = cellText(a.cells[idx]);
          const B = cellText(b.cells[idx]);
          let cmp = 0;
          if(isDate){
            cmp = (normalizeDate(A)||0) - (normalizeDate(B)||0);
          } else if(isNum){
            cmp = (parseNumber(A)||0) - (parseNumber(B)||0);
          } else {
            cmp = A.localeCompare(B, undefined, {numeric:true, sensitivity:'base'});
          }
          return dir==='asc' ? cmp : -cmp;
        });
        for(const r of rows) tbody.appendChild(r);
        applyFilters();
      });
    });

    wrap.querySelectorAll('input.mlv2-retirado-check').forEach(chk=>{
      chk.addEventListener('change', async ()=>{
        const formId = chk.dataset.form;
        const form = formId ? document.getElementById(formId) : chk.closest('form');
        if(!form) return;

        let status = form.querySelector('.mlv2-inline-status');
        if(!status){
          status = document.createElement('span');
          status.className = 'mlv2-inline-status';
          status.style.marginLeft = '8px';
          status.style.fontSize = '12px';
          form.appendChild(status);
        }

        const prevChecked = chk.checked;
        chk.disabled = true;
        status.textContent = 'Guardando...';

        try{
          let ok = false;

          // Prefer AJAX endpoint (más robusto). Fallback: POST a la misma URL.
          const ajaxUrl = (window.MLV2 && window.MLV2.ajax_url) ? window.MLV2.ajax_url : (form.querySelector('input[name="mlv2_ajax_url"]') ? form.querySelector('input[name="mlv2_ajax_url"]').value : null);
          const ajaxNonce = (window.MLV2 && window.MLV2.nonce) ? window.MLV2.nonce : (form.querySelector('input[name="mlv2_ajax_nonce"]') ? form.querySelector('input[name="mlv2_ajax_nonce"]').value : null);

          if (ajaxUrl && ajaxNonce) {
            const fd2 = new FormData();
            fd2.append('action', 'mlv2_set_retirado');
            fd2.append('nonce', ajaxNonce);

            const movInput = form.querySelector('input[name="mov_id"]');
            if (movInput) fd2.append('mov_id', movInput.value);

            if (chk.checked) { fd2.append('retirado', '1'); }

            const res2 = await fetch(ajaxUrl, {method:'POST', body: fd2, credentials:'same-origin'});
            if (res2.ok) {
              const j = await res2.json().catch(()=>null);
              ok = !!(j && j.success);
              if (!ok && j && j.data && j.data.message) {
                status.textContent = j.data.message;
              }
            } else {
              const t = await res2.text().catch(()=>null);
              if (t) status.textContent = 'Error: ' + t.slice(0, 120);
            }
          } else {
            const fd = new FormData(form);
            if(!chk.checked){ fd.delete('retirado'); }
            const res = await fetch(window.location.href, {method:'POST', body: fd, credentials:'same-origin'});
            ok = res.ok; // Nota: en fallback no podemos verificar si se guardó realmente
          }

          if(ok){
            setTimeout(()=>{ status.textContent=''; }, 1200);
          } else {
            chk.checked = !prevChecked;
            if(!status.textContent) status.textContent = 'No se pudo guardar';
          }
        }
        catch(e){
          chk.checked = !prevChecked;
          status.textContent = 'Error';
        } finally {
          chk.disabled = false;
        }
      });
    });
  }

  function boot(){
    document.querySelectorAll('table.mlv2-table').forEach(enhanceTable);

    // Movimientos: botón "Cargar más" en móvil (AJAX)
    document.querySelectorAll('.mlv2-loadmore').forEach(wrap=>{
      if(wrap.dataset.mlv2LmInit==='1') return;
      wrap.dataset.mlv2LmInit='1';

      const btn = wrap.querySelector('.mlv2-loadmore-btn');
      const status = wrap.querySelector('.mlv2-loadmore-status');
      if(!btn) return;

      btn.addEventListener('click', async ()=>{
        const ajaxUrl = (window.MLV2 && window.MLV2.ajax_url) ? window.MLV2.ajax_url : null;
        const nonce = (window.MLV2 && window.MLV2.nonce) ? window.MLV2.nonce : null;
        if(!ajaxUrl || !nonce) return;

        const context = wrap.dataset.context || 'cliente';
        const perPage = parseInt(wrap.dataset.per_page || wrap.dataset.perPage || '15', 10) || 15;
        const currentPage = parseInt(wrap.dataset.page || '1', 10) || 1;
        const totalPages = parseInt(wrap.dataset.total_pages || wrap.dataset.totalPages || '1', 10) || 1;
        const nextPage = currentPage + 1;

        if(nextPage > totalPages) {
          wrap.style.display = 'none';
          return;
        }

        btn.disabled = true;
        if(status) status.textContent = 'Cargando...';

        try{
          const fd = new FormData();
          fd.append('action', 'mlv2_load_more_movimientos');
          fd.append('nonce', nonce);
          fd.append('context', context);
          fd.append('page', String(nextPage));
          fd.append('per_page', String(perPage));

          // payload extra
          if(context === 'cliente') {
            if(wrap.dataset.local_codigo) fd.append('local_codigo', wrap.dataset.local_codigo);
          } else {
            if(wrap.dataset.cliente_user_id) fd.append('cliente_user_id', wrap.dataset.cliente_user_id);
          }

          const res = await fetch(ajaxUrl, {method:'POST', body: fd, credentials:'same-origin'});
          const j = await res.json().catch(()=>null);
          if(!res.ok || !j || !j.success){
            if(status) status.textContent = (j && j.data && j.data.message) ? j.data.message : 'Error al cargar';
            btn.disabled = false;
            return;
          }

          const html = (j.data && j.data.html) ? String(j.data.html) : '';
          if(!html){
            wrap.style.display = 'none';
            return;
          }

          const card = wrap.closest('.mlv2-card');
          const table = card ? card.querySelector('table.mlv2-table') : null;
          const tbody = table && table.tBodies && table.tBodies[0] ? table.tBodies[0] : null;
          if(tbody){
            const tmp = document.createElement('tbody');
            tmp.innerHTML = html;
            const frag = document.createDocumentFragment();
            Array.from(tmp.children).forEach(tr=>frag.appendChild(tr));
            tbody.appendChild(frag);
            ensureDataLabels(table);
          }

          // actualizar estado
          wrap.dataset.page = String(nextPage);
          const hasMore = !!(j.data && j.data.has_more);
          if(!hasMore || nextPage >= totalPages){
            wrap.style.display = 'none';
          }
          if(status) status.textContent = '';
        } catch(e){
          if(status) status.textContent = 'Error';
        } finally {
          btn.disabled = false;
        }
      });
    });

    // Registro gasto: mostrar máximo según cliente seleccionado
    var sel = document.querySelector('select[name="cliente_user_id"]');
    var maxBox = document.getElementById('mlv2-saldo-max');
    if (sel && maxBox) {
      var updateMax = function(){
        var opt = sel.options[sel.selectedIndex];
        var saldo = opt ? parseInt(opt.getAttribute('data-saldo') || '0', 10) : 0;
        if (!isNaN(saldo) && saldo > 0) {
          maxBox.textContent = 'Máximo: $' + saldo.toLocaleString('es-CL');
        } else if (opt && opt.value) {
          maxBox.textContent = 'Máximo: $0';
        } else {
          maxBox.textContent = '';
        }
      };
      sel.addEventListener('change', updateMax);
      updateMax();
    }

    // Registro latas: mostrar nombre del archivo (mejor UX)
    var fileInput = document.getElementById('mlv2-evidencia');
    if (fileInput) {
      var nameBox = document.getElementById('mlv2-evidencia-name');
      var onChange = function(){
        if (!nameBox) return;
        var f = fileInput.files && fileInput.files[0] ? fileInput.files[0].name : '';
        nameBox.textContent = f ? ('Seleccionado: ' + f) : '';
      };
      fileInput.addEventListener('change', onChange);
      onChange();
    }
  }
  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();


// Alert close (convencional) + persistencia (batch)
(function(){
  function closest(el, sel){
    while(el && el !== document){
      if (el.matches && el.matches(sel)) return el;
      el = el.parentNode;
    }
    return null;
  }

  document.addEventListener('click', function(e){
    var btn = e.target;
    if (!btn) return;
    if (!btn.classList || !btn.classList.contains('mlv2-alert__close')) return;

    var alertEl = closest(btn, '.mlv2-alert');
    if (!alertEl) return;

    var id = alertEl.getAttribute('data-alert-id');
    // UX: cierre inmediato
    alertEl.style.opacity = '0';
    alertEl.style.transform = 'translateY(-4px)';
    setTimeout(function(){
      if (alertEl && alertEl.parentNode) alertEl.parentNode.removeChild(alertEl);
    }, 180);


    // Si la alerta viene por querystring, limpiamos la URL para que no reaparezca al recargar.
    try {
      if (window.history && window.history.replaceState) {
        var url = new URL(window.location.href);
        var keys = ['mlv2_res','mlv_ok','mlv_err','mlv_nombre'];
        var changed = false;
        for (var i=0;i<keys.length;i++){
          if (url.searchParams.has(keys[i])){ url.searchParams.delete(keys[i]); changed = true; }
        }
        if (changed) window.history.replaceState({}, document.title, url.toString());
      }
    } catch(e) {}

    // Persistir cierre si viene desde BD
    if (id && window.MLV2 && MLV2.ajax_url) {
      window.MLV2 = window.MLV2 || {};
      window.MLV2._dismissQueue = window.MLV2._dismissQueue || [];
      window.MLV2._dismissQueue.push(id);

      if (!window.MLV2._dismissTimer) {
        window.MLV2._dismissTimer = setTimeout(function(){
          var ids = window.MLV2._dismissQueue || [];
          window.MLV2._dismissQueue = [];
          window.MLV2._dismissTimer = null;

          var fd = new FormData();
          fd.append('action', 'mlv2_dismiss_alerts');
          fd.append('nonce', MLV2.nonce || '');
          fd.append('alert_ids', ids.join(','));

          fetch(MLV2.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
            .catch(function(){});
        }, 300);
      }
    }
  });
})();

// Flash alerts: si la URL trae params (mlv2_res/mlv_ok/mlv_err), los removemos al primer render.
// Así el usuario ve la alerta una vez, y al recargar no vuelve a aparecer.
(function(){
  function strip(){
    try {
      if (!(window.history && window.history.replaceState)) return;
      if (!document.querySelector('.mlv2-alerts')) return;
      var url = new URL(window.location.href);
      var keys = ['mlv2_res','mlv_ok','mlv_err','mlv_nombre'];
      var changed = false;
      for (var i=0;i<keys.length;i++){
        if (url.searchParams.has(keys[i])){ url.searchParams.delete(keys[i]); changed = true; }
      }
      if (changed) window.history.replaceState({}, document.title, url.toString());
    } catch(e) {}
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', strip);
  } else {
    strip();
  }
})();

// data-label ya se inyecta en enhanceTable / ensureDataLabels
