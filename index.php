<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <?php
  // Load site name and color palette from settings so index reflects admin settings
  $site_name = 'Japan Food Trip';
  $primary_color = '#EE6F57';
  $secondary_color = '#CB3737';
  try{
    require_once __DIR__ . '/db.php';
    $tmpPdo = getPDO();
    $sth = $tmpPdo->prepare('SELECT v FROM settings WHERE k = :k LIMIT 1');
    $sth->execute([':k' => 'site_name']);
    $v = $sth->fetchColumn();
    if($v !== false && strlen(trim((string)$v)) > 0) $site_name = $v;
    // optional colors
    $sth = $tmpPdo->prepare('SELECT v FROM settings WHERE k = :k LIMIT 1');
    $sth->execute([':k' => 'color_primary']);
    $v = $sth->fetchColumn(); if($v !== false && trim((string)$v) !== '') $primary_color = $v;
    $sth->execute([':k' => 'color_secondary']);
    $v = $sth->fetchColumn(); if($v !== false && trim((string)$v) !== '') $secondary_color = $v;
  }catch(Exception $e){ /* ignore and use fallback */ }
  ?>
  <title><?= htmlspecialchars($site_name) ?></title>
  <link rel="stylesheet" href="css/design.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <style>
    /* Header layout (uses admin-configured primary color) */
    header{ display:flex; justify-content:space-between; align-items:center; padding:18px 16px; border-bottom:1px solid rgba(0,0,0,0.06); background: <?= htmlspecialchars($primary_color) ?>; color:#fff; }
    header .brand{ display:flex; gap:12px; align-items:center; text-decoration:none; color:inherit; }
    header .logo{ width:48px; height:48px; background:#fff; color: <?= htmlspecialchars($primary_color) ?>; display:flex; align-items:center; justify-content:center; border-radius:8px; font-weight:800; font-size:18px; }
    header .brand h1{ margin:0; font-size:18px; line-height:1; color:#fff; }
    header .brand p.lead{ margin:0; font-size:12px; color:rgba(255,255,255,0.9); }
    .actions{ display:flex; gap:10px; align-items:center; }
    .search{ padding:8px 12px; border-radius:24px; border:1px solid rgba(255,255,255,0.12); width:260px; background: rgba(255,255,255,0.95); }
    .icon-btn{ background:transparent; border:0; font-size:18px; cursor:pointer; position:relative; color:#fff; }
    .cart-count{ position:absolute; top:-6px; right:-8px; background: <?= htmlspecialchars($secondary_color) ?>; color:#fff; border-radius:12px; padding:2px 6px; font-size:12px; }

    /* Page content centering */
    .products-container{ padding:28px 12px 48px; max-width:4000px; margin:0 auto; }
    .products-section{ margin:100px; max-width:4000px; }

    /* Add horizontal spacing for each product column (safe gutters) */
    #all-menu > [class*='col-']{ padding-left:16px; padding-right:16px; box-sizing:border-box; }

    /* Product card image fix: consistent aspect and cover */
    .card-img-top{ height:180px; object-fit:cover; border-top-left-radius:8px; border-top-right-radius:8px; }
    /* Use block layout inside product card body and add left padding to card content */
    .product-card{ margin-left:0; transition:transform 0.3s ease, box-shadow 0.3s ease; }
    .product-card:hover{ transform:translateY(-8px); box-shadow:0 12px 28px rgba(0,0,0,0.15); }
    .product-card__body{ display:block; padding-left:24px; }
    .add-btn{ background:<?= htmlspecialchars($primary_color) ?>; border-color: <?= htmlspecialchars($primary_color) ?>; color:#fff; transition:all 0.2s ease; }
    .add-btn:hover{ opacity:0.9; transform:scale(1.05); }
    .add-btn:active{ transform:scale(0.95); }

    /* Small screens adjustments */
    @media (max-width:900px){
      header{ flex-direction:column; align-items:flex-start; gap:10px; }
      .search{ width:140px; }
      .card-img-top{ height:150px; }
      .products-container{ padding:18px 8px; }
      /* Reduce the card content left padding on small screens to avoid overflow */
      .product-card{ margin-left:0; }
      .product-card .card-body{ padding-left:10px; }
    }
  </style>
</head>
<body>
  <?php
  session_start();
  require_once __DIR__ . '/db.php';
  $pdo = getPDO();
  // cart count: prefer DB per-user cart when logged in, otherwise session
  $cart_count = 0;
  if (!empty($_SESSION['user_id'])) {
    try {
      $sth = $pdo->prepare('SELECT COUNT(*) FROM user_cart WHERE user_id = :uid');
      $sth->execute([':uid' => (int)$_SESSION['user_id']]);
      $cart_count = (int)$sth->fetchColumn();
    } catch (Exception $e) {
      // fallback to session cart on error
      if(!empty($_SESSION['cart']) && is_array($_SESSION['cart'])){
        $cart_count = count($_SESSION['cart']);
      }
    }
  } else {
    // guest cart (session)
    if(!empty($_SESSION['cart']) && is_array($_SESSION['cart'])){
      $cart_count = count($_SESSION['cart']);
    }
  }
  // Add search support: when ?q=... is provided, return only matching DB items
  $q = trim((string)($_GET['q'] ?? ''));
  $all_products = [];
  if($q !== ''){
    try{
      $sth = $pdo->prepare('SELECT * FROM menu_items WHERE name LIKE :q OR category LIKE :q ORDER BY id DESC');
      $like = "%" . $q . "%";
      $sth->execute([':q'=>$like]);
      $all_products = $sth->fetchAll(PDO::FETCH_ASSOC);
    }catch(Exception $e){
      $all_products = [];
    }
  } else {
    // fetch all products
    try{
      $stmt = $pdo->query('SELECT * FROM menu_items ORDER BY id DESC');
      $all_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }catch(Exception $e){
      $all_products = [];
    }
  }

  ?>
  <div class="app">
    <!-- Header -->
    <header>
      <a href="index.php" class="brand" style="text-decoration:none;color:inherit;">
        <div class="logo">FP</div>
        <div>
          <h1><?= htmlspecialchars($site_name) ?></h1>
          <p class="lead">Feel that you are in Japan</p>
          <?php if(!empty($_SESSION['user_name'])): ?>
            <div class="welcome small" style="margin-top:4px;">Welcome, <?= htmlspecialchars($_SESSION['user_name']) ?></div>
          <?php endif; ?>
        </div>
      </a>

      <div class="actions">
        <form method="get" action="index.php" style="display:inline-block;">
          <input name="q" class="search" placeholder="Search meals, e.g. sushi" value="<?= htmlspecialchars($q ?? '') ?>" />
        </form>

        <!-- Cart icon (AJAX modal) -->
        <button id="cartBtn" class="icon-btn" title="Go to Cart">🛒<span id="cart-count" class="cart-count"><?= (int)$cart_count ?></span></button>
        <!-- My Orders button -->
        <button id="myOrdersBtn" class="icon-btn" title="My Orders">📦</button>

        <!-- Account button: opens AJAX-loaded menu (Account management, Transactions, Sign out) -->
        <div style="display:inline-block;position:relative">
          <button id="accountBtn" class="icon-btn" title="Account">Account ▾</button>
          <div id="accountMenu" style="display:none;position:absolute;right:0;top:calc(100% + 6px);min-width:180px;background:#fff;border:1px solid #ddd;box-shadow:0 8px 24px rgba(0,0,0,0.12);border-radius:6px;z-index:12000;padding:6px;">
            <div class="small text-muted" style="padding:8px;"></div>
          </div>
        </div>

      </div>
    </header>

    <!-- Page Title Section -->
    <section class="page-title">
      <h2><?= htmlspecialchars($site_name) ?></h2>
      <p class="subtitle">Discover the best Japanese meals!</p>
    </section>

    <?php if(!empty($q)): ?>
      <section class="page-title search-summary">
        <p class="subtitle">Search results for: <strong><?= htmlspecialchars($q) ?></strong></p>
      </section>
    <?php endif; ?>


  <main class="products-container">
    <?php if(!empty($all_products)): ?>
      <section class="products-section" id="all-products">
        <h3 class="section-title">All Products 🍣</h3>
        <div class="menu row gx-4 gy-4" id="all-menu">
          <?php foreach($all_products as $item):
            if(empty($item['name'])) continue;
            $avg = ($item['rating_count'] ? round($item['rating_total'] / $item['rating_count'],2) : 0);
          ?>
            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
              <div class="product-card h-100" style="cursor:pointer;" onclick="showProductDetail(<?= (int)$item['id'] ?>)">
                <?php if(!empty($item['image'])): ?><img src="<?= htmlspecialchars($item['image']) ?>" class="card-img-top" alt="<?= htmlspecialchars($item['name']) ?>"><?php else: ?><div class="card-img-top" style="display:flex;align-items:center;justify-content:center;height:160px;color:#999">No image</div><?php endif; ?>
                <div class="product-card__body p-3">
                  <div class="d-flex justify-content-between align-items-start mb-2">
                    <div class="badge bg-light text-warning small" style="color:#F07B3F;background:#FFF5EB;border-radius:6px;padding:6px 8px;font-weight:700"><?= htmlspecialchars($item['category']) ?></div>
                    <div class="small text-muted">🛒 <?= (int)$item['buy_count'] ?></div>
                  </div>
                  <h5 class="card-title mb-1" style="font-size:16px;font-weight:700"><?= htmlspecialchars($item['name']) ?></h5>
                  <div class="d-flex align-items-center mb-2">
                    <div class="me-2 text-warning" style="font-size:14px;"><?php for($i=0;$i<5;$i++){ echo ($i < floor($avg)) ? '★' : '☆'; } ?></div>
                    <div class="small text-muted"><?= $avg ?> (<?= (int)$item['rating_count'] ?>)</div>
                  </div>
                  <div class="d-flex justify-content-between align-items-center mt-auto">
                    <div class="price" style="color:#EE6F57;font-weight:800;font-size:18px">$<?= number_format($item['price'],2) ?></div>
                    <button class="btn btn-sm add-btn" onclick="event.stopPropagation(); addToCart(<?= (int)$item['id'] ?>, this)">Add to Cart</button>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php else: ?>
      <div class="no-products text-center" style="padding:36px;">
        <p class="lead">No menu items available right now.</p>
      </div>
    <?php endif; ?>
  </main>

  <!-- Product Detail Modal -->
  <div id="productModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center; overflow:auto;">
    <div style="background:#fff; max-width:600px; width:92%; margin:20px auto; border-radius:12px; overflow:hidden;">
      <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid #eee;">
        <h4 id="modalProductTitle" style="margin:0;">Product Details</h4>
        <button id="modalProductClose" style="background:none; border:none; font-size:24px; cursor:pointer; color:#999;">×</button>
      </div>
      <div style="padding:20px; max-height:70vh; overflow-y:auto;">
        <div id="modalProductBody">
          <div style="text-align:center; padding:40px; color:#666;">Loading...</div>
        </div>
      </div>
    </div>
  </div>

    <footer>
      Built as a demo ordering UI • Colors: #FAFAFA, #E3E3E3, #EE6F57, #CB3737
    </footer>
  </div>

  <!-- External JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/ajax_cart_orders.js"></script>
  <style>
    /* Small improvements for AJAX modal content */
    .ajax-modal .modal-body { max-height: 60vh; overflow:auto; }
    .ajax-spinner { font-size:14px;color:#666;padding:8px }
    .order-card, .cart-row { transition: box-shadow .18s ease, transform .18s ease; }
    .order-card:hover, .cart-row:hover { box-shadow: 0 12px 30px rgba(0,0,0,0.06); transform: translateY(-2px); }
  </style>
  <script>
  document.addEventListener('DOMContentLoaded', function(){
    // Wire header buttons only if external ajax handler not present
    if(!window.hasAjaxCartOrdersScript){
      const cartBtn = document.getElementById('cartBtn'); if(cartBtn) cartBtn.addEventListener('click', function(){ window.loadCart && window.loadCart(); });
      const myOrdersBtn = document.getElementById('myOrdersBtn'); if(myOrdersBtn) myOrdersBtn.addEventListener('click', function(){ window.loadMyOrders && window.loadMyOrders(); });
    }
    // Fallback addToCart (keeps existing inline onclick working if external script isn't loaded)
    if(!window.addToCart){
      window.addToCart = function(id, btn){ try{ if(btn) btn.disabled = true; }catch(e){} const form = new URLSearchParams(); form.append('id', id); form.append('qty', 1); fetch('add_to_cart.php', { method: 'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: form.toString() }).then(r=>r.json()).then(j=>{ if(j.ok){ const el = document.getElementById('cart-count'); if(el) el.textContent = (j.count || 0); if(btn){ const prev = btn.textContent; btn.textContent = 'Added ✓'; setTimeout(()=>btn.textContent = prev, 900); } } else { alert(j.error || 'Add to cart failed'); } }).catch(()=> alert('Network')).finally(()=> { try{ if(btn) btn.disabled = false; }catch(e){} }); };
    }
    
    // Product detail modal handlers
    const productModal = document.getElementById('productModal');
    const modalClose = document.getElementById('modalProductClose');
    modalClose.addEventListener('click', () => { productModal.style.display = 'none'; });
    productModal.addEventListener('click', (e) => { if(e.target === productModal) productModal.style.display = 'none'; });
    
    // Show product detail modal
    window.showProductDetail = function(productId){
      const modalBody = document.getElementById('modalProductBody');
      modalBody.innerHTML = '<div style="text-align:center; padding:40px; color:#666;">Loading...</div>';
      productModal.style.display = 'flex';
      
      fetch('get_product.php?product=' + encodeURIComponent(productId))
        .then(r => r.json())
        .then(data => {
          if(data && data.product){
            const p = data.product;
            const avg = p.rating_count > 0 ? (p.rating_total / p.rating_count).toFixed(1) : 0;
            const stars = Array.from({length:5}, (_, i) => i < Math.floor(avg) ? '★' : '☆').join('');
            
            let html = '<div style="display:flex; flex-direction:column; gap:16px;">';
            // Product image
            if(p.image){
              html += '<img src="' + p.image + '" style="width:100%; height:300px; object-fit:cover; border-radius:8px; background:#f5f5f5;" />';
            } else {
              html += '<div style="width:100%; height:300px; background:#f5f5f5; border-radius:8px; display:flex; align-items:center; justify-content:center; color:#999;">No image</div>';
            }
            
            // Title and category
            html += '<div>';
            html += '<div style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">';
            html += '<span class="badge" style="background:#FFF5EB; color:#F07B3F; font-weight:700; padding:6px 10px; border-radius:6px;">' + (p.category || 'Uncategorized') + '</span>';
            html += '<span style="font-size:14px; color:#999;">🛒 ' + (p.buy_count || 0) + ' orders</span>';
            html += '</div>';
            html += '<h3 style="margin:0 0 8px 0; font-size:24px; font-weight:700;">' + p.name + '</h3>';
            html += '</div>';
            
            // Price
            html += '<div style="font-size:28px; font-weight:700; color:#EE6F57;">$' + parseFloat(p.price).toFixed(2) + '</div>';
            
            // Rating
            html += '<div style="display:flex; align-items:center; gap:8px; padding:12px; background:#FFF9F5; border-radius:8px;">';
            html += '<div style="font-size:20px; color:#F07B3F;">' + stars + '</div>';
            html += '<div style="font-size:16px; font-weight:700;">' + avg + '</div>';
            html += '<div style="font-size:14px; color:#999;">(' + (p.rating_count || 0) + ' ratings)</div>';
            html += '</div>';
            
            // Ratings section
            if (data.ratings && data.ratings.length > 0) {
              html += '<div style="margin-top:20px; border-top:1px solid #eee; padding-top:16px;">';
              html += '<div style="font-size:16px; font-weight:700; margin-bottom:12px;">Customer Ratings</div>';
              data.ratings.forEach(function(rating) {
                var ratingDate = new Date(rating.created_at);
                var now = new Date();
                var diffTime = Math.abs(now - ratingDate);
                var diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                var dateStr = diffDays === 0 ? 'Today' : (diffDays === 1 ? 'Yesterday' : diffDays + ' days ago');
                
                var ratingStars = '';
                for (var i = 0; i < 5; i++) {
                  ratingStars += i < rating.rating ? '★' : '☆';
                }
                
                html += '<div style="padding:12px; background:#f9f9f9; border-radius:6px; margin-bottom:8px; font-size:13px;">';
                html += '<div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:6px;">';
                html += '<span style="font-weight:600; color:#222;">' + rating.name + '</span>';
                html += '<span style="color:#999; font-size:12px;">' + dateStr + '</span>';
                html += '</div>';
                html += '<div style="color:#F07B3F; font-size:16px; letter-spacing:2px; margin-bottom:4px;">' + ratingStars + '</div>';
                html += '</div>';
              });
              html += '</div>';
            } else {
              html += '<div style="margin-top:20px; border-top:1px solid #eee; padding-top:16px; text-align:center; color:#999; font-size:14px;">No ratings yet</div>';
            }
            
            // Add to cart button
            html += '<button class="btn btn-primary" style="width:100%; padding:12px; font-size:16px; font-weight:700; margin-top:16px;" onclick="event.stopPropagation(); addToCart(' + p.id + ', this); document.getElementById(\'productModal\').style.display=\'none\';">Add to Cart</button>';
            
            html += '</div>';
            modalBody.innerHTML = html;
          } else {
            modalBody.innerHTML = '<div style="text-align:center; padding:40px; color:#c33;">Product not found</div>';
          }
        })
        .catch(err => {
          modalBody.innerHTML = '<div style="text-align:center; padding:40px; color:#c33;">Error loading product</div>';
        });
    };
  });
  </script>

</div>
</body>
</html>
