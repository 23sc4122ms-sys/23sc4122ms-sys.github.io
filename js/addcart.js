// ======= Cart & Menu Logic =======

const bestMenuEl = document.getElementById('best-menu');
const otherMenuEl = document.getElementById('other-menu');
const cartCountEl = document.getElementById('cart-count');

let cart = JSON.parse(localStorage.getItem('cart')) || {};

// Sample menu data
const menuData = [
  { id: 1, name: 'Cheesy Beef Burger', desc: 'Juicy burger with double cheese', price: 149, best: true },
  { id: 2, name: 'Crispy Chicken', desc: 'Fried chicken with secret spice', price: 139, best: true },
  { id: 3, name: 'Veggie Wrap', desc: 'Fresh veggies and hummus', price: 119, best: false },
  { id: 4, name: 'Milkshake (Chocolate)', desc: 'Rich and creamy', price: 89, best: false },
  { id: 5, name: 'Family Fries', desc: 'Large seasoned fries', price: 99, best: false },
  { id: 6, name: 'Sushi Box', desc: '8-piece assorted sushi', price: 249, best: true }
];

// ======= Render Menu Items =======
function renderMenu() {
  bestMenuEl.innerHTML = '';
  otherMenuEl.innerHTML = '';

  menuData.forEach(item => {
    const card = document.createElement('div');
    card.className = 'card';
    card.innerHTML = `
      <div class="food-img">${item.name.split(' ')[0]}</div>
      <div>
        <h3 class="food-title">${item.name}</h3>
        <p class="food-desc">${item.desc}</p>
      </div>
      <div class="card-row">
        <div style="font-weight:700">₱${item.price.toFixed(2)}</div>
        <div>
          <button class="btn" data-id="${item.id}">Add to Cart</button>
        </div>
      </div>
    `;

    if (item.best) bestMenuEl.appendChild(card);
    else otherMenuEl.appendChild(card);
  });

  // Add event listeners for "Add" buttons
  document.querySelectorAll('button.btn').forEach(b => {
    b.addEventListener('click', () => {
      const id = Number(b.dataset.id);
      addToCart(id);
    });
  });
}

// ======= Add Item to Cart =======
function addToCart(id) {
  const item = menuData.find(i => i.id === id);
  if (!item) return;
  if (cart[id]) cart[id].qty++;
  else cart[id] = { ...item, qty: 1 };
  saveCart();
  updateCartCount();
}

// ======= Update Cart Badge =======
function updateCartCount() {
  // Always read from localStorage to sync across pages
  cart = JSON.parse(localStorage.getItem('cart')) || {};
  const totalQty = Object.values(cart).reduce((sum, item) => sum + item.qty, 0);
  if (cartCountEl) cartCountEl.textContent = totalQty;
}

// ======= Save Cart to LocalStorage =======
function saveCart() {
  localStorage.setItem('cart', JSON.stringify(cart));
}

// ======= Reset Cart (for Checkout or Clear) =======
function resetCart() {
  cart = {};
  saveCart();
  updateCartCount();
}

// ======= Initialize =======
renderMenu();
updateCartCount();

// ======= Listen for storage events (optional) =======
// This ensures the cart badge updates if user clears cart on cart.html
window.addEventListener('storage', () => {
  updateCartCount();
});
