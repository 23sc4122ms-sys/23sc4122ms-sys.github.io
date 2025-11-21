// AJAX Cart & Orders - uses Bootstrap modals and toasts
// Provide nicer interactions: toasts, spinners, debounced qty updates

(function(){
  'use strict';

  // Helpers
  function escapeHtml(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  async function fetchJson(url, opts){
    const res = await fetch(url, opts);
    const ct = res.headers.get('content-type') || '';
    if(ct.indexOf('application/json') !== -1) return res.json();
    // fallback to text parse if not JSON
    const text = await res.text();
    try{ return JSON.parse(text); }catch(e){ return { ok:false, error: text || 'Invalid response' }; }
  }

  // Toast utilities
  function ensureToastContainer(){
    let container = document.getElementById('ajaxToastContainer');
    if(container) return container;
    container = document.createElement('div');
    container.id = 'ajaxToastContainer';
    container.style.position = 'fixed';
    container.style.right = '16px';
    container.style.top = '16px';
    container.style.zIndex = 12000;
    document.body.appendChild(container);
    return container;
  }

  function showToast(message, type='info', delay=3000){
    const container = ensureToastContainer();
    const toast = document.createElement('div');
    toast.className = 'toast align-items-center text-white bg-' + (type==='error' ? 'danger' : (type==='success' ? 'success' : 'primary')) + ' border-0';
    toast.setAttribute('role','alert');
    toast.setAttribute('aria-live','assertive');
    toast.setAttribute('aria-atomic','true');
    toast.style.minWidth = '200px';
    toast.style.marginBottom = '8px';
    toast.innerHTML = `<div class="d-flex"><div class="toast-body">${escapeHtml(message)}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>`;
    container.appendChild(toast);
    const bstoast = new bootstrap.Toast(toast, { delay });
    bstoast.show();
    toast.addEventListener('hidden.bs.toast', ()=> toast.remove());
    return bstoast;
  }

  // Generic modal creation using Bootstrap markup
  function createModal(id, title, size='lg'){
    // ensure any stray ajax modals/backdrops are removed first
    try{ closeAllModals(); }catch(e){}
    // remove existing element with same id
    const existing = document.getElementById(id);
    if(existing) existing.remove();
    const div = document.createElement('div');
    div.id = id;
    // mark as ajax-modal so central helpers can find and close/dispose them
    div.className = 'modal fade ajax-modal';
    div.tabIndex = -1;
    div.innerHTML = `
      <div class="modal-dialog modal-${size}">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">${escapeHtml(title)}</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body" id="${id}-body"></div>
          
        </div>
      </div>
    `;
    document.body.appendChild(div);
    return div;
  }

  // Close and remove any ajax-created modals and backdrops (robust cleanup)
  function closeAllModals(){
    try{
      // hide bootstrap modal instances
      document.querySelectorAll('.modal.ajax-modal').forEach(el=>{
        try{ const inst = bootstrap.Modal.getInstance(el); if(inst) inst.hide(); }catch(e){}
        try{ el.remove(); }catch(e){}
      });
      // remove any lingering backdrops
      document.querySelectorAll('.modal-backdrop').forEach(b=>{ try{ b.remove(); }catch(e){} });
      // clear modal-open class and inline styles on body
      try{ document.body.classList.remove('modal-open'); document.body.style.overflow = ''; document.body.style.paddingRight = ''; }catch(e){}
    }catch(e){ /* ignore */ }
  }

  // Create and show a star-rating modal (Shopee-style) for products or riders
  function showRatingModal(opts){
    // opts: { type: 'product'|'rider', id: <menuId or riderId>, title?, current?, onSuccess?: fn }
    if(!opts || !opts.type || !opts.id) return Promise.reject(new Error('Invalid rating options'));
    const title = opts.title || (opts.type==='rider' ? 'Rate Rider' : 'Rate Product');
    const modalEl = createModal('ratingModal', title, 'md');
    const bs = new bootstrap.Modal(modalEl);
    const body = document.getElementById('ratingModal-body');
    // build stars UI
    const cur = Number(opts.current) || 0;
    const starHtml = Array.from({length:5}).map((_,i)=>{
      const v = i+1;
      return `<button type="button" class="btn btn-link p-0 rating-star" data-value="${v}" style="font-size:28px;color:${v<=cur? '#FFC107' : '#CCC'};text-decoration:none">${v<=cur? '★' : '☆'}</button>`;
    }).join(' ');
    body.innerHTML = `
      <div class="text-center">
        <div id="ratingStars" style="margin-bottom:12px">${starHtml}</div>
        <textarea id="ratingComment" class="form-control form-control-sm" placeholder="Optional comment" rows="3"></textarea>
        <div class="d-flex justify-content-end mt-2">
          <button id="ratingCancel" class="btn btn-sm btn-outline-secondary me-2">Cancel</button>
          <button id="ratingSubmit" class="btn btn-sm btn-primary">Submit</button>
        </div>
      </div>
    `;
    // wiring
    const stars = body.querySelectorAll('.rating-star');
    let selected = cur;
    function render(){ stars.forEach(s=>{ const v = Number(s.dataset.value); s.style.color = (v<=selected ? '#FFC107' : '#CCC'); s.textContent = (v<=selected ? '★' : '☆'); }); }
    stars.forEach(s=>{ s.addEventListener('mouseenter', ()=>{ const v = Number(s.dataset.value); stars.forEach(x=>{ x.style.color = (Number(x.dataset.value) <= v ? '#FFD54A' : '#EEE'); x.textContent = (Number(x.dataset.value) <= v ? '★' : '☆'); }); }); s.addEventListener('mouseleave', ()=> render()); s.addEventListener('click', ()=>{ selected = Number(s.dataset.value); render(); }); });
    const submitBtn = document.getElementById('ratingSubmit');
    const cancelBtn = document.getElementById('ratingCancel');
    cancelBtn.addEventListener('click', ()=>{ try{ bs.hide(); }catch(e){} });
    const endpoint = (opts.type === 'rider') ? 'rate_rider.php' : 'rate_item.php';
    submitBtn.addEventListener('click', async function(){
      const comment = (document.getElementById('ratingComment')||{value:''}).value || '';
      if(!selected || selected < 1 || selected > 5){ showToast('Please select a rating (1-5)','error'); return; }
      submitBtn.disabled = true;
      try{
        const form = new URLSearchParams();
        if(opts.type === 'rider') form.append('rider_id', opts.id); else form.append('id', opts.id);
        form.append('rating', selected);
        if(comment) form.append('comment', comment);
        const res = await fetch(endpoint, { method: 'POST', body: form.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
        const j = await res.json();
        if(j && j.ok){
          showToast('Thanks — rating saved', 'success');
          try{ bs.hide(); }catch(e){}
          if(typeof opts.onSuccess === 'function') opts.onSuccess(j);
        } else {
          showToast(j && (j.error||j.msg) ? (j.error||j.msg) : 'Failed to save rating', 'error');
          submitBtn.disabled = false;
        }
      }catch(err){ showToast('Network error','error'); submitBtn.disabled = false; }
    });
    modalEl.addEventListener('hidden.bs.modal', function(){ try{ const inst = bootstrap.Modal.getInstance(modalEl); if(inst) try{ inst.dispose(); }catch(e){} modalEl.remove(); }catch(e){} });
    bs.show();
    return Promise.resolve();
  }

  // Show combined rating modal for product and (optionally) rider
  function showCombinedRatingModal(opts){
    // opts: { menuId?, riderId?, title?, onSuccess?: fn }
    if(!opts || (!opts.menuId && !opts.riderId)) return Promise.reject(new Error('Invalid combined rating options'));
    const title = opts.title || 'Rate';
    const modalEl = createModal('ratingModalCombined', title, 'md');
    const bs = new bootstrap.Modal(modalEl);
    const body = document.getElementById('ratingModalCombined-body');
    // build stars UI for product and rider when present
    const productStars = opts.menuId ? Array.from({length:5}).map((_,i)=> `<button type="button" class="btn btn-link p-0 rating-star-product" data-value="${i+1}" style="font-size:24px;color:#CCC;border:0">☆</button>`).join(' ') : '';
    const riderStars = opts.riderId ? Array.from({length:5}).map((_,i)=> `<button type="button" class="btn btn-link p-0 rating-star-rider" data-value="${i+1}" style="font-size:24px;color:#CCC;border:0">☆</button>`).join(' ') : '';
    body.innerHTML = `
      <div>
        ${ opts.menuId ? `<div style="font-weight:600">Rate Product</div><div id="prodStars" style="margin-bottom:8px">${productStars}</div>` : '' }
        ${ opts.riderId ? `<div style="font-weight:600;margin-top:8px">Rate Rider</div><div id="riderStars" style="margin-bottom:8px">${riderStars}</div>` : '' }
        <textarea id="ratingCommentCombined" class="form-control form-control-sm" placeholder="Optional comment" rows="3"></textarea>
        <div class="d-flex justify-content-end mt-2">
          <button id="ratingCancelCombined" class="btn btn-sm btn-outline-secondary me-2">Cancel</button>
          <button id="ratingSubmitCombined" class="btn btn-sm btn-primary">Submit</button>
        </div>
      </div>
    `;
    // wiring
    let prodSelected = 0, riderSelected = 0;
    const prodStarsEls = body.querySelectorAll('.rating-star-product');
    const riderStarsEls = body.querySelectorAll('.rating-star-rider');
    function renderProd(){ prodStarsEls.forEach(s=>{ const v = Number(s.dataset.value); s.style.color = (v<=prodSelected? '#FFC107' : '#CCC'); s.textContent = (v<=prodSelected? '★' : '☆'); }); }
    function renderRider(){ riderStarsEls.forEach(s=>{ const v = Number(s.dataset.value); s.style.color = (v<=riderSelected? '#FFC107' : '#CCC'); s.textContent = (v<=riderSelected? '★' : '☆'); }); }
    prodStarsEls.forEach(s=>{ s.addEventListener('mouseenter', ()=>{ const v = Number(s.dataset.value); prodStarsEls.forEach(x=>{ x.style.color = (Number(x.dataset.value) <= v ? '#FFD54A' : '#EEE'); x.textContent = (Number(x.dataset.value) <= v ? '★' : '☆'); }); }); s.addEventListener('mouseleave', renderProd); s.addEventListener('click', ()=>{ prodSelected = Number(s.dataset.value); renderProd(); }); });
    riderStarsEls.forEach(s=>{ s.addEventListener('mouseenter', ()=>{ const v = Number(s.dataset.value); riderStarsEls.forEach(x=>{ x.style.color = (Number(x.dataset.value) <= v ? '#FFD54A' : '#EEE'); x.textContent = (Number(x.dataset.value) <= v ? '★' : '☆'); }); }); s.addEventListener('mouseleave', renderRider); s.addEventListener('click', ()=>{ riderSelected = Number(s.dataset.value); renderRider(); }); });

    const submitBtn = document.getElementById('ratingSubmitCombined');
    const cancelBtn = document.getElementById('ratingCancelCombined');
    cancelBtn.addEventListener('click', ()=>{ try{ bs.hide(); }catch(e){} });
    submitBtn.addEventListener('click', async function(){
      const comment = (document.getElementById('ratingCommentCombined')||{value:''}).value || '';
      if((opts.menuId && (!prodSelected || prodSelected < 1)) && (opts.riderId && (!riderSelected || riderSelected < 1))){ showToast('Please select at least one rating (1-5)','error'); return; }
      submitBtn.disabled = true;
      try{
        let anyOk = false;
        // submit product rating if present
        if(opts.menuId && prodSelected && prodSelected > 0){
          const form = new URLSearchParams(); form.append('id', opts.menuId); form.append('rating', prodSelected); if(comment) form.append('comment', comment);
          try{
            const res = await fetch('rate_item.php', { method: 'POST', body: form.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
            const j = await res.json(); if(j && j.ok) anyOk = true; else if(j && j.error) showToast('Product: ' + j.error, 'error');
          }catch(e){ showToast('Network error while rating product','error'); }
        }
        // submit rider rating if present
        if(opts.riderId && riderSelected && riderSelected > 0){
          const form2 = new URLSearchParams(); form2.append('rider_id', opts.riderId); form2.append('rating', riderSelected); if(comment) form2.append('comment', comment);
          try{
            const res2 = await fetch('rate_rider.php', { method: 'POST', body: form2.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
            const j2 = await res2.json(); if(j2 && j2.ok) anyOk = true; else if(j2 && j2.error) showToast('Rider: ' + j2.error, 'error');
          }catch(e){ showToast('Network error while rating rider','error'); }
        }
        if(anyOk){ showToast('Thanks — rating saved', 'success'); try{ bs.hide(); }catch(e){} if(typeof opts.onSuccess === 'function') opts.onSuccess(); }
        else { showToast('Failed to save rating', 'error'); submitBtn.disabled = false; }
      }catch(err){ showToast('Network error','error'); submitBtn.disabled = false; }
    });
    modalEl.addEventListener('hidden.bs.modal', function(){ try{ const inst = bootstrap.Modal.getInstance(modalEl); if(inst) try{ inst.dispose(); }catch(e){} modalEl.remove(); }catch(e){} });
    bs.show();
    return Promise.resolve();
  }

  // Expose the function immediately so inline scripts can use it before DOMContentLoaded
  try{ window.showRatingModal = showRatingModal; }catch(e){ /* ignore if window not available */ }

  // Update header cart count helper
  function updateHeaderCartCount(count){
    const el = document.getElementById('cart-count');
    if(el) el.textContent = (count || 0);
  }

  // Add to cart
  async function addToCart(id, btn){
    try{ if(btn) btn.disabled = true; }catch(e){}
    try{
      const form = new URLSearchParams(); form.append('id', id); form.append('qty', 1);
      const j = await fetchJson('add_to_cart.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: form.toString() });
      if(j.ok){ updateHeaderCartCount(j.count || 0); showToast('Added to cart', 'success');
        if(btn){ const prev = btn.innerHTML; btn.innerHTML = 'Added ✓'; setTimeout(()=>btn.innerHTML = prev, 900); }
      } else { showToast(j.error || 'Add to cart failed', 'error'); }
    }catch(err){ showToast('Network error', 'error'); }
    finally{ try{ if(btn) btn.disabled = false; }catch(e){} }
  }

  // Load cart modal and content
  async function loadCart(){
    const modalEl = createModal('cartModal', 'My Cart', 'lg');
    const bsModal = new bootstrap.Modal(modalEl);
    bsModal.show();
    const body = document.getElementById('cartModal-body');
    // intentionally left blank while data loads (no visible "Loading…" placeholder)
    try{
      const j = await fetchJson('get_cart.php');
      if(!j.ok){ body.innerHTML = '<div class="text-danger">Failed to load cart</div>'; return; }
      if(!j.items || j.items.length === 0){ body.innerHTML = '<div class="text-muted">Your cart is empty.</div>'; return; }

      // Build table-like layout
      const container = document.createElement('div');
      container.className = 'container-fluid';
      const rows = j.items.map(it=>{
        return `
          <div class="row align-items-center py-2 border-bottom" data-id="${it.id}">
            <div class="col-auto"><input class="form-check-input cart-select" type="checkbox" data-id="${it.id}"></div>
            <div class="col-auto">${it.image ? `<img src="${escapeHtml(it.image)}" class="rounded" style="width:72px;height:72px;object-fit:cover">` : ''}</div>
            <div class="col"> <div class="fw-semibold">${escapeHtml(it.name)}</div>
              <div class="text-muted small">Price: $${Number(it.price).toFixed(2)}</div>
              <div class="mt-2">
                <div class="input-group input-group-sm" style="width:130px">
                  <button class="btn btn-outline-secondary qty-dec" data-id="${it.id}">-</button>
                  <input type="text" class="form-control text-center item-qty" data-id="${it.id}" value="${it.quantity}" readonly>
                  <button class="btn btn-outline-secondary qty-inc" data-id="${it.id}">+</button>
                </div>
              </div>
            </div>
            <div class="col-auto text-end">
              <div class="fw-bold item-subtotal" data-id="${it.id}">$${Number(it.subtotal).toFixed(2)}</div>
            </div>
          </div>
        `;
      }).join('\n');

      container.innerHTML = `
        <div class="mb-2 d-flex justify-content-between align-items-center">
          <div><input id="cart-select-all" class="form-check-input" type="checkbox"> <label for="cart-select-all" class="small">Select all</label></div>
          <div><button id="removeSelectedBtn" class="btn btn-sm btn-outline-danger">Remove selected</button></div>
        </div>
        <div class="cart-rows">${rows}</div>
        <div class="mt-3 d-flex justify-content-between align-items-center">
          <div id="cartTotal" class="fw-bold">Total: $${Number(j.total).toFixed(2)}</div>
          <div><button id="checkoutBtn" class="btn btn-primary btn-sm">Checkout</button></div>
        </div>
      `;

      body.innerHTML = '';
      body.appendChild(container);

      // Handlers
      document.getElementById('cart-select-all').addEventListener('change', function(){
        document.querySelectorAll('.cart-select').forEach(cb=> cb.checked = this.checked);
      });

      // qty handlers (delegated)
      body.querySelectorAll('.qty-inc').forEach(btn=> btn.addEventListener('click', async function(){
        const id = this.dataset.id; const qtyInput = body.querySelector('.item-qty[data-id="'+id+'"]');
        let qty = parseInt(qtyInput.value || '0', 10) || 0; qty++;
        await setQty(id, qty);
      }));
      body.querySelectorAll('.qty-dec').forEach(btn=> btn.addEventListener('click', async function(){
        const id = this.dataset.id; const qtyInput = body.querySelector('.item-qty[data-id="'+id+'"]');
        let qty = parseInt(qtyInput.value || '0', 10) || 0; qty--;
        if(qty <= 0){ if(!confirm('Quantity is 0 — remove item from cart?')) return; }
        await setQty(id, qty);
      }));

      async function setQty(id, qty){
        try{
          const form = new URLSearchParams(); form.append('id', id); form.append('qty', qty);
          const jr = await fetchJson('update_cart_qty.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: form.toString() });
          if(!jr.ok){ showToast(jr.error || 'Update failed','error'); return; }
          // update UI
          const qtyInput = body.querySelector('.item-qty[data-id="'+id+'"]'); if(qtyInput) qtyInput.value = jr.qty;
          const sub = body.querySelector('.item-subtotal[data-id="'+id+'"]'); if(sub) sub.textContent = '$' + Number(jr.itemSubtotal).toFixed(2);
          const totalEl = document.getElementById('cartTotal'); if(totalEl) totalEl.textContent = 'Total: $' + Number(jr.total).toFixed(2);
          if(jr.qty === 0){ const row = body.querySelector('.row[data-id="'+id+'"]'); if(row){ row.style.transition = 'opacity .25s'; row.style.opacity = 0; setTimeout(()=>row.remove(), 300); } }
          updateHeaderCartCount(jr.count || 0);
        }catch(err){ showToast('Network error','error'); }
      }

      // remove selected
      document.getElementById('removeSelectedBtn').addEventListener('click', async function(){
        const checked = Array.from(body.querySelectorAll('.cart-select:checked')).map(i=>i.dataset.id);
        if(checked.length === 0){ showToast('No items selected','info'); return; }
        if(!confirm('Remove selected items from cart?')) return;
        try{
          const form = new URLSearchParams(); checked.forEach(id=> form.append('ids[]', id));
          const jr = await fetchJson('remove_from_cart.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: form.toString() });
          if(!jr.ok){ showToast(jr.error||'Remove failed','error'); return; }
          updateHeaderCartCount(jr.count || 0);
          // refresh modal
          loadCart();
        }catch(err){ showToast('Network error','error'); }
      });

      // checkout
      document.getElementById('checkoutBtn').addEventListener('click', async function(){
        const checked = Array.from(body.querySelectorAll('.cart-select:checked')).map(i=>i.dataset.id);
        if(checked.length === 0){ showToast('Please select one or more items to checkout','info'); return; }
        try{
          this.disabled = true; this.textContent = 'Placing order...';
          const form = new URLSearchParams(); checked.forEach(id=> form.append('ids[]', id));
          const jr = await fetchJson('checkout.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest','Accept':'application/json'}, body: form.toString() });
          if(!jr.ok){ showToast(jr.error||'Checkout failed','error'); this.disabled = false; this.textContent = 'Checkout'; return; }
          updateHeaderCartCount(jr.count || 0);
          bsModal.hide();
          showToast('Order placed', 'success');
          // refresh orders and highlight new order when opened
          setTimeout(()=> loadMyOrders(jr.ref), 400);
        }catch(err){ showToast('Network error','error'); }
        finally{ this.disabled = false; this.textContent = 'Checkout'; }
      });

    }catch(err){ body.innerHTML = '<div class="text-danger">Error loading cart</div>'; }
  }

  // Load My Orders
  async function loadMyOrders(highlightRef){
    const modalEl = createModal('ordersModal', 'My Orders', 'lg');
    const bsModal = new bootstrap.Modal(modalEl);
    bsModal.show();
    const body = document.getElementById('ordersModal-body');
    // intentionally left blank while data loads (no visible "Loading…" placeholder)
    try{
      const j = await fetchJson('get_my_orders.php');
      if(!j.ok){ body.innerHTML = '<div class="text-danger">Failed to load orders</div>'; return; }
      if(!j.orders || j.orders.length === 0){ body.innerHTML = '<div class="text-muted">No recent orders found.</div>'; return; }
      // build order cards
        const nodes = j.orders.map(o=>{
        const items = (o.items||[]).map(it=>{
          const img = it.image? `<img src="${escapeHtml(it.image)}" style="width:56px;height:56px;object-fit:cover;border-radius:6px;margin-right:8px">` : '';
          const statusLower = String(it.status||'').toLowerCase();
          const statusHtml = (statusLower === 'completed') ? `<div class="small text-success">Status: <strong class="order-list-status">Completed</strong></div>` : `<div class="small">Status: <strong class="order-list-status">${escapeHtml(it.status||'')}</strong></div>`;
          return `<div class="d-flex align-items-center mb-2"><div>${img}</div><div><div class="fw-semibold">${escapeHtml(it.product_name)}</div><div class="small text-muted">Qty: ${it.quantity} — $${Number(it.price).toFixed(2)}</div>${statusHtml}</div></div>`;
        }).join('');
        const label = o.ref ? ('Order ' + escapeHtml(o.ref)) : ('Order #' + o.id);
        
        // Check if any items are completed and can be rated
        const hasRateableItems = (o.items||[]).some(it => String(it.status||'').toLowerCase() === 'completed' && it.menu_item_id);
        const rateButtonsHtml = hasRateableItems ? (o.items||[]).map(it => {
          if(String(it.status||'').toLowerCase() === 'completed' && it.menu_item_id) {
            return `<button class="btn btn-sm btn-primary ms-2 rate-item-btn" data-item-id="${it.id}" data-menu-id="${it.menu_item_id}" data-rider-id="${o.rider_id||''}" data-order-id="${o.id}" data-product-name="${escapeHtml(it.product_name)}">Rate "${escapeHtml(it.product_name.substring(0,15))}"</button>`;
          }
          return '';
        }).join('') : '';
        
        return `
          <div class="card mb-3 order-card" data-order-id="${o.id}">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start">
                <div><div class="fw-semibold">${label}</div><div class="small text-muted">${escapeHtml(o.created_at)}</div></div>
                <div class="fw-bold">$${Number(o.total).toFixed(2)}</div>
              </div>
              <div class="mt-3">${items}</div>
              <div class="mt-2 d-flex align-items-center flex-wrap">
                <div class="me-auto">
                  <button class="btn btn-sm btn-outline-primary seeDetailsBtn" data-order-id="${o.id}">See details</button>
                  ${rateButtonsHtml}
                </div>
                <div>
                  ${ (o.items||[]).some(it=>['processing','pending','approved'].includes(String(it.status||'').toLowerCase())) ? `<button class="btn btn-sm btn-outline-danger cancel-order-btn" data-order-id="${o.id}">Cancel Order</button>` : '' }
                  ${ (o.can_rate_rider && o.rider_id) ? `<button class="btn btn-sm btn-primary ms-2 rate-rider-btn" data-rider-id="${o.rider_id}" data-order-id="${o.id}">Rate Rider</button>` : '' }
                </div>
              </div>
            </div>
          </div>
        `;
      }).join('\n');
      body.innerHTML = nodes;

      // attach handlers
      body.querySelectorAll('.seeDetailsBtn').forEach(btn=> btn.addEventListener('click', function(){ loadOrderDetails(this.dataset.orderId); }));
      body.querySelectorAll('.cancel-order-btn').forEach(btn=> btn.addEventListener('click', async function(){
        const orderId = this.dataset.orderId; if(!orderId) return; if(!confirm('Cancel the entire order?')) return;
        try{
          this.disabled = true;
          const form = new URLSearchParams(); form.append('order', orderId);
          const jr = await fetchJson('cancel_order.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest','Accept':'application/json'}, body: form.toString() });
          if(!jr.ok){ showToast(jr.error||'Cancel failed','error'); this.disabled = false; return; }
          // remove or update
          const card = body.querySelector('.order-card[data-order-id="'+orderId+'"]');
          if(card){ if(typeof jr.total !== 'undefined' && Number(jr.total)===0) card.remove(); else if(typeof jr.total !== 'undefined'){ const cost = card.querySelector('.fw-bold'); if(cost) cost.textContent = '$' + Number(jr.total).toFixed(2); } }
          showToast('Order updated','success');
        }catch(err){ showToast('Network error','error'); }
      }));

      // attach rate-item handlers in the list view
      body.querySelectorAll('.rate-item-btn').forEach(btn=>{
        if(btn.dataset.bound) return; btn.dataset.bound = '1';
        btn.addEventListener('click', function(){
          const menuId = this.dataset.menuId; const itemId = this.dataset.itemId;
          if(!menuId) return alert('No product id');
            const riderId = this.dataset.riderId || null;
            const btnRef = this;
            if(window.showCombinedRatingModal){
              showCombinedRatingModal({ menuId: menuId, riderId: riderId, onSuccess: ()=>{ 
                btnRef.disabled = true;
                btnRef.textContent = '✓ Rated';
                btnRef.classList.remove('btn-primary');
                btnRef.classList.add('btn-success');
              } });
            } else if(window.showRatingModal){
              showRatingModal({ type: 'product', id: menuId, onSuccess: ()=>{ 
                btnRef.disabled = true;
                btnRef.textContent = '✓ Rated';
                btnRef.classList.remove('btn-primary');
                btnRef.classList.add('btn-success');
              } });
            } else {
              showToast('Rating UI unavailable in this browser', 'error');
            }
        });
      });

      // attach rate-rider handlers for orders
      body.querySelectorAll('.rate-rider-btn').forEach(btn=>{
        if(btn.dataset.bound) return; btn.dataset.bound = '1';
        btn.addEventListener('click', function(){
          const riderId = this.dataset.riderId; if(!riderId) return alert('No rider id');
          const btnRef = this;
          if(window.showRatingModal){
            showRatingModal({ type: 'rider', id: riderId, onSuccess: ()=>{ try{ btnRef.remove(); }catch(e){} } });
            return;
          }
          showToast('Rating UI unavailable in this browser', 'error');
        });
      });

      // highlight newly placed
      if(highlightRef){ const el = Array.from(body.querySelectorAll('.order-card')).find(x=> x.textContent && x.textContent.indexOf(highlightRef) !== -1); if(el){ el.classList.add('border','border-success'); setTimeout(()=> el.classList.remove('border','border-success'), 2500); } }

      // start polling for updates while the modal is open so completed items appear without closing
      (function startPolling(){
        let pollId = null;
        function applyUpdates(data){
          if(!data || !data.orders) return;
          data.orders.forEach(o => {
            const card = body.querySelector('.order-card[data-order-id="'+o.id+'"]');
            if(!card) return;
            (o.items||[]).forEach(it => {
              const statusEl = card.querySelector('.order-list-status');
              // update only the item's status text if present
              if(statusEl && String(it.status||'').toLowerCase() === 'completed'){
                // replace the small status element to show completed in green
                const container = statusEl.closest('div');
                if(container) container.innerHTML = '<div class="small text-success">Status: <strong class="order-list-status">Completed</strong></div>';
              }
              // add rate button if can_rate and not present
                  if(it.can_rate){
                const existingBtn = card.querySelector('.rate-item-btn[data-menu-id="'+(it.menu_item_id||'')+'"]');
                if(!existingBtn){
                  // find the "See details" button area and add rate button after it
                  const detailsBtn = card.querySelector('.seeDetailsBtn');
                  if(detailsBtn && detailsBtn.parentElement){
                    const btn = document.createElement('button');
                    btn.className = 'btn btn-sm btn-primary ms-2 rate-item-btn';
                    btn.dataset.itemId = it.id;
                    btn.dataset.menuId = it.menu_item_id;
                    btn.dataset.riderId = o.rider_id || '';
                    btn.dataset.orderId = o.id;
                    btn.dataset.productName = it.product_name;
                    btn.textContent = 'Rate "' + (it.product_name.substring(0,15)) + '"';
                    detailsBtn.parentElement.appendChild(btn);
                    // attach handler to new button
                    btn.addEventListener('click', function(){
                      const menuId = this.dataset.menuId; const itemId = this.dataset.itemId;
                      if(!menuId) return alert('No product id');
                      const riderId = this.dataset.riderId || null;
                      const btnRef = this;
                      if(window.showCombinedRatingModal){
                        showCombinedRatingModal({ menuId: menuId, riderId: riderId, onSuccess: ()=>{ 
                          btnRef.disabled = true;
                          btnRef.textContent = '✓ Rated';
                          btnRef.classList.remove('btn-primary');
                          btnRef.classList.add('btn-success');
                        } });
                      } else if(window.showRatingModal){
                        showRatingModal({ type: 'product', id: menuId, onSuccess: ()=>{ 
                          btnRef.disabled = true;
                          btnRef.textContent = '✓ Rated';
                          btnRef.classList.remove('btn-primary');
                          btnRef.classList.add('btn-success');
                        } });
                      } else {
                        showToast('Rating UI unavailable in this browser', 'error');
                      }
                    });
                  }
                }
              }
              // add rider rate button on the order card if server indicates it
              if(o.can_rate_rider && o.rider_id){
                const existingRiderBtn = card.querySelector('.rate-rider-btn');
                if(!existingRiderBtn){
                  const actionsDiv = card.querySelector('.mt-2');
                  if(actionsDiv){
                    const btn = document.createElement('button'); btn.className = 'btn btn-sm btn-primary ms-2 rate-rider-btn'; btn.dataset.riderId = o.rider_id; btn.dataset.orderId = o.id; btn.textContent = 'Rate Rider';
                    // append to actions area
                    const right = actionsDiv.querySelector('div:last-child') || actionsDiv;
                    right.appendChild(btn);
                  }
                }
              }
            });
          });
          // attach handlers for any newly added rate buttons
          body.querySelectorAll('.rate-item-btn').forEach(btn=>{
            if(btn.dataset.bound) return; btn.dataset.bound = '1';
            btn.addEventListener('click', function(){
              const menuId = this.dataset.menuId; const itemId = this.dataset.itemId; const riderId = this.dataset.riderId || null;
              if(!menuId) return alert('No product id');
            if(window.showCombinedRatingModal){
                showCombinedRatingModal({ menuId: menuId, riderId: riderId, onSuccess: ()=>{ 
                  this.disabled = true;
                  this.textContent = '✓ Rated';
                  this.classList.remove('btn-primary');
                  this.classList.add('btn-success');
                } });
            } else if(window.showRatingModal){
                showRatingModal({ type: 'product', id: menuId, onSuccess: ()=>{ 
                  this.disabled = true;
                  this.textContent = '✓ Rated';
                  this.classList.remove('btn-primary');
                  this.classList.add('btn-success');
                } });
            } else {
                showToast('Rating UI unavailable in this browser', 'error');
            }
            });
          });
        }

        // poll every 8 seconds
        pollId = setInterval(async ()=>{
          try{
            const res = await fetch('get_my_orders.php');
            const j = await res.json();
            if(j && j.ok) applyUpdates(j);
          }catch(e){ /* ignore polling errors */ }
        }, 8000);

        // clear when modal hidden
        modalEl.addEventListener('hidden.bs.modal', function(){ try{ clearInterval(pollId); }catch(e){} });
      })();
    }catch(err){ body.innerHTML = '<div class="text-danger">Error loading orders</div>'; }
  }

  // load single order details
  async function loadOrderDetails(id){
    if(!id) return;
    const modalEl = createModal('ordersModal', 'Order Details', 'lg');
    const bsModal = new bootstrap.Modal(modalEl);
    bsModal.show();
    const body = document.getElementById('ordersModal-body');
    // intentionally left blank while data loads (no visible "Loading..." placeholder)
    try{
      const j = await fetchJson('get_order.php?order=' + encodeURIComponent(id));
      if(!j.ok){ body.innerHTML = '<div class="text-danger">Failed to load order</div>'; return; }
      const o = j.order;
      const parts = [];
      parts.push(`<div class="mb-2"><button class="btn btn-sm btn-outline-secondary" id="backToOrdersBtn">← Back to orders</button></div>`);
      parts.push(`<div class="fw-semibold mb-2">${o.ref ? 'Order ' + escapeHtml(o.ref) : 'Order #' + o.id} — ${escapeHtml(o.created_at)} — <span class="fw-bold">$${Number(o.total).toFixed(2)}</span></div>`);
      // if there is a delivery attached but order not paid, warn user that payment is required before rider payment/confirmation
      const paidFlag = j.order && (j.order.paid === 1 || j.order.paid === '1' || j.order.paid === true);
      if(j.delivery && !paidFlag){ parts.push('<div class="alert alert-warning">Payment required before rider can be paid or delivery confirmed. If you have already paid, please refresh or contact support.</div>'); }
      parts.push('<div>');
      (j.items||[]).forEach((it, idx)=>{
        const rateControl = (String(it.status||'').toLowerCase() === 'completed') ? `<div class="mt-2"><button class="rate-item-btn btn btn-sm btn-primary" data-item-id="${it.id}" data-menu-id="${it.menu_item_id}" data-rider-id="${j.rider_id||''}">Rate this item</button></div>` : '';
        parts.push(`<div class="p-2 mb-2 border rounded order-detail-item" data-item-id="${it.id}"><div class="fw-semibold">${escapeHtml(it.product_name)}</div><div class="small text-muted">Qty: ${it.quantity} • $${Number(it.price).toFixed(2)}</div><div class="small">Status: <strong class="order-item-status" data-item-id="${it.id}">${escapeHtml(it.status||'')}</strong></div>${ String(it.status||'').toLowerCase() === 'processing' ? `<div class="mt-2"><button class="btn btn-sm btn-outline-danger cancel-item-btn" data-item-id="${it.id}">Cancel</button></div>` : '' }${rateControl}</div>`);
      });
      parts.push('</div>');
      body.innerHTML = parts.join('\n');

      const back = document.getElementById('backToOrdersBtn'); if(back) back.addEventListener('click', function(e){ e.preventDefault(); loadMyOrders(); });

      body.querySelectorAll('.cancel-item-btn').forEach(btn=> btn.addEventListener('click', async function(){
        const itemId = this.dataset.itemId; if(!itemId) return; if(!confirm('Cancel this item?')) return;
        try{
          this.disabled = true;
          const form = new URLSearchParams(); form.append('item', itemId);
          const jr = await fetchJson('cancel_order_item.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest','Accept':'application/json'}, body: form.toString() });
          if(!jr.ok){ showToast(jr.error||'Cancel failed','error'); this.disabled = false; return; }
          const statusEl = body.querySelector('.order-item-status[data-item-id="'+itemId+'"]'); if(statusEl) statusEl.textContent = jr.status || 'cancelled';
          const row = body.querySelector('.order-detail-item[data-item-id="'+itemId+'"]'); if(row){ row.classList.add('text-muted'); row.style.textDecoration = 'line-through'; }
          showToast('Item cancelled','success');
        }catch(err){ showToast('Network error','error'); }
      }));

      // attach rate-item handlers
      body.querySelectorAll('.rate-item-btn').forEach(btn=>{
        if(btn.dataset.bound) return; btn.dataset.bound = '1';
        btn.addEventListener('click', function(){
          const menuId = this.dataset.menuId; const itemId = this.dataset.itemId; const riderId = this.dataset.riderId || null;
          if(!menuId){ showToast('No product id','error'); return; }
          if(window.showCombinedRatingModal){
            showCombinedRatingModal({ menuId: menuId, riderId: riderId, onSuccess: ()=>{ try{ this.remove(); }catch(e){} } });
            return;
          }
          if(window.showRatingModal){
            showRatingModal({ type: 'product', id: menuId, onSuccess: ()=>{ try{ this.remove(); }catch(e){} } });
            return;
          }
          showToast('Rating UI unavailable in this browser', 'error');
        });
      });

      // rider rating control (if server indicates and order paid)
      const paidFlag2 = j.order && (j.order.paid === 1 || j.order.paid === '1' || j.order.paid === true);
      if(j.can_rate_rider && j.rider_id && paidFlag2){
        const riderBlock = document.createElement('div'); riderBlock.style.marginTop = '8px';
        riderBlock.innerHTML = `<div style="font-weight:600">Rate your driver</div><div class="small text-muted">You can rate your rider for this delivery.</div><div style="margin-top:6px;"><button id="rateRiderBtn" class="btn btn-sm btn-primary">Rate Rider</button></div>`;
        body.appendChild(riderBlock);
        const rb = document.getElementById('rateRiderBtn');
        if(rb){ rb.addEventListener('click', function(){ if(window.showRatingModal){ showRatingModal({ type: 'rider', id: j.rider_id, onSuccess: ()=>{ try{ rb.remove(); }catch(e){} } }); return; } showToast('Rating UI unavailable in this browser', 'error'); }); }
      }

    }catch(err){ body.innerHTML = '<div class="text-danger">Error loading details</div>'; }
  }

  // Account menu loader (keeps existing account button behavior)
  function initAccountMenu(){
    const btn = document.getElementById('accountBtn');
    const menu = document.getElementById('accountMenu');
    if(!btn || !menu) return;
    let loaded = false;
    async function loadMenu(){
      try{
        const res = await fetch('account_menu.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const html = await res.text(); menu.innerHTML = html;
        const txBtn = menu.querySelector('[data-action="transactions"]'); if(txBtn) txBtn.addEventListener('click', function(e){ e.preventDefault(); hideMenu(); loadMyOrders(); });
        const acctBtn = menu.querySelector('[data-action="account"]'); if(acctBtn) acctBtn.addEventListener('click', function(e){ e.preventDefault(); hideMenu(); const url = acctBtn.dataset.url || 'customer_account_management.php'; loadAccountManagement(url); });
        const signout = menu.querySelector('[data-action="signout"]'); if(signout) signout.addEventListener('click', async function(e){ e.preventDefault(); if(!confirm('Sign out?')) return; try{ const res2 = await fetch('logout.php', { method:'POST', headers:{ 'X-Requested-With':'XMLHttpRequest' } }); const j = await res2.json(); if(j && j.ok) window.location = j.redirect || 'index.php'; else window.location = 'index.php'; }catch(err){ window.location = 'index.php'; } });
        loaded = true;
      }catch(err){ menu.innerHTML = '<div class="small text-danger p-2">Failed to load menu</div>'; }
    }
    function showMenu(){ menu.style.display = 'block'; }
    function hideMenu(){ menu.style.display = 'none'; }
    btn.addEventListener('click', async function(e){ e.stopPropagation(); if(menu.style.display === 'block'){ hideMenu(); return; } if(!loaded) await loadMenu(); showMenu(); });
    document.addEventListener('click', function(ev){ if(!menu.contains(ev.target) && ev.target !== btn){ hideMenu(); } });
  }

  // Load account management fragment into a modal
  async function loadAccountManagement(url){
    const modalEl = createModal('accountMgmtModal', 'Account', 'md');
    const bsModal = new bootstrap.Modal(modalEl);
    bsModal.show();
    const body = document.getElementById('accountMgmtModal-body');
    // intentionally left blank while data loads (no visible "Loading…" placeholder)
    try{
      const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const html = await res.text(); body.innerHTML = html;
      // attempt to run inline scripts in fragment
      const scripts = body.querySelectorAll('script');
      scripts.forEach(old=>{ const s = document.createElement('script'); if(old.src) s.src = old.src; if(old.textContent) s.textContent = old.textContent; old.parentNode.replaceChild(s, old); });
      // handle form submit inside fragment via AJAX if present
      const form = body.querySelector('form'); if(form){ form.addEventListener('submit', async function(e){ e.preventDefault(); const fd = new FormData(form); try{ const r = await fetch(url, { method:'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } }); const txt = await r.text(); body.innerHTML = txt; }catch(err){ showToast('Network error','error'); } }); }
    }catch(err){ body.innerHTML = '<div class="text-danger">Failed to load account</div>'; }
  }

  // Attach global event listeners once DOM ready
  document.addEventListener('DOMContentLoaded', function(){
    // wire header buttons
    const cartBtn = document.getElementById('cartBtn'); if(cartBtn) cartBtn.addEventListener('click', function(){ loadCart(); });
    const myOrdersBtn = document.getElementById('myOrdersBtn'); if(myOrdersBtn) myOrdersBtn.addEventListener('click', function(){ loadMyOrders(); });
    // expose functions to global scope for inline onclick attributes
    window.addToCart = addToCart;
    window.loadCart = loadCart;
    window.loadMyOrders = loadMyOrders;
    window.loadOrderDetails = loadOrderDetails;
    window.loadAccountManagement = loadAccountManagement;
    // expose rating modal helper so other inline scripts can use it
    window.showRatingModal = showRatingModal;
    window.showCombinedRatingModal = showCombinedRatingModal;
    initAccountMenu();
  });
  // signal that this external script provides AJAX cart/orders handlers
  try{ window.hasAjaxCartOrdersScript = true; }catch(e){}

})();
