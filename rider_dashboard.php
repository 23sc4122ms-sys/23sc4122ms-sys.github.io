<?php
// Rider Dashboard - DB-driven metrics for logged-in rider
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

// ensure rider
if(empty($_SESSION['user_id']) || strtolower(($_SESSION['user_role'] ?? '')) !== 'rider'){
    echo '<div class="container py-3"><div class="alert alert-warning">Please sign in as a rider to view this dashboard.</div></div>';
    return;
}
$rid = (int)$_SESSION['user_id'];

// Pending deliveries: assigned or picked_up
$pending = 0;
try{
    $sth = $pdo->prepare("SELECT COUNT(*) FROM deliveries WHERE rider_id = :rid AND status IN ('assigned','picked_up')");
    $sth->execute([':rid'=>$rid]);
    $pending = (int)$sth->fetchColumn();
}catch(Exception $e){ }

// Completed today: delivered or completed where delivered_at/completed_at is today
$completedToday = 0;
try{
    $sth = $pdo->prepare("SELECT COUNT(*) FROM deliveries WHERE rider_id = :rid AND status IN ('delivered','completed') AND DATE(COALESCE(delivered_at, completed_at)) = CURRENT_DATE()");
    $sth->execute([':rid'=>$rid]);
    $completedToday = (int)$sth->fetchColumn();
}catch(Exception $e){ }

// Earnings today: sum of order_items for deliveries completed/delivered today
// Earnings today: prefer daily aggregate table, fallback to summing deliveries/order_items
$earnings = 0.0;
try{
  $sth = $pdo->prepare('SELECT COALESCE(total_amount,0) FROM rider_daily_earnings WHERE rider_id = :rid AND `date` = CURRENT_DATE() LIMIT 1');
  $sth->execute([':rid'=>$rid]);
  $earnings = (float)$sth->fetchColumn();
  if($earnings == 0){
    $sql = "SELECT COALESCE(SUM(oi.price * oi.quantity),0) as total
        FROM deliveries d
        JOIN order_items oi ON oi.order_id = d.order_id
        WHERE d.rider_id = :rid
          AND oi.status IN ('delivered','completed')
          AND DATE(COALESCE(d.delivered_at, d.completed_at)) = CURRENT_DATE()";
    $sth2 = $pdo->prepare($sql);
    $sth2->execute([':rid'=>$rid]);
    $earnings = (float)$sth2->fetchColumn();
  }
}catch(Exception $e){ }

// Current shift: infer earliest assigned_at today for this rider
$shiftText = 'Not active';
try{
    $sth = $pdo->prepare("SELECT MIN(assigned_at) FROM deliveries WHERE rider_id = :rid AND DATE(assigned_at) = CURRENT_DATE()");
    $sth->execute([':rid'=>$rid]);
    $start = $sth->fetchColumn();
    if($start){
        $t = strtotime($start);
        if($t){
            $shiftText = 'Active — Started at ' . date('g:i A', $t);
        }
    }
}catch(Exception $e){ }

// Nearby orders: approximate by counting recent orders without a delivery (unassigned) created in last 30 minutes
$nearby = 0;
try{
    $sql = "SELECT COUNT(*) FROM orders o WHERE o.id NOT IN (SELECT order_id FROM deliveries) AND o.created_at >= (NOW() - INTERVAL 30 MINUTE)";
    $sth = $pdo->query($sql);
    $nearby = (int)$sth->fetchColumn();
}catch(Exception $e){ }

// Product-level earnings for this rider today (per product)
// Product-level earnings for this rider today (per product)
?>

<div class="container py-3">
  <div class="row g-3">
    <!-- Card 1: Pending Deliveries -->
    <div class="col-md-4">
      <div class="card shadow-sm p-3">
        <h5>Pending Deliveries</h5>
        <p class="fs-4"><?php echo htmlspecialchars($pending); ?></p>
      </div>
    </div>
      <script>
      // Poll deliverables and earnings so dashboard updates after a rider marks delivered
      async function tryFetchJson(paths){
        for(const p of paths){
          try{
            const res = await fetch(p);
            if(!res.ok) continue;
            const j = await res.json();
            return j;
          }catch(e){ /* try next */ }
        }
        throw new Error('all_failed');
      }

      async function refreshDashboard(){
        // update completed today by querying deliveries for this rider
        try{
          const delPaths = ['get_deliveries.php','../get_deliveries.php','/JapanFoodOrder/get_deliveries.php'];
          const deliveries = await tryFetchJson(delPaths);
          if(Array.isArray(deliveries)){
            const completedStatuses = ['delivered','confirmed','complete','completed'];
            const today = new Date().toISOString().slice(0,10);
            let count = 0;
            for(const d of deliveries){
              if(!d || !d.status) continue;
              const s = String(d.status).toLowerCase();
              const dt = (d.delivered_at || d.completed_at || d.assigned_at || d.created_at || '');
              if(completedStatuses.includes(s) && dt.startsWith(today)) count++;
            }
            const el = document.getElementById('completedTodayCount'); if(el) el.textContent = count;
          }
        }catch(e){ /* ignore */ }

        // update today's earnings using weekly summary endpoint (provides 'today')
        try{
          const earnPaths = ['get_weekly_earnings.php','../get_weekly_earnings.php','/JapanFoodOrder/get_weekly_earnings.php'];
          const j = await tryFetchJson(earnPaths);
          if(j && j.ok){
            const el = document.getElementById('earningsToday');
            if(el) el.textContent = '$' + (Number(j.today || 0)).toFixed(2);
          }
        }catch(e){ /* ignore */ }
      }

      // run once and then every 10 seconds
      refreshDashboard();
      setInterval(refreshDashboard, 10000);
      </script>

    <!-- Card 2: Completed Today -->
    <div class="col-md-4">
      <div class="card shadow-sm p-3">
        <h5>Completed Today</h5>
        <p id="completedTodayCount" class="fs-4"><?php echo htmlspecialchars($completedToday); ?></p>
      </div>
    </div>

    <!-- Card 3: Earnings -->
    <div class="col-md-4">
      <div class="card shadow-sm p-3">
        <h5>Earnings (Today)</h5>
        <p id="earningsToday" class="fs-4"><?php echo '$' . number_format($earnings, 2); ?></p>
      </div>
    </div>

    <!-- Wider card: Current Shift -->
    <div class="col-md-6">
      <div class="card shadow-sm p-3">
        <h5>Current Shift</h5>
        <p class="fs-5"><?php echo htmlspecialchars($shiftText); ?></p>
      </div>
    </div>

    <!-- Wider card: Nearby Orders -->
    <div class="col-md-6">
      <div class="card shadow-sm p-3">
        <h5>Nearby Orders</h5>
        <p class="fs-5"><?php echo htmlspecialchars($nearby); ?> orders (recent, unassigned)</p>
      </div>
    </div>
  
    <!-- Product earnings removed per user request -->
  </div>
</div>
