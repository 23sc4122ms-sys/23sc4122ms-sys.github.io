<?php
session_start();
require_once __DIR__ . '/../db.php';
$pdo = getPDO();

// Determine cart source: DB `user_cart` when logged in, otherwise session
$cart = [];
$items = [];
$total = 0.0;
$cart_count = 0;
if(!empty($_SESSION['user_id'])){
  $uid = (int)$_SESSION['user_id'];
  try{
    $cstmt = $pdo->prepare('SELECT menu_item_id, quantity FROM user_cart WHERE user_id = :uid');
    $cstmt->execute([':uid'=>$uid]);
    $rows = $cstmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r) $cart[(int)$r['menu_item_id']] = (int)$r['quantity'];
    // count
    $cart_count = array_sum(array_map('intval', $cart));
  }catch(Exception $e){
    // fallback to session
    $cart = $_SESSION['cart'] ?? [];
    if(is_array($cart)) $cart_count = array_sum(array_map('intval', $cart));
  }
} else {
  $cart = $_SESSION['cart'] ?? [];
  if(is_array($cart)) $cart_count = array_sum(array_map('intval', $cart));
}

// load product rows if cart not empty
if(!empty($cart)){
  $ids = array_map('intval', array_keys($cart));
  $place = implode(',', $ids);
  $stmt = $pdo->query("SELECT * FROM menu_items WHERE id IN ($place)");
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows as $r) $items[$r['id']] = $r;
  foreach($cart as $id => $qty){
    if(isset($items[$id])){
      $total += $items[$id]['price'] * $qty;
    }
  }
}

// handle updates (qty changes or remove)
if($_SERVER['REQUEST_METHOD'] === 'POST'){
  if(isset($_POST['action']) && $_POST['action'] === 'update'){
    // update quantities
    $new = [];
    foreach($_POST['qty'] as $id => $q){
      $qid = (int)$id; $qq = (int)$q; if($qq < 1) continue;
      $new[$qid] = $qq;
    }
    if(!empty($_SESSION['user_id'])){
      // persist to DB
      $uid = (int)$_SESSION['user_id'];
      try{
        // delete existing entries, then re-insert new ones (simple approach)
        $d = $pdo->prepare('DELETE FROM user_cart WHERE user_id = :uid');
        $d->execute([':uid'=>$uid]);
        $ins = $pdo->prepare('INSERT INTO user_cart (user_id, menu_item_id, quantity, created_at, updated_at) VALUES (:uid,:mid,:q,NOW(),NOW())');
        foreach($new as $mid => $qq){
          $ins->execute([':uid'=>$uid, ':mid'=>$mid, ':q'=>$qq]);
        }
      }catch(Exception $e){ /* ignore DB errors for now */ }
      header('Location: cart.php'); exit;
    } else {
      $_SESSION['cart'] = $new;
      header('Location: cart.php'); exit;
    }
  }
}
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Cart</title><link rel="stylesheet" href="../css/design.css"></head><body>
<div class="app">
  <!-- Header (copied from index.php) -->
  <header>
    <div class="brand">
      <a href="../index.php" style="text-decoration:none;color:inherit;">
        <div class="logo">FP</div>
      </a>
      <div>
        <h1><a href="../index.php" style="text-decoration:none;color:inherit;">Japan Food Trip</a></h1>
        <p class="lead">Feel that you are in Japan</p>
      </div>
    </div>

    <div class="actions">
      <form method="get" action="../index.php" style="display:inline-block;">
        <input name="q" class="search" placeholder="Search meals, e.g. sushi" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" />
      </form>

      <!-- Cart icon links to cart page -->
      <a href="cart.php" class="icon-btn" title="Go to Cart">
        🛒<span id="cart-count" class="cart-count"><?= (int)$cart_count ?></span>
      </a>

      <a href="login.php" class="icon-btn" title="Account">Account</a>

    </div>
  </header>

  <div class="container" style="padding:24px;">
    <h2>Your Cart</h2>
  <?php if(empty($cart)): ?>
    <p>Your cart is empty. Go back to <a href="/JapanFoodOrder/index.php">menu</a>.</p>
  <?php else: ?>
    <form method="post">
      <input type="hidden" name="action" value="update">
      <table class="table">
        <thead><tr><th>Item</th><th>Price</th><th>Qty</th><th>Subtotal</th></tr></thead>
        <tbody>
        <?php foreach($cart as $id => $qty): if(!isset($items[$id])) continue; $it = $items[$id]; ?>
          <tr>
            <td>
              <?php if(!empty($it['image'])): ?>
                <img src="<?= htmlspecialchars('../' . $it['image']) ?>" alt="" style="height:48px;width:auto;margin-right:8px;border-radius:6px;vertical-align:middle">
              <?php endif; ?>
              <?= htmlspecialchars($it['name']) ?>
            </td>
            <td>$<?= number_format($it['price'],2) ?></td>
            <td><input type="number" name="qty[<?= (int)$id ?>]" value="<?= (int)$qty ?>" min="1" style="width:72px"></td>
            <td>$<?= number_format($it['price'] * $qty,2) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div style="margin-top:12px;">Total: <strong>$<?= number_format($total,2) ?></strong></div>
      <div style="margin-top:12px; display:flex; gap:8px; align-items:center;">
        <button class="btn btn-primary" formaction="../checkout.php" formmethod="post">Checkout</button>
        <button class="btn btn-secondary" type="submit">Update quantities</button>
        <a class="btn btn-outline-info" href="../order_progress.php">View Order Progress</a>
      </div>
    </form>
  <?php endif; ?>
  </div>

  <footer>
    Built as a demo ordering UI • Colors: #FAFAFA, #E3E3E3, #EE6F57, #CB3737
  </footer>
</div>
</body></html>
