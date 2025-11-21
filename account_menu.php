<?php
session_start();
// Simple fragment returned via AJAX for the account dropdown menu
header('Content-Type: text/html; charset=utf-8');
$user_name = isset($_SESSION['user_name']) ? htmlspecialchars($_SESSION['user_name']) : null;
$logged_in = !empty($_SESSION['user_id']);

// Determine account management link
$account_link = $logged_in ? 'rider_profile.php' : 'main/login.php';

?>
<?php if($logged_in): ?>
  <div style="padding:8px 6px;border-bottom:1px solid #f2f2f2;">
    <div style="font-weight:700;color:#000;"><?php echo $user_name ?></div>
    <div class="small text-muted" style="margin-top:4px;">Manage your account</div>
  </div>
  <div style="display:flex;flex-direction:column;">
    <a href="#" data-action="account" data-url="customer_account_management.php" style="padding:8px 10px;text-decoration:none;color:#222;border-radius:4px;">Account management</a>
    <a href="#" data-action="transactions" style="padding:8px 10px;text-decoration:none;color:#222;border-radius:4px;">Transactions</a>
    <a href="#" data-action="signout" style="padding:8px 10px;text-decoration:none;color:#c33;border-radius:4px;">Sign out</a>
  </div>
<?php else: ?>
  <div style="padding:8px 6px;border-bottom:1px solid #f2f2f2;">
    <div style="font-weight:700;color:#000;">Guest</div>
    <div class="small text-muted" style="margin-top:4px;">Please sign in or create an account</div>
  </div>
  <div style="display:flex;flex-direction:column;">
    <a href="main/login.php" style="padding:8px 10px;text-decoration:none;color:#222;border-radius:4px;">Sign in</a>
    <a href="main/register.php" style="padding:8px 10px;text-decoration:none;color:#222;border-radius:4px;">Create account</a>
    <a href="main/register_rider.php" style="padding:8px 10px;text-decoration:none;color:#222;border-radius:4px;">Create rider account</a>
  </div>
<?php endif; ?>
