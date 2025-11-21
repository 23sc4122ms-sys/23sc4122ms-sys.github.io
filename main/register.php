<?php
// register.php - create customer account
session_start();
// require the project root db.php (was incorrectly requiring main/db.php)
require_once __DIR__ . '/../db.php';
$pdo = getPDO();

$errors = [];
$success = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    // do NOT accept role from client; registration role is always 'customer'

    if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
        $errors[] = 'Please enter a valid email address.';
    }
    if(strlen($password) < 6){
        $errors[] = 'Password must be at least 6 characters.';
    }

    if(empty($errors)){
        try{
            // check duplicate email
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            if($stmt->fetch()){
                $errors[] = 'An account with that email already exists.';
            }else{
                $hash = password_hash($password, PASSWORD_DEFAULT);
                // explicitly insert literal 'customer' as role to prevent client control
                $stmt = $pdo->prepare('INSERT INTO users (name,email,password,role,status) VALUES (?,?,?,?,?)');
                $stmt->execute([$name ?: null, $email, $hash, 'customer', 'active']);
                $success = 'Account created successfully. You can now log in.';
            }
        }catch(Exception $e){
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Register — Japan Food Trip</title>
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
          <p class="lead">Create your account</p>
        </div>
      </a>
      <div class="actions">
        <button id="cartBtn" class="icon-btn" title="Go to Cart">🛒</button>
        <button id="myOrdersBtn" class="icon-btn" title="My Orders">📦</button>
        <a href="../index.php" class="icon-btn" title="Back to Menu">⬅ Menu</a>
      </div>
    </header>

  <!-- ...existing code (registration container) ... -->
  <main class="login-container">
    <div class="login-card">
      <h2>Create Account</h2>
      <p class="subtitle">Sign up to start ordering delicious Japanese meals 🍣</p>

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
        <input type="text" name="name" placeholder="Full name (optional)" value="<?=htmlspecialchars($_POST['name'] ?? '')?>" class="form-control" style="margin-bottom:8px;">
        <input type="email" name="email" placeholder="Email" required value="<?=htmlspecialchars($_POST['email'] ?? '')?>" class="form-control" style="margin-bottom:8px;">
        <input type="password" name="password" placeholder="Password (min 6 chars)" required class="form-control" style="margin-bottom:8px;">
        <div style="display:flex;gap:8px;margin-top:8px;">
          <button class="btn btn-primary" type="submit">Create account</button>
          <a class="btn btn-outline-secondary" href="../index.php">Cancel</a>
        </div>
      </form>

      <div class="login-links" style="margin-top:12px;">
        <a href="login.php">Already have an account? Log in</a>
      </div>
    </div>
  </main>

    <!-- Footer copied from main/login.php -->
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
