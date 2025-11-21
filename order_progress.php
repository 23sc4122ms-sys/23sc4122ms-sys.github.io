<?php
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();
$sid = session_id();
// show orders for logged-in user, otherwise for this session
if(!empty($_SESSION['user_id'])){
  $stmt = $pdo->prepare('SELECT * FROM orders WHERE user_id = :uid ORDER BY created_at DESC');
  $stmt->execute([':uid'=>(int)$_SESSION['user_id']]);
} else {
  $stmt = $pdo->prepare('SELECT * FROM orders WHERE session_id = :sid ORDER BY created_at DESC');
  $stmt->execute([':sid'=>$sid]);
}
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
// load items for these orders
$items = [];
if($orders){
    $ids = array_column($orders, 'id');
    $place = implode(',', array_map('intval', $ids));
    $s2 = $pdo->query("SELECT * FROM order_items WHERE order_id IN ($place) ORDER BY created_at DESC");
    $rows = $s2->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r) $items[$r['order_id']][] = $r;
}

// header variables (cart count: prefer DB per-user cart when logged in)
$cart_count = 0;
if(!empty($_SESSION['user_id'])){
  try{
    $cstmt = $pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM user_cart WHERE user_id = :uid');
    $cstmt->execute([':uid'=>(int)$_SESSION['user_id']]);
    $cart_count = (int)$cstmt->fetchColumn();
  }catch(Exception $e){
    if(!empty($_SESSION['cart']) && is_array($_SESSION['cart'])){
      $cart_count = array_sum(array_map('intval', $_SESSION['cart']));
    }
  }
} else {
  if(!empty($_SESSION['cart']) && is_array($_SESSION['cart'])){
    $cart_count = array_sum(array_map('intval', $_SESSION['cart']));
  }
}
$q = trim((string)($_GET['q'] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Food Ordering — Demo</title>
  <link rel="stylesheet" href="css/design.css">
</head>
<body>
  <div class="app">
    <!-- Header (from index.php) -->
    <header>
      <a href="index.php" class="brand" style="text-decoration:none;color:inherit;">
        <div class="logo">FP</div>
        <div>
          <h1>Japan Food Trip</h1>
          <p class="lead">Feel that you are in Japan</p>
        </div>
      </a>

      <div class="actions">
        <form method="get" action="order_progress.php" style="display:inline-block;">
          <input name="q" class="search" placeholder="Search meals, e.g. sushi" value="<?= htmlspecialchars($q ?? '') ?>" />
        </form>

        <!-- Cart icon links to cart page -->
        <a href="main/cart.php" class="icon-btn" title="Go to Cart">
          🛒<span id="cart-count" class="cart-count"><?= (int)$cart_count ?></span>
        </a>

        <button class="icon-btn" title="Account">
          <a href="main/login.html" class="icon-btn" title="Back to Menu">Account</a>
        </button>
      </div>
    </header>

<div class="container" style="padding:24px;">
  <h2>Your Orders</h2>
  <?php if(empty($orders)): ?>
    <p>No orders found. After checkout, you'll see your order(s) here.</p>
  <?php else: foreach($orders as $o): ?>
    <div class="card p-3 mb-3">
      <div><strong>Order #<?= (int)$o['id'] ?></strong> — <?= htmlspecialchars($o['created_at']) ?></div>
      <div>Total: $<?= number_format($o['total'],2) ?></div>
      <div style="margin-top:8px;">
        <table class="table">
          <thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach($items[$o['id']] ?? [] as $it): ?>
            <tr>
              <td><?= htmlspecialchars($it['product_name']) ?></td>
              <td><?= (int)$it['quantity'] ?></td>
              <td>$<?= number_format($it['price'],2) ?></td>
              <td><?= htmlspecialchars($it['status']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>

    <!-- Footer (from index.php) -->
    <footer>
      Built as a demo ordering UI • Colors: #FAFAFA, #E3E3E3, #EE6F57, #CB3737
    </footer>
  </div>

  <!-- Add to cart script (from index.php) -->
  <script>
  // Add to cart via AJAX and update header count
  function addToCart(id, btn){
    try{ btn.disabled = true; }catch(e){}
    const form = new URLSearchParams(); form.append('id', id); form.append('qty', 1);
    fetch('add_to_cart.php', { method: 'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: form.toString() })
      .then(r=>r.json()).then(j=>{
        if(j.ok){
          const el = document.getElementById('cart-count');
          if(el) el.textContent = (j.count || 0);
          // button feedback
          if(btn){ const prev = btn.textContent; btn.textContent = 'Added ✓'; setTimeout(()=>btn.textContent = prev, 1000); }
        } else {
          alert(j.error || 'Add to cart failed');
        }
      }).catch(()=> alert('Network')) .finally(()=> { try{ if(btn) btn.disabled = false; }catch(e){} });
  }
  </script>

</body>
</html>
