<?php
// register.php - create account for owner/admin/rider/customer
session_start();
require_once __DIR__ . '/db.php';

$errors = [];
$success = '';

// Allowed roles
$roles = ['owner','admin','rider','customer'];

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'customer';

    if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
        $errors[] = 'Please enter a valid email address.';
    }
    if(strlen($password) < 6){
        $errors[] = 'Password must be at least 6 characters.';
    }
    if(!in_array($role, $roles, true)){
        $errors[] = 'Invalid role selected.';
    }

    if(empty($errors)){
        try{
            $pdo = getPDO();
            // check duplicate email
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            if($stmt->fetch()){
                $errors[] = 'An account with that email already exists.';
            }else{
              $hash = password_hash($password, PASSWORD_DEFAULT);
              $stmt = $pdo->prepare('INSERT INTO users (name,email,password,role,status) VALUES (?,?,?,?,?)');
              $stmt->execute([$name ?: null, $email, $hash, $role, 'active']);
              // auto-login the newly created user: set session and redirect
              $newId = $pdo->lastInsertId();
              $_SESSION['user_id'] = (int)$newId;
              $_SESSION['user_role'] = $role;
              $_SESSION['user_name'] = $name ?: $email;
              // redirect based on role
              $r = strtolower($role);
              if($r === 'admin'){
                header('Location: admin_dashboard.php');
              } elseif($r === 'rider'){
                header('Location: rider_dashboard.php');
              } else {
                header('Location: index.php');
              }
              exit;
            }
        }catch(Exception $e){
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Register - Japan Food</title>
  <link rel="stylesheet" href="css/design.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <style>
    /* Small header tweaks (kept inline so register looks like index) */
    header{ display:flex; justify-content:space-between; align-items:center; padding:18px 16px; border-bottom:1px solid rgba(0,0,0,0.06); background:#EE6F57; color:#fff; }
    header .brand{ display:flex; gap:12px; align-items:center; text-decoration:none; color:inherit; }
    header .logo{ width:48px; height:48px; background:#fff; color:#EE6F57; display:flex; align-items:center; justify-content:center; border-radius:8px; font-weight:800; font-size:18px; }
    .actions{ display:flex; gap:10px; align-items:center; }
    .icon-btn{ background:transparent; border:0; font-size:18px; cursor:pointer; position:relative; color:#fff; }
    .cart-count{ position:absolute; top:-6px; right:-8px; background:#CB3737; color:#fff; border-radius:12px; padding:2px 6px; font-size:12px; }
  </style>
  
</head>
<body class="p-4">
  <?php
  // header needs site name and cart count like index.php
  $site_name = 'Japan Food Trip';
  $primary_color = '#EE6F57';
  try{
    $sth = $pdo->prepare('SELECT v FROM settings WHERE k = :k LIMIT 1');
    $sth->execute([':k' => 'site_name']);
    $v = $sth->fetchColumn(); if($v !== false && strlen(trim((string)$v)) > 0) $site_name = $v;
  }catch(Exception $e){ /* ignore */ }
  // compute cart count (session fallback)
  $cart_count = 0;
  if (!empty($_SESSION['user_id'])) {
    try { $sth = $pdo->prepare('SELECT COUNT(*) FROM user_cart WHERE user_id = :uid'); $sth->execute([':uid'=>(int)$_SESSION['user_id']]); $cart_count = (int)$sth->fetchColumn(); } catch(Exception $e){ if(!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) $cart_count = count($_SESSION['cart']); }
  } else { if(!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) $cart_count = count($_SESSION['cart']); }
  ?>

  <header>
    <a href="index.php" class="brand" style="text-decoration:none;color:inherit;">
      <div class="logo">FP</div>
      <div>
        <h1><?= htmlspecialchars($site_name) ?></h1>
        <p class="lead">Create your account</p>
      </div>
    </a>

    <div class="actions">
      <button id="cartBtn" class="icon-btn" title="Go to Cart">🛒<span id="cart-count" class="cart-count"><?= (int)$cart_count ?></span></button>
      <button id="myOrdersBtn" class="icon-btn" title="My Orders">📦</button>
      <div style="display:inline-block;position:relative">
        <button id="accountBtn" class="icon-btn" title="Account">Account ▾</button>
      </div>
    </div>
  </header>

  <div class="container" style="max-width:720px;margin-top:26px;">
    <div class="card p-4 shadow-sm">
      <h3>Create account</h3>
      <p class="small">Create an account for a Rider, Admin or Owner.</p>

      <?php if($errors): ?>
        <div class="alert alert-danger">
          <ul class="mb-0">
          <?php foreach($errors as $e): ?>
            <li><?=htmlspecialchars($e)?></li>
          <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if($success): ?>
        <div class="alert alert-success"><?=htmlspecialchars($success)?></div>
      <?php endif; ?>

      <form method="post" novalidate>
        <div class="mb-2">
          <label class="form-label">Full name (optional)</label>
          <input class="form-control" name="name" value="<?=htmlspecialchars($_POST['name'] ?? '')?>">
        </div>
        <div class="mb-2">
          <label class="form-label">Email</label>
          <input class="form-control" name="email" type="email" required value="<?=htmlspecialchars($_POST['email'] ?? '')?>">
        </div>
        <div class="mb-2">
          <label class="form-label">Password</label>
          <input class="form-control" name="password" type="password" required>
          <div class="small text-muted">Use at least 6 characters.</div>
        </div>
        <div class="mb-3">
          <label class="form-label">Role</label>
          <select class="form-select" name="role">
            <?php foreach($roles as $r): ?>
              <option value="<?=htmlspecialchars($r)?>" <?=((($_POST['role'] ?? '') === $r)? 'selected':'')?>><?=htmlspecialchars(ucfirst($r))?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="d-flex gap-2">
          <button class="btn btn-primary" type="submit">Create account</button>
          <a class="btn btn-outline-secondary" href="index.php">Cancel</a>
        </div>
      </form>
    </div>
  </div>
    <!-- Include external JS for AJAX cart/orders behavior like index.php -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/ajax_cart_orders.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function(){
        if(!window.hasAjaxCartOrdersScript){
          const cartBtn = document.getElementById('cartBtn'); if(cartBtn) cartBtn.addEventListener('click', function(){ window.loadCart && window.loadCart(); });
          const myOrdersBtn = document.getElementById('myOrdersBtn'); if(myOrdersBtn) myOrdersBtn.addEventListener('click', function(){ window.loadMyOrders && window.loadMyOrders(); });
        }
      });
    </script>
  </body>
  </html>
