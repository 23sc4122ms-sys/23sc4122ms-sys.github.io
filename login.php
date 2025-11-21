<?php
session_start();
require_once __DIR__ . '/../db.php';
$pdo = getPDO();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim((string)($_POST['email'] ?? ''));
  $password = trim((string)($_POST['password'] ?? ''));
  if ($email === '' || $password === '') {
    $error = 'Email and password are required.';
  } else {
    try {
      $sth = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
      $sth->execute([':email' => $email]);
      $user = $sth->fetch(PDO::FETCH_ASSOC);
      if ($user && password_verify($password, $user['password'])) {
        // Login success: set session and redirect depending on role
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role'] = $user['role'] ?? 'customer';
        // store a display name for welcome messages
        $_SESSION['user_name'] = $user['name'] ?: $user['email'];
        // role-based redirects
        $role = strtolower($_SESSION['user_role']);
        if ($role === 'admin') {
          header('Location: ../admin_dashboard.php');
        } elseif ($role === 'rider') {
          header('Location: ../rider_panel.php');
        } else {
          header('Location: ../index.php');
        }
        exit;
      } else {
        $error = 'Invalid email or password.';
      }
    } catch (Exception $e) {
      $error = 'Login failed.';
      // error_log($e->getMessage());
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Login — Japan Food Trip</title>
  <link rel="stylesheet" href="../css/design.css">
  <link rel="stylesheet" href="../css/login.css">
  <style>
    header{ display:flex; justify-content:space-between; align-items:center; padding:18px 16px; border-bottom:1px solid rgba(0,0,0,0.06); background:#EE6F57; color:#fff; }
    header .brand{ display:flex; gap:12px; align-items:center; text-decoration:none; color:inherit; }
    header .logo{ width:48px; height:48px; background:#fff; color:#EE6F57; display:flex; align-items:center; justify-content:center; border-radius:8px; font-weight:800; font-size:18px; }
    .actions{ display:flex; gap:10px; align-items:center; }
    .icon-btn{ background:transparent; border:0; font-size:18px; cursor:pointer; position:relative; color:#fff; }
  </style>
</head>
<body>
  <div class="app">
    <header>
      <a href="../index.php" class="brand" style="text-decoration:none;color:inherit;">
        <div class="logo">FP</div>
        <div>
          <h1>Japan Food Trip</h1>
          <p class="lead">Login to your account</p>
        </div>
      </a>
      <div class="actions">
        <button id="cartBtn" class="icon-btn" title="Go to Cart">🛒</button>
        <button id="myOrdersBtn" class="icon-btn" title="My Orders">📦</button>
        <a href="../index.php" class="icon-btn" title="Back to Menu">⬅ Menu</a>
      </div>
    </header>

    <main class="login-container">
      <div class="login-card">
        <h2>Welcome Back!</h2>
        <p class="subtitle">Sign in to continue your food journey 🍣</p>

        <?php if ($error): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php">
          <input type="email" name="email" placeholder="Email" id="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
          <input type="password" name="password" placeholder="Password" id="password" required>
          <div style="display:flex;gap:8px;margin-top:12px;">
            <button type="submit" id="login-btn">Login</button>
            <a href="register.php" class="btn" style="display:inline-block;padding:8px 12px;text-decoration:none;">Register</a>
          </div>
        </form>

        <div class="login-links" style="margin-top:12px;">
          <a href="#">Forgot Password?</a>
        </div>
      </div>
    </main>

    <footer>
      Built as a demo ordering UI • Colors: #FAFAFA, #E3E3E3, #EE6F57, #CB3737
    </footer>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../js/ajax_cart_orders.js"></script>
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
