// ======= Cart Page Logic (AJAX-backed) =======
const cartListEl = document.getElementById('cart-list');
const cartTotalEl = document.getElementById('cart-total')?.children[1];
const checkoutBtn = document.getElementById('checkout');
const clearBtn = document.getElementById('clear');

async function fetchCart(){
  cartListEl.innerHTML = '<div class="text-muted">Loading cart…</div>';
  try{
    const res = await fetch('get_cart.php');
    const j = await res.json();
    if(!j.ok) return renderEmpty('Failed to load cart');
    renderCartItems(j.items || [], j.total || 0);
  }catch(err){ renderEmpty('Network error'); }
}

function renderEmpty(msg){
  cartListEl.innerHTML = `<div class="text-muted small">${msg || 'Cart is empty'}</div>`;
  if(cartTotalEl) cartTotalEl.textContent = '₱0.00';
  if(checkoutBtn) checkoutBtn.disabled = true;
}

function renderCartItems(items, total){
  if(!items || items.length === 0) return renderEmpty('Cart is empty');
  cartListEl.innerHTML = '';
  items.forEach(it=>{
    const row = document.createElement('div');
    row.className = 'd-flex align-items-center mb-3 cart-row';
    row.dataset.id = it.id;
    row.innerHTML = `
      <div style="width:72px">${it.image? `<img src="${it.image}" style="width:72px;height:72px;object-fit:cover;border-radius:6px">` : ''}</div>
      <div class="ms-3 flex-grow-1">
        <div class="fw-bold">${escapeHtml(it.name)}</div>
        <div class="text-muted small">₱${Number(it.price).toFixed(2)}</div>
        <div class="mt-2">
          <div class="input-group input-group-sm" style="max-width:160px">
            <button class="btn btn-outline-secondary qty-dec" data-id="${it.id}" type="button">−</button>
            <input type="text" class="form-control text-center item-qty" data-id="${it.id}" value="${it.quantity}" readonly>
            <button class="btn btn-outline-secondary qty-inc" data-id="${it.id}" type="button">+</button>
          </div>
        </div>
      </div>
      <div class="text-end ms-3" style="min-width:110px">
        <div class="fw-bold">₱<span class="item-subtotal" data-id="${it.id}">${Number(it.subtotal).toFixed(2)}</span></div>
        <div class="small text-muted mt-1"><button class="btn btn-link btn-sm remove-item" data-id="${it.id}">Remove</button></div>
      </div>
    `;
    cartListEl.appendChild(row);
  });
  if(cartTotalEl) cartTotalEl.textContent = `₱${Number(total).toFixed(2)}`;
  if(checkoutBtn) checkoutBtn.disabled = false;
  attachCartHandlers();
}

function attachCartHandlers(){
  document.querySelectorAll('.qty-inc').forEach(b=>{ if(b.dataset.bound) return; b.dataset.bound='1'; b.addEventListener('click', async ()=>{ await changeQty(b.dataset.id, 1); }); });
  document.querySelectorAll('.qty-dec').forEach(b=>{ if(b.dataset.bound) return; b.dataset.bound='1'; b.addEventListener('click', async ()=>{ await changeQty(b.dataset.id, -1); }); });
  document.querySelectorAll('.remove-item').forEach(b=>{ if(b.dataset.bound) return; b.dataset.bound='1'; b.addEventListener('click', async ()=>{ if(!confirm('Remove item?')) return; await setQty(b.dataset.id, 0); }); });
}

async function changeQty(id, delta){
  const qtyEl = document.querySelector('.item-qty[data-id="'+id+'"]');
  let qty = parseInt(qtyEl?.value || '0') + delta;
  if(qty < 0) qty = 0;
  await setQty(id, qty);
}

async function setQty(id, qty){
  try{
    const form = new URLSearchParams(); form.append('id', id); form.append('qty', qty);
    const res = await fetch('update_cart_qty.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: form.toString() });
    const j = await res.json();
    if(!j.ok) return alert(j.error || 'Failed to update cart');
    // refresh cart UI with server response
    await fetchCart();
    // update header badge if present
    const el = document.getElementById('cart-count'); if(el) el.textContent = (j.count || 0);
  }catch(err){ alert('Network error'); }
}

// clear cart
if(clearBtn) clearBtn.addEventListener('click', async ()=>{
  if(!confirm('Clear cart?')) return;
  try{
    const res = await fetch('remove_from_cart.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({'clear':'1'}).toString() });
    const j = await res.json(); if(!j.ok) return alert(j.error||'Failed');
    await fetchCart(); const el = document.getElementById('cart-count'); if(el) el.textContent = (j.count||0);
  }catch(e){ alert('Network error'); }
});

// checkout — reuse existing page behaviour if needed
if(checkoutBtn) checkoutBtn.addEventListener('click', async ()=>{
  const checked = Array.from(document.querySelectorAll('.item-qty')).map(i=>i.dataset.id);
  if(checked.length === 0) return alert('Cart is empty');
  // simple checkout: submit all items
  try{
    checkoutBtn.disabled = true; checkoutBtn.textContent = 'Placing order...';
    const form = new URLSearchParams(); checked.forEach(id=> form.append('ids[]', id));
    const res = await fetch('checkout.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: form.toString() });
    const j = await res.json();
    if(!j.ok){ alert(j.error || 'Checkout failed'); checkoutBtn.disabled = false; checkoutBtn.textContent = 'Checkout'; return; }
    // redirect to orders or show success
    window.location = '/index.php';
  }catch(err){ alert('Network error'); checkoutBtn.disabled = false; checkoutBtn.textContent = 'Checkout'; }
});

// initial load
fetchCart();

// helper
function escapeHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
