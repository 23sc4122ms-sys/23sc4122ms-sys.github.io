<?php
// Fragment: Rider Order History
// This file is intended to be loaded into the `#mainContent` area via AJAX or included server-side.
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

// require rider role
if(empty($_SESSION['user_id']) || strtolower(($_SESSION['user_role'] ?? '')) !== 'rider'){
  echo '<div class="container py-3"><div class="alert alert-warning">Please sign in as a rider to view order history.</div></div>';
  return;
}
$rid = (int)$_SESSION['user_id'];

// Ensure deliveries table exists (basic schema used across app)
try{
  $pdo->exec("CREATE TABLE IF NOT EXISTS `deliveries` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id` INT UNSIGNED NOT NULL,
    `rider_id` INT UNSIGNED DEFAULT NULL,
    `status` VARCHAR(32) DEFAULT 'assigned',
    `assigned_at` DATETIME DEFAULT NULL,
    `picked_up_at` DATETIME DEFAULT NULL,
    `delivered_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `fee` DECIMAL(10,2) DEFAULT 0.00,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX (`order_id`),
    INDEX (`rider_id`),
    INDEX (`status`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}catch(Exception $e){ /* non-fatal */ }

// gather stats for this rider
$totalOrders = 0; $completed = 0; $totalEarnings = 0.0; $avgRating = 0.0;
try{
  $sth = $pdo->prepare('SELECT COUNT(*) FROM deliveries WHERE rider_id = :rid');
  $sth->execute([':rid'=>$rid]); $totalOrders = (int)$sth->fetchColumn();
}catch(Exception $e){ }
try{
  // Prefer authoritative completed_orders snapshot count for completed deliveries
  try{
    $sth = $pdo->prepare('SELECT COUNT(*) FROM completed_orders WHERE rider_id = :rid');
    $sth->execute([':rid'=>$rid]);
    $completed = (int)$sth->fetchColumn();
  }catch(Exception $ee){
    // fallback to deliveries table if completed_orders not present
    $sth = $pdo->prepare("SELECT COUNT(*) FROM deliveries WHERE rider_id = :rid AND LOWER(status) IN ('delivered','completed','confirmed')");
    $sth->execute([':rid'=>$rid]); $completed = (int)$sth->fetchColumn();
  }
}catch(Exception $e){ }
try{
  // Sum earnings from rider_earnings (preferred) otherwise fallback to deliveries.fee
  try{
    $sth = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM rider_earnings WHERE rider_id = :rid');
    $sth->execute([':rid'=>$rid]); $totalEarnings = (float)$sth->fetchColumn();
  }catch(Exception $ee){
    $sth = $pdo->prepare("SELECT COALESCE(SUM(fee),0) FROM deliveries WHERE rider_id = :rid AND LOWER(status) IN ('delivered','completed','confirmed')");
    $sth->execute([':rid'=>$rid]); $totalEarnings = (float)$sth->fetchColumn();
  }
}catch(Exception $e){ }

// fetch recent deliveries list
$recent = [];
try{
  $sth = $pdo->prepare("SELECT d.*, o.created_at AS order_created, COALESCE(u.name, CONCAT('Guest ', LEFT(o.session_id,8))) AS customer_name, u.address AS customer_address
    FROM deliveries d
    LEFT JOIN orders o ON o.id = d.order_id
    LEFT JOIN users u ON u.id = o.user_id
    WHERE d.rider_id = :rid
    ORDER BY COALESCE(d.delivered_at, d.completed_at, d.assigned_at, d.created_at) DESC
    LIMIT 50");
  $sth->execute([':rid'=>$rid]); $recent = $sth->fetchAll(PDO::FETCH_ASSOC);
}catch(Exception $e){ }

// fetch completed deliveries (for the separate Completed Orders table)
$completedList = [];
try{
  $sql = "SELECT d.id AS delivery_id, d.order_id, d.fee AS rider_fee, d.amount AS amount, COALESCE(d.delivery_bonus,0) AS delivery_bonus, COALESCE(o.total, (
              SELECT COALESCE(SUM(price*quantity),0) FROM order_items oi WHERE oi.order_id = o.id
            )) AS order_total, COALESCE(d.delivered_at, d.completed_at) AS completed_at, d.status
    FROM deliveries d
    LEFT JOIN orders o ON o.id = d.order_id
    WHERE d.rider_id = :rid AND LOWER(d.status) IN ('delivered','completed','confirmed')
    ORDER BY completed_at DESC
    LIMIT 200";
  $sth = $pdo->prepare($sql);
  $sth->execute([':rid'=>$rid]);
  $completedList = $sth->fetchAll(PDO::FETCH_ASSOC);

  // compute effective rider fee per row using payout rules (amount > 0 ? amount : baseRate) + delivery_bonus
  $baseRate = 5.00;
  try{
    $ps = $pdo->prepare("SELECT setting_value FROM payout_settings WHERE setting_key = 'delivery_base_rate' LIMIT 1");
    $ps->execute(); $val = $ps->fetchColumn();
    if($val !== false) $baseRate = (float)$val;
  }catch(Exception $ee){ /* ignore, keep default baseRate */ }

  foreach($completedList as &$row){
    $amount = isset($row['amount']) ? (float)$row['amount'] : 0.0;
    $bonus = isset($row['delivery_bonus']) ? (float)$row['delivery_bonus'] : 0.0;
    if($amount > 0){
      $computed = $amount + $bonus;
    }elseif(isset($row['rider_fee']) && (float)$row['rider_fee'] > 0){
      $computed = (float)$row['rider_fee'] + $bonus;
    }else{
      $computed = $baseRate + $bonus;
    }
    $row['computed_fee'] = $computed;
  }
  unset($row);

}catch(Exception $e){ /* non-fatal */ }

// load persisted earnings from rider_earnings for these orders (prefer these over computed value)
$earnedMap = [];
try{
  $orderIds = array_values(array_filter(array_map(function($r){ return isset($r['order_id']) ? (int)$r['order_id'] : null; }, $completedList)));
  $orderIds = array_values(array_unique(array_filter($orderIds)));
  if(count($orderIds) > 0){
    // robust: pick the latest rider_earnings.amount per order using MAX(created_at)
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $sql = "SELECT re.order_id, re.amount FROM rider_earnings re
             INNER JOIN (
               SELECT order_id, MAX(created_at) AS m FROM rider_earnings WHERE rider_id = ? AND order_id IN ($placeholders) GROUP BY order_id
             ) t ON re.order_id = t.order_id AND re.created_at = t.m
             WHERE re.rider_id = ?";
    $params = array_merge([$rid], $orderIds, [$rid]);
    $ers = $pdo->prepare($sql);
    $ers->execute($params);
    $rows = $ers->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $er){
      $oid = (int)$er['order_id'];
      $earnedMap[$oid] = (float)$er['amount'];
    }
  }
}catch(Exception $e){ /* ignore earnings fetch errors */ }

// If no deliveries-based completed rows were found, fallback to completed_orders snapshots
try{
  if(empty($completedList)){
    $sth = $pdo->prepare("SELECT co.order_id, co.rider_id, co.rider_fee AS computed_fee, COALESCE(o.total,0) AS order_total, co.completed_at, 'completed' AS status, NULL AS delivery_id
      FROM completed_orders co
      LEFT JOIN orders o ON o.id = co.order_id
      WHERE co.rider_id = :rid
      ORDER BY co.completed_at DESC
      LIMIT 200");
    $sth->execute([':rid'=>$rid]);
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    if($rows){
      $completedList = $rows;
      // convert numeric fields
      foreach($completedList as &$r){
        $r['order_total'] = $r['order_total'] ?? 0;
        $r['computed_fee'] = isset($r['computed_fee']) ? (float)$r['computed_fee'] : 0.0;
      }
      unset($r);
    }
  }
}catch(Exception $e){ /* ignore fallback errors */ }

// test query for recent earnings (debugging)
try{
  $sth = $pdo->prepare("SELECT id, rider_id, order_id, amount, source, created_at
FROM rider_earnings
WHERE rider_id = YOUR_RIDER_ID
ORDER BY created_at DESC
LIMIT 50;

SELECT COALESCE(SUM(amount),0) as total FROM rider_earnings WHERE rider_id = YOUR_RIDER_ID;
SELECT COUNT(*) FROM completed_orders WHERE rider_id = YOUR_RIDER_ID;");
  $sth->execute();
  $recentEarnings = $sth->fetchAll(PDO::FETCH_ASSOC);
}catch(Exception $e){ $recentEarnings = []; }

// test query for recent completed orders (debugging)
try{
  $sth = $pdo->prepare("SELECT id, order_id, rider_id, rider_fee, created_at FROM completed_orders ORDER BY created_at DESC LIMIT 10");
  $sth->execute();
  $recentCompleted = $sth->fetchAll(PDO::FETCH_ASSOC);
}catch(Exception $e){ $recentCompleted = []; }

?>
<div class="container-fluid">
  <div class="mb-4">
    <h3 class="mb-0">Order History</h3>
    <p class="text-muted">Track all your completed and cancelled deliveries</p>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-sm-6 col-md-3">
      <div class="card p-3 shadow-sm">
        <small class="text-muted">Total Orders</small>
        <h4 class="mt-2"><?php echo htmlspecialchars($totalOrders); ?></h4>
        <small class="text-muted">All time</small>
      </div>
    </div>
    <div class="col-sm-6 col-md-3">
      <div class="card p-3 shadow-sm">
        <small class="text-muted">Completed</small>
        <h4 class="mt-2"><?php echo htmlspecialchars($completed); ?></h4>
        <small class="text-success"><?php echo ($totalOrders>0) ? round($completed/$totalOrders*100,0) . '% success' : '—'; ?></small>
      </div>
    </div>
    <div class="col-sm-6 col-md-3">
      <div class="card p-3 shadow-sm">
        <small class="text-muted">Total Earnings</small>
        <h4 class="mt-2"><span id="totalEarnings"><?php echo '$' . number_format($totalEarnings,2); ?></span></h4>
        <small class="text-muted">From deliveries</small>
      </div>
    </div>
    <div class="col-sm-6 col-md-3">
      <div class="card p-3 shadow-sm">
        <small class="text-muted">Avg Rating</small>
        <h4 class="mt-2"><?php echo htmlspecialchars(number_format($avgRating,1)); ?>/5.0</h4>
        <small class="text-warning">Based on customer feedback</small>
      </div>
    </div>
  </div>

  <!-- Search / Filters removed per request -->

    <!-- Completed Orders removed per request -->

  <!-- Recent deliveries removed per request -->

</div>

<!-- optional: small JS for interaction could be added here -->
<script>
document.addEventListener('DOMContentLoaded', function(){
  // fetch earnings via AJAX and update the UI
  fetch('get_rider_earnings.php', {credentials:'same-origin'})
    .then(function(res){ return res.json(); })
    .then(function(json){
      if(!json || !json.ok) return;
      // update total earnings
      var te = document.getElementById('totalEarnings');
      if(te) te.textContent = '$' + Number(json.total || 0).toFixed(2);
      // update completed count
      var cc = document.getElementById('completedCount');
      if(cc) cc.textContent = json.completed || 0;
      // update per-order earnings
      if(json.per_order){
          Object.keys(json.per_order).forEach(function(k){
            var amt = Number(json.per_order[k] || 0).toFixed(2);
            var sel = '.rider-earning[data-order-id="' + k + '"]';
            var nodes = document.querySelectorAll(sel);
            nodes.forEach(function(n){
              n.textContent = '$' + amt;
              // add a small title to indicate source if provided
              if(json.source && json.source[k]){
                n.title = 'Source: ' + json.source[k];
              }
            });
        });
      }
    }).catch(function(err){
      // silent fail
      console && console.debug && console.debug('earnings fetch failed', err);
    });
});
</script>

<?php
// Diagnostics block: show recent rider_earnings rows for this rider to help debug mismatched amounts
try{
  $dbg = $pdo->prepare('SELECT id, order_id, delivery_id, amount, source, created_at FROM rider_earnings WHERE rider_id = :rid ORDER BY created_at DESC LIMIT 50');
  $dbg->execute([':rid'=>$rid]);
  $dbgRows = $dbg->fetchAll(PDO::FETCH_ASSOC);
  if($dbgRows):
?>
<div class="card mt-3 p-3">
  <h6 class="mb-2">Completed Orders</h6>
  <div class="table-responsive">
    <table class="table table-sm">
      <thead><tr><th>ID</th><th>Order</th><th>Delivery</th><th class="text-end">Amount</th><th>Created At</th></tr></thead>
      <tbody>
        <?php foreach($dbgRows as $dr): ?>
        <tr>
          <td><?php echo (int)$dr['id']; ?></td>
          <td><?php echo $dr['order_id'] ? ('#ORD-' . (int)$dr['order_id']) : '-'; ?></td>
          <td><?php echo $dr['delivery_id'] ? (int)$dr['delivery_id'] : '-'; ?></td>
          <td class="text-end"><?php echo '$' . number_format((float)$dr['amount'],2); ?></td>
          <td><?php echo htmlspecialchars($dr['created_at']); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
  endif;
}catch(Exception $e){ /* ignore diagnostics errors */ }
?>
