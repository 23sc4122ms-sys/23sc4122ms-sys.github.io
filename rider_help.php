<?php
// Rider help / FAQ (shows support contact from settings)
require_once __DIR__ . '/db.php';
$pdo = getPDO();
$contact_email = 'support@example.com';
try{
  $sth = $pdo->prepare('SELECT v FROM settings WHERE k = :k LIMIT 1');
  $sth->execute([':k'=>'contact_email']);
  $v = $sth->fetchColumn();
  if($v !== false && strlen(trim((string)$v))>0) $contact_email = $v;
}catch(Exception $e){ /* ignore - use fallback */ }
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Help</h2>
    <small class="text-muted">Support & FAQ</small>
  </div>

  <div class="card p-3">
    <h6>How to accept a delivery</h6>
    <p>Tap Start/Accept on the Deliveries page to begin. Use the Complete button when finished.</p>
    <h6>Contact support</h6>
    <p>Email: <a href="mailto:<?php echo htmlspecialchars($contact_email) ?>"><?php echo htmlspecialchars($contact_email) ?></a></p>
  </div>
</div>
