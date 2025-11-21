<?php
// register_rider.php - create rider account and log them in
session_start();
require_once __DIR__ . '/../db.php';

$errors = [];
$success = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
        $errors[] = 'Please enter a valid email address.';
    }
    if(strlen($password) < 6){
        $errors[] = 'Password must be at least 6 characters.';
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
                // insert with role 'rider'
                $stmt = $pdo->prepare('INSERT INTO users (name,email,password,role,status) VALUES (?,?,?,?,?)');
                $stmt->execute([$name ?: null, $email, $hash, 'rider', 'active']);
                $uid = (int)$pdo->lastInsertId();

                // populate session from DB (ensure we rely on stored data)
                $sth = $pdo->prepare('SELECT id,name,email,role FROM users WHERE id = :id LIMIT 1');
                $sth->execute([':id'=>$uid]);
                $user = $sth->fetch(PDO::FETCH_ASSOC);
                if($user){
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_name'] = $user['name'] ?: $user['email'];
                    // redirect rider to rider panel
                    header('Location: ../rider_panel.php');
                    exit;
                }
                $success = 'Rider account created.';
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
  <title>Register Rider — Japan Food Trip</title>
  <link rel="stylesheet" href="../css/design.css">
  <link rel="stylesheet" href="../css/login.css">
</head>
<body>
  <div class="app">
    <header>
      <div class="brand">
        <div class="logo">FP</div>
        <div>
          <h1>Japan Food Trip</h1>
          <p class="lead">Create a rider account</p>
        </div>
      </div>
      <div class="actions">
        <a href="../index.php" class="icon-btn" title="Back to Menu">⬅ Menu</a>
      </div>
    </header>

  <main class="login-container" style="padding:18px;">
    <div class="login-card">
      <h2>Create Rider Account</h2>
      <p class="subtitle">Sign up to become a rider</p>

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
        <input type="text" name="name" placeholder="Full name" value="<?=htmlspecialchars($_POST['name'] ?? '')?>" class="form-control" style="margin-bottom:8px;">
        <input type="email" name="email" placeholder="Email" required value="<?=htmlspecialchars($_POST['email'] ?? '')?>" class="form-control" style="margin-bottom:8px;">
        <input type="password" name="password" placeholder="Password (min 6 chars)" required class="form-control" style="margin-bottom:8px;">
        <div style="display:flex;gap:8px;margin-top:8px;">
          <button class="btn btn-primary" type="submit">Create rider account</button>
          <a class="btn btn-outline-secondary" href="../index.php">Cancel</a>
        </div>
      </form>

      <div class="login-links" style="margin-top:12px;"><a href="login.php">Already have an account? Log in</a></div>
    </div>
  </main>

    <footer>
      Built as a demo ordering UI • Colors: #FAFAFA, #E3E3E3, #EE6F57, #CB3737
    </footer>
  </div>
</body>
</html>
