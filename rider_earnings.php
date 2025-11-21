<?php
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

// Handle payout request (AJAX POST)
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='request_payout'){
  header('Content-Type: application/json');
  // buffer any stray output (warnings, HTML) so client always receives valid JSON
  ob_start();
  function send_json($arr){
    $buf = '';
    try{ $buf = ob_get_clean(); }catch(Exception $e){ $buf = ''; }
    // log any stray buffer output for debugging
    if(!empty($buf)){
      try{ $logDir = __DIR__ . '/storage'; if(!is_dir($logDir)) @mkdir($logDir,0755,true); @file_put_contents($logDir . '/debug_payouts.log', json_encode(['ts'=>date('c'),'event'=>'stray_output','output'=>substr($buf,0,2000)]) . "\n", FILE_APPEND | LOCK_EX); }catch(Exception $ee){}
    }
    // send JSON and exit
    echo json_encode($arr);
    exit;
  }
  if(empty($_SESSION['user_id']) || strtolower(($_SESSION['user_role']??''))!=='rider'){
    send_json(['ok'=>false,'error'=>'Unauthorized']);
  }
  $rid=(int)$_SESSION['user_id'];
  // Client may send an amount, but server will authoritative use rider_accounts.available_amount
  $client_amount=(float)($_POST['amount']??0);
  // Temporary debug: ensure storage dir and log attempt details
  try{
    $logDir = __DIR__ . '/storage'; if(!is_dir($logDir)) @mkdir($logDir,0755,true);
    // use client-supplied amount for the initial attempt log (server selects final amount later)
    $dbgLine = [ 'ts'=>date('c'), 'event'=>'payout_attempt', 'rider'=>$rid, 'requested_amount'=>$client_amount, 'remote_ip'=>($_SERVER['REMOTE_ADDR']??null) ];
  }catch(Exception $e){ /* ignore logging failures */ }
  // Note: validation moved after computing authoritative available_amount below
  $min = defined('CASHOUT_MIN')?CASHOUT_MIN:10.0;

  // Compute available balance from ledger first
  $avail=0.0; $pending=0.0;
  try{
    $st=$pdo->prepare('SELECT total_earned,total_earnings,available_amount,pending_amount FROM rider_accounts WHERE rider_id=:r LIMIT 1');
    $st->execute([':r'=>$rid]);
    if($row=$st->fetch(PDO::FETCH_ASSOC)){
      $pending=(float)$row['pending_amount'];
      $avail=(float)$row['available_amount']; // authoritative available balance source
    // If no completed rows found but payouts exist for this rider, show them as a fallback
    if(empty($doneRows)){
      try{
        $fb = $pdo->prepare("SELECT id, amount, method, notes, DATE(created_at) as requested_date FROM payouts WHERE rider_id = :rid ORDER BY created_at DESC LIMIT 200");
        $fb->execute([':rid'=>$rid]);
        $allRows = $fb->fetchAll(PDO::FETCH_ASSOC);
        if($allRows){
          echo '<h5 class="mt-4">Payouts (All)</h5>';
          echo '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Date</th><th>Payout ID</th><th>Amount</th><th>Method</th><th>Notes</th></tr></thead><tbody>';
          foreach($allRows as $r){
            $d = htmlspecialchars($r['requested_date'] ?? '');
            $id = htmlspecialchars($r['id'] ?? '');
            $amt = number_format((float)($r['amount'] ?? 0),2);
            $method = htmlspecialchars($r['method'] ?? '');
            $notes = htmlspecialchars(substr((string)($r['notes'] ?? ''),0,140));
            echo '<tr><td>' . $d . '</td><td>#' . $id . '</td><td>$' . $amt . '</td><td>' . $method . '</td><td>' . $notes . '</td></tr>';
          }
          echo '</tbody></table></div>';
        }
      }catch(Exception $e){ /* ignore fallback errors */ }
    }
      if($avail < 0) $avail = 0.0;
      // Optional fallback: if available is zero but total_earned positive and no pending payouts, derive from total_earned
      if($avail === 0.0 && $pending <= 0){
        $te = (float)$row['total_earned'];
        if($te > 0){
          $avail = $te;
          try{ $pdo->prepare('UPDATE rider_accounts SET available_amount=:a,last_updated=NOW() WHERE rider_id=:r')->execute([':a'=>$avail,':r'=>$rid]); }catch(Exception $se){}
        }
      }
    }
  }catch(Exception $e){ }
  if($avail<=0){
    try{
      $earned=0.0;
      $s=$pdo->prepare('SELECT IFNULL(SUM(amount),0) FROM rider_earnings WHERE rider_id=:r'); $s->execute([':r'=>$rid]); $earned=(float)$s->fetchColumn();
      if($earned<=0){
        $col=$pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='deliveries'")->fetchAll(PDO::FETCH_COLUMN);
        $hasAmount=in_array('amount',$col,true); $parts=[]; foreach(['base_pay','bonus','tip','fee'] as $c){ if(in_array($c,$col,true)) $parts[]="IFNULL($c,0)"; }
        $inner=$parts?implode('+',$parts):'0'; $expr=$hasAmount?"IFNULL(NULLIF(amount,0),($inner))":"($inner)";
        $s2=$pdo->prepare("SELECT IFNULL(SUM($expr),0) FROM deliveries WHERE rider_id=:r"); $s2->execute([':r'=>$rid]); $earned=(float)$s2->fetchColumn();
      }
      // Some deployments may not have a `status` column on `payouts`.
      // Detect column existence and use a safe fallback to avoid SQL errors.
      $pending = 0.0;
      try{
        $chk = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payouts' AND COLUMN_NAME='status'");
        $chk->execute(); $hasStatus = (int)$chk->fetchColumn() > 0;
      }catch(Exception $e){ $hasStatus = false; }
      if($hasStatus){
        $spSql = "SELECT IFNULL(SUM(amount),0) FROM payouts WHERE rider_id=:r AND status IN ('pending','processing')";
      }else{
        // Older schema: no status column — consider all payouts as pending for conservative calculation
        $spSql = "SELECT IFNULL(SUM(amount),0) FROM payouts WHERE rider_id=:r";
      }
      try{
        $sp = $pdo->prepare($spSql);
        $sp->execute([':r'=>$rid]);
        $pending = (float)$sp->fetchColumn();
      }catch(PDOException $pe){
        // If the preferred query fails (missing column), fall back to the simple query
        try{
          $fb = $pdo->prepare("SELECT IFNULL(SUM(amount),0) FROM payouts WHERE rider_id=:r");
          $fb->execute([':r'=>$rid]);
          $pending = (float)$fb->fetchColumn();
        }catch(Exception $ee){
          $pending = 0.0;
        }
      }
      $avail=max(0,$earned-$pending);
    }catch(Exception $fe){ $avail=0.0; }
  }
  // Detailed debug: log ledger row and computed avail
  try{
    // Use client_amount for the 'requested' debug field — $amount is assigned later
    $dbg = [ 'ts'=>date('c'), 'event'=>'payout_compute', 'rider'=>$rid, 'requested'=>$client_amount, 'avail_raw'=>$avail, 'pending_raw'=>$pending ];
    // include ledger row if available
    try{ $ledgerRow = $pdo->prepare('SELECT total_earned, pending_amount, available_amount FROM rider_accounts WHERE rider_id = :r LIMIT 1'); $ledgerRow->execute([':r'=>$rid]); $lr = $ledgerRow->fetch(PDO::FETCH_ASSOC); if($lr) $dbg['ledger']=$lr; }catch(Exception $e){}
    @file_put_contents(__DIR__ . '/storage/debug_payouts.log', json_encode($dbg) . "\n", FILE_APPEND | LOCK_EX);
  }catch(Exception $e){}
  // Prefer client-requested amount if provided, otherwise cash out the full available balance.
  // Always validate and cap to the authoritative available balance on server-side.
  $requested_client_amount = round((float)($client_amount ?? 0), 2);
  if($requested_client_amount > 0){
    // Client requested a specific amount — ensure it's not more than available
    if($requested_client_amount > $avail){
      // log rejection
      try{ $rej = ['ts'=>date('c'),'event'=>'payout_rejected_client_exceeds','rider'=>$rid,'requested'=>$requested_client_amount,'available'=>$avail]; @file_put_contents(__DIR__ . '/storage/debug_payouts.log', json_encode($rej) . "\n", FILE_APPEND | LOCK_EX); }catch(Exception $e){}
      send_json(['ok'=>false,'error'=>'Amount exceeds balance','available'=>$avail]);
    }
    $amount = $requested_client_amount;
  } else {
    // No client-specified amount: cash out full available by default
    $amount = round($avail,2);
  }
  // log server selected amount for debugging
  try{ @file_put_contents(__DIR__ . '/storage/debug_payouts.log', json_encode(['ts'=>date('c'),'event'=>'payout_selected_amount','rider'=>$rid,'client_requested'=>$client_amount,'server_amount'=>$amount]) . "\n", FILE_APPEND | LOCK_EX); }catch(Exception $e){}

  if($amount <= 0){ send_json(['ok'=>false,'error'=>'Invalid amount','available'=>$avail]); }
  if($amount < $min){ send_json(['ok'=>false,'error'=>'Below minimum','min'=>$min,'available'=>$amount]); }

  if($amount>$avail){
    // log rejection
    try{ $rej = ['ts'=>date('c'),'event'=>'payout_rejected','rider'=>$rid,'requested'=>$amount,'available'=>$avail]; @file_put_contents(__DIR__ . '/storage/debug_payouts.log', json_encode($rej) . "\n", FILE_APPEND | LOCK_EX); }catch(Exception $e){}
    send_json(['ok'=>false,'error'=>'Amount exceeds balance','available'=>$avail]);
  }

  // Insert payout and update ledger atomically (with error logging)
  try{
    // log start of DB operation and computed amounts
    try{ @file_put_contents(__DIR__ . '/storage/debug_payouts.log', json_encode(['ts'=>date('c'),'event'=>'payout_start','rider'=>$rid,'computed_amount'=>round($avail,2),'pending'=>$pending]) . "\n", FILE_APPEND | LOCK_EX); }catch(Exception $e){}
    $pdo->beginTransaction();
    // Ensure INSERT matches schema: adapt to whichever columns exist on `payouts`
    try{
      $insHasStatus = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','status');
      $insHasRequested = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','requested_at');
      $insHasCreated = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','created_at');
    }catch(Exception $e){ $insHasStatus = $insHasRequested = $insHasCreated = false; }

    // Build column list and placeholders based on available columns
    $cols = ['rider_id','amount'];
    $placeholders = [':r',':a'];
    $params = [':r'=>$rid, ':a'=>$amount];
    if($insHasStatus){ $cols[] = 'status'; $placeholders[] = "'processing'"; }
    // method column expected in most schemas
    $cols[] = 'method'; $placeholders[] = "'direct_deposit'";
    if($insHasRequested){ $cols[] = 'requested_at'; $placeholders[] = 'NOW()'; }
    if($insHasCreated){ $cols[] = 'created_at'; $placeholders[] = 'NOW()'; }

    $sql = 'INSERT INTO payouts (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
    $ins = $pdo->prepare($sql);
    // execute with params (method/status values are inlined above)
    $ins->execute($params);
    $pid=(int)$pdo->lastInsertId();

    // Atomically increment pending and deduct from available
    // Do NOT deduct total_earned at request time to avoid double deduction.
    // total_earned will be reduced when payout is marked paid (confirm_payout.php).
    $upd = $pdo->prepare('UPDATE rider_accounts SET pending_amount = pending_amount + :a, available_amount = GREATEST(0, available_amount - :a), last_updated = NOW() WHERE rider_id = :r');
    $upd->execute([':a'=>$amount,':r'=>$rid]);
    if($upd->rowCount() === 0){
      // No ledger row yet — insert one with pending_amount set, available 0
      $insAcc = $pdo->prepare('INSERT INTO rider_accounts (rider_id,total_earned,pending_amount,available_amount,last_updated) VALUES (:r,0,:a,0,NOW())');
      $insAcc->execute([':r'=>$rid,':a'=>$amount]);
    }

    $pdo->commit();
    // fetch updated available
    $st2 = $pdo->prepare('SELECT IFNULL(available_amount,0) AS avail FROM rider_accounts WHERE rider_id = :r LIMIT 1');
    $st2->execute([':r'=>$rid]); $newAvail = (float)$st2->fetchColumn();

    // log acceptance
    try{
      $log = ['ts'=>date('c'),'event'=>'payout_accepted','rider'=>$rid,'payout_id'=>$pid,'amount'=>$amount,'available_after'=>isset($newAvail)?$newAvail:null];
      @file_put_contents(__DIR__ . '/storage/debug_payouts.log', json_encode($log) . "\n", FILE_APPEND | LOCK_EX);
    }catch(Exception $e){}

    send_json(['ok'=>true,'payout_id'=>$pid,'amount'=>$amount,'available'=>isset($newAvail)?round($newAvail,2):null]);
  }catch(Exception $e){
    try{ if($pdo->inTransaction()) $pdo->rollBack(); }catch(Exception $rb){}
    // log exception details for debugging (file only)
    try{
      $err = ['ts'=>date('c'),'event'=>'payout_error','rider'=>$rid,'requested'=>$amount,'message'=>$e->getMessage(), 'trace'=>substr($e->getTraceAsString(),0,2000)];
      @file_put_contents(__DIR__ . '/storage/debug_payouts.log', json_encode($err) . "\n", FILE_APPEND | LOCK_EX);
    }catch(Exception $ee){}
    // Return error message to client for debugging (safe in dev/local environments)
    $msg = $e->getMessage();
    send_json(['ok'=>false,'error'=>'Server error','message'=>$msg]);
  }
}

if(empty($_SESSION['user_id']) || strtolower(($_SESSION['user_role']??''))!=='rider'){
  echo '<div class="container py-3"><div class="alert alert-warning">Sign in as a rider to view earnings.</div></div>'; return;
}
$rid=(int)$_SESSION['user_id'];

// Pre-render stats
$week_total=0.0; $daily_avg=0.0; $total_orders=0; $today_total=0.0;
try{
  $acc=$pdo->prepare('SELECT week_earn FROM rider_accounts WHERE rider_id=:r LIMIT 1'); $acc->execute([':r'=>$rid]); if($ar=$acc->fetch(PDO::FETCH_ASSOC)){ if($ar['week_earn']!==null) $week_total=(float)$ar['week_earn']; }
  $cnt=$pdo->prepare('SELECT IFNULL(COUNT(*),0) c, IFNULL(AVG(amount),0) a, IFNULL(SUM(CASE WHEN DATE(created_at)=CURDATE() THEN amount END),0) t FROM rider_earnings WHERE rider_id=:r');
  $cnt->execute([':r'=>$rid]); if($cr=$cnt->fetch(PDO::FETCH_ASSOC)){ $total_orders=(int)($cr['c']??0); $daily_avg=(float)($cr['a']??0); $today_total=(float)($cr['t']??0); }
}catch(Exception $e){ }
// Fetch authoritative available_amount for initial render
$initial_available = 0.00;
try{
  $avSt = $pdo->prepare('SELECT IFNULL(available_amount,0) AS avail FROM rider_accounts WHERE rider_id = :r LIMIT 1');
  $avSt->execute([':r'=>$rid]); if($ar = $avSt->fetch(PDO::FETCH_ASSOC)){ $initial_available = (float)($ar['avail'] ?? 0.0); }
}catch(Exception $e){ $initial_available = 0.00; }

// Compute total completed payouts amount for initial render (schema-resilient)
$total_payouts = 0.00;
try{
  // Compute total payouts as the SUM(amount) in payouts table for this rider_id
  $tsth = $pdo->prepare('SELECT IFNULL(SUM(amount),0) FROM payouts WHERE rider_id = :r');
  $tsth->execute([':r'=>$rid]);
  $total_payouts = (float)$tsth->fetchColumn();
}catch(Exception $e){ $total_payouts = 0.00; }
?>
<div class="container-fluid" id="riderEarningsFragment">
  <style>
    /* Earnings summary card styles to match design */
    .earnings-summary { display:flex; gap:1rem; flex-wrap:wrap; }
    .summary-card { background:#fff; border:1px solid #eef0f3; border-radius:10px; padding:18px; min-width:200px; flex:1 1 220px; }
    .summary-card small { color:#6b7280; display:block; }
    .summary-amount { font-size:1.45rem; font-weight:700; margin-top:6px; }
    .summary-icon { width:40px;height:40px;border-radius:8px;background:#f1f5ff;display:flex;align-items:center;justify-content:center;color:#2b6cb0;margin-left:auto }
    .earnings-chart-card { border:1px solid #eef0f3;border-radius:10px;padding:18px;background:#fff; position:relative }
    .card-shadow { box-shadow: 0 1px 6px rgba(16,24,40,0.04); }
    .chart-controls{ position:absolute; top:8px; right:8px; display:flex; gap:6px; }
    .chart-toggle{ background:#fff; border:1px solid #d1d5db; padding:4px 10px; font-size:11px; border-radius:6px; cursor:pointer; display:flex; align-items:center; gap:4px; box-shadow:0 1px 2px rgba(0,0,0,0.07); }
    .chart-toggle.active{ background:#2563eb; color:#fff; border-color:#2563eb; }
    .chart-toggle .dot{ width:10px; height:10px; border-radius:50%; display:inline-block; }
    .chart-toggle.base .dot{ background:#16a34a; }
    .chart-toggle.total .dot{ background:#2563eb; }
    @media (max-width:768px){ .summary-card{ flex:1 1 100%; } }
  </style>
  <div class="mb-4">
    <h3 class="mb-0">Earnings</h3>
    <p class="text-muted">Track your income, payouts, and financial performance</p>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-12">
      <div class="earnings-summary">
        <div class="summary-card card-shadow">
          <div style="display:flex;align-items:center;gap:12px">
            <div>
              <small>Today's Earning</small>
                <div class="summary-amount" id="todayTotal"><?php echo '$' . number_format($today_total,2); ?></div>
            </div>
            <div class="summary-icon"><i class="bi bi-currency-dollar"></i></div>
          </div>
        </div>

        <div class="summary-card card-shadow">
          <small>Average Earning</small>
          <div class="summary-amount" id="dailyAvg"><?php echo '$' . number_format($daily_avg,2); ?></div>
        </div>

        <div class="summary-card card-shadow">
          <small>Weekly Earning</small>
          <div class="summary-amount" id="weekTotal"><?php echo '$' . number_format($week_total,2); ?></div>
        </div>

        <div class="summary-card card-shadow">
          <small>Total Orders</small>
          <div class="summary-amount" id="totalOrders"><?php echo (int)$total_orders; ?></div>
        </div>
        <div class="summary-card card-shadow" id="availableBalanceCard">
          <small>Available Balance</small>
          <div class="summary-amount" id="availableBalance"><?php echo '$' . number_format($initial_available,2); ?></div>
          <small id="availableBalanceHint" style="margin-top:4px;color:#6b7280">Minimum $<?php echo number_format(defined('CASHOUT_MIN')?CASHOUT_MIN:10,2); ?> required</small>
            <div style="margin-top:8px">
            <small>Total Payouts</small>
            <div class="summary-amount" id="totalPayouts"><?php echo '$' . number_format((float)$total_payouts,2); ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-4 p-3">
    <div class="d-flex gap-2 mb-3 align-items-center">
      <div class="btn-group btn-group-sm" role="group">
        <button class="btn btn-sm btn-outline-secondary active" data-tab="overview">Overview</button>
        <button class="btn btn-sm btn-outline-secondary" data-tab="breakdown">Breakdown</button>
        <button class="btn btn-sm btn-outline-secondary" data-tab="payouts">Payouts</button>
        <button class="btn btn-sm btn-outline-secondary" data-tab="comparison">Comparison</button>
      </div>

      <div class="ms-auto d-flex gap-2 align-items-center">
        <select id="earningsRange" class="form-select form-select-sm" style="width:140px;">
          <option value="7" selected>Last 7 days</option>
          <option value="14">Last 14 days</option>
          <option value="30">Last 30 days</option>
        </select>
        <!-- Chart displays total earnings only -->
        <button id="refreshEarnings" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-clockwise"></i></button>
      </div>
    </div>

    <div class="mt-2">
      <h6 class="mb-3">Daily Earnings Trend</h6>
      <div class="earnings-chart-card">
        <canvas id="earningsChart" style="width:100%;height:320px"></canvas>
      </div>
    </div>
  </div>

  <div id="earningsTabContent" class="mt-3"></div>

  <!-- Summary or breakdown rows could go here -->
  <div class="row">
    <div class="col-md-6">
      <div class="card p-3 shadow-sm mb-3">
        <div style="display:flex;align-items:center;gap:12px">
          <div>
            <h6 class="mb-0">Recent Payout</h6>
            <div class="text-muted small" id="recentPayoutMeta">Direct deposit • —</div>
            <div class="fw-bold mt-2" id="recentPayoutAmount">$0.00</div>
          </div>
          <div style="margin-left:auto; display:flex; gap:8px; align-items:center">
            <button id="cashoutBtn" class="btn btn-sm btn-primary" data-initial-available="<?php echo number_format($initial_available,2,'.',''); ?>">Cash Out</button>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card p-3 shadow-sm mb-3">
        <h6 class="mb-0">Pending Payouts</h6>
        <div class="text-muted small" id="pendingPayoutMeta">Waiting for confirmation</div>
        <div class="fw-bold mt-2" id="pendingPayoutAmount">$0.00</div>
      </div>
    </div>
  </div>

  <!-- Cash Out: simplified flow (AJAX request, admin must confirm) -->

  <!-- Riders diagnostics removed per user request -->

  <script>
    const SERVER_CASHOUT_MIN = <?php echo defined('CASHOUT_MIN')?number_format(CASHOUT_MIN,2,'.',''):'10.00'; ?>;
    (function(){
    // Use a fragment-local marker to avoid double-init for the same DOM node.
    // When the fragment is re-inserted via AJAX a new element is created and will re-run init.
    const _root = document.getElementById('riderEarningsFragment');
    // Debug: indicate fragment init
    try{ console.debug('riderEarnings: init', { rootExists: !!_root, datasetInit: _root && _root.dataset && _root.dataset.riderEarningsInit }); }catch(e){}
    // Defensive: if a previous fragment left state behind, call its cleanup first (idempotent)
    try{ if(typeof window.riderEarningsCleanup === 'function'){ try{ window.riderEarningsCleanup(); }catch(e){} } }catch(e){}
    // Ensure controller registry is reset
    try{ window._riderEarningsControllers = window._riderEarningsControllers || {}; }catch(e){}
    if(_root && _root.dataset.riderEarningsInit){ console.log('rider_earnings fragment already initialized for this DOM node'); return; }
    if(_root) _root.dataset.riderEarningsInit = '1';
    // Defensive: ensure any form controls inside the fragment are enabled on init
    try{
      if(_root){
        Array.from(_root.querySelectorAll('button, input, select, textarea')).forEach(el=>{
          try{ el.disabled = false; }catch(e){}
          try{ if(el.dataset) delete el.dataset.cashoutProcessing; }catch(e){}
        });
      }
    }catch(e){ console.warn('riderEarnings: failed to re-enable controls on init', e); }
    // AbortController helpers and ajax events
    if(!window._riderEarningsControllers) window._riderEarningsControllers = {};
    if(!window._riderEarningsTimers) window._riderEarningsTimers = [];
    // MutationObserver reference to detect fragment removal
    let _riderEarningsObserver = null;

    function _makeController(key){ try{ const ac = new AbortController(); window._riderEarningsControllers[key]=ac; return ac; }catch(e){ return null; } }
    function _clearController(key){ try{ if(window._riderEarningsControllers && window._riderEarningsControllers[key]) delete window._riderEarningsControllers[key]; }catch(e){} }
    function riderEarningsAbortAll(){ try{ if(window._riderEarningsControllers){ Object.keys(window._riderEarningsControllers).forEach(k=>{ try{ window._riderEarningsControllers[k].abort(); }catch(e){} }); window._riderEarningsControllers = {}; } }catch(e){} }
    // Expose abort all for host use
    try{ window.riderEarningsAbortAll = riderEarningsAbortAll; }catch(e){}
    function _dispatchAjaxEvent(name, detail){ try{ window.dispatchEvent(new CustomEvent(name,{detail:detail||{}})); }catch(e){ console.warn('dispatch event failed', name, e); } }

    // Helper to register timeouts/intervals so they can be cleared on cleanup
    function _registerTimer(id){ try{ if(!window._riderEarningsTimers) window._riderEarningsTimers = []; window._riderEarningsTimers.push(id); }catch(e){} }
    function _clearAllTimers(){ try{ if(window._riderEarningsTimers){ window._riderEarningsTimers.forEach(t=>{ try{ clearTimeout(t); clearInterval(t); }catch(e){} }); window._riderEarningsTimers = []; } }catch(e){} }
    // Elements will be looked up lazily inside functions so re-inserting the fragment works
    let chart = window.earningsChartInstance || null;

    function money(v){ return '$' + Number(v || 0).toFixed(2); }

    let showBase = true, showTotal = true; let lastData = { labels:[], base:[], total:[] };

    function fetchAndRender(){
      const rangeEl = document.getElementById('earningsRange');
      const refreshBtn = document.getElementById('refreshEarnings');
      // Freeze controls while fetching
      if(rangeEl) rangeEl.disabled = true;
      if(refreshBtn) refreshBtn.disabled = true;
      const days = parseInt(rangeEl ? rangeEl.value : '7',10);
      // Create an abort controller for this fetch
      const _ac = _makeController('fetchAndRender');
      _dispatchAjaxEvent('rider:ajax-start',{ action:'fetchAndRender' });
      // If Chart.js is available, prefer the Canvas-based chart and skip SVG renderer
      if(typeof Chart !== 'undefined' && typeof initEarningsChart === 'function'){
        try{
          // ensure SVG fallback is removed to avoid duplicate visuals
          const chartContainerTmp = document.querySelector('.earnings-chart-card');
          if(chartContainerTmp){
            // destroy any existing SVG content
            Array.from(chartContainerTmp.querySelectorAll('svg, .svg-holder')).forEach(n=>n.remove());
            // ensure canvas exists for Chart.js
            let canvas = chartContainerTmp.querySelector('#earningsChart');
            if(!canvas){
              canvas = document.createElement('canvas');
              canvas.id = 'earningsChart';
              canvas.style.width = '100%'; canvas.style.height = '320px';
              chartContainerTmp.appendChild(canvas);
            }
          }
          initEarningsChart();
        }catch(e){ console.warn('initEarningsChart failed', e); }
        if(rangeEl) rangeEl.disabled = false;
        if(refreshBtn) refreshBtn.disabled = false;
        return;
      }
      return fetch('get_earnings.php?range=' + encodeURIComponent(days), _ac ? { signal: _ac.signal } : undefined)
        .then(r => r.text())
        .then(text => {
          let payload = null;
          try{ payload = JSON.parse(text); }
          catch(err){ console.warn('get_earnings returned non-json:', err, text); payload = { has_data:false }; }
          return payload;
        })
        .then(payload => {
          // Chart-only: do not update the summary cards here. Summary cards are populated
          // by the persisted `get_weekly_earnings.php` endpoint (which reads `rider_accounts.week_earn`).
          const hasData = !!payload.has_data;

          let labels = Array.isArray(payload.labels) ? payload.labels.slice() : [];
          let totalData = Array.isArray(payload.total) ? payload.total.slice() : [];
          let baseData = Array.isArray(payload.base) ? payload.base.slice() : [];

          // Normalize lengths: ensure all arrays match labels length
          const n = labels.length;
          const toNumber = v => {
            const num = Number(v);
            return (typeof num === 'number' && !isNaN(num)) ? num : 0;
          };
          if(n === 0){
            payload.has_data = false;
          } else {
            totalData = totalData.slice(0, n).map(toNumber);
            while(totalData.length < n) totalData.push(0);
            baseData = baseData.slice(0, n).map(toNumber);
            while(baseData.length < n) baseData.push(0);
            const sumAll = totalData.reduce((a,b)=>a+b,0);
            if(sumAll === 0) payload.has_data = false;
          }

          // Find chart container and clear it
          const chartContainer = document.querySelector('.earnings-chart-card');
          if(!chartContainer) return;
          // If a Chart.js instance exists, destroy it and remove the canvas before using SVG fallback
          try{ if(window.earningsChartInstance && typeof window.earningsChartInstance.destroy === 'function'){ window.earningsChartInstance.destroy(); window.earningsChartInstance = null; } }catch(e){}
          // remove existing canvas to avoid canvas overlay when rendering inline SVG
          const existingCanvas = chartContainer.querySelector('#earningsChart'); if(existingCanvas) existingCanvas.remove();
          chartContainer.innerHTML = '';

          if(!hasData){
            const msg = document.createElement('div');
            msg.style.cssText = 'display:flex;align-items:center;justify-content:center;height:320px;color:#6b7280;font-weight:600';
            msg.textContent = 'No earnings data yet';
            chartContainer.appendChild(msg);
          } else {
            lastData = { labels, base: baseData, total: totalData };
            buildInteractiveChart(chartContainer);
          }
        })
        .catch(err => {
          if(err && err.name === 'AbortError'){
            _dispatchAjaxEvent('rider:ajax-abort',{ action:'fetchAndRender' });
          } else {
            console.warn('Failed to load earnings', err);
          }
        })
        .finally(()=>{
          // Re-enable controls after render
          if(rangeEl) rangeEl.disabled = false;
          if(refreshBtn) refreshBtn.disabled = false;
          _dispatchAjaxEvent('rider:ajax-finished',{ action:'fetchAndRender' });
          _clearController('fetchAndRender');
        });
    }

    function buildInteractiveChart(container){
      // controls
      const controls = document.createElement('div'); controls.className='chart-controls';
      const btnBase = document.createElement('button'); btnBase.type='button'; btnBase.className='chart-toggle base' + (showBase?' active':''); btnBase.innerHTML='<span class="dot"></span>Base';
      const btnTotal = document.createElement('button'); btnTotal.type='button'; btnTotal.className='chart-toggle total' + (showTotal?' active':''); btnTotal.innerHTML='<span class="dot"></span>Total';
      controls.appendChild(btnBase); controls.appendChild(btnTotal); container.appendChild(controls);
      const svgHolder = document.createElement('div'); container.appendChild(svgHolder);
      const render = ()=>{
        svgHolder.innerHTML = renderChartSVG(container, lastData.labels, showBase?lastData.base:lastData.base.map(()=>0), showTotal?lastData.total:lastData.total.map(()=>0));
      };
      btnBase.addEventListener('click', ()=>{ if(btnBase.disabled) return; showBase=!showBase; btnBase.classList.toggle('active', showBase); render(); });
      btnTotal.addEventListener('click', ()=>{ if(btnTotal.disabled) return; showTotal=!showTotal; btnTotal.classList.toggle('active', showTotal); render(); });
      render();
    }

    // Generate SVG for chart
    function renderChartSVG(containerEl, labels, baseData, totalData){
      const w = (containerEl && containerEl.offsetWidth) ? containerEl.offsetWidth : 800;
      const h = 320;
      const margin = { top: 20, right: 20, bottom: 40, left: 50 };
      const plotW = w - margin.left - margin.right;
      const plotH = h - margin.top - margin.bottom;

      const maxVal = Math.max(
        Math.max(...totalData, 0),
        Math.max(...baseData, 0),
        1 // ensure min scale
      );
      const yScale = plotH / maxVal;
      const xStep = plotW / Math.max(labels.length - 1, 1);

      let svg = '<svg width="' + w + '" height="' + h + '" style="display:block;width:100%;height:auto">\n';
      svg += '<defs><style>.chart-text { font-size:12px; fill:#666; } .chart-line { fill:none; stroke-width:2; } .chart-point { fill:white; stroke-width:2; r:4; }</style></defs>\n';

      // Background grid
      svg += '<rect x="' + margin.left + '" y="' + margin.top + '" width="' + plotW + '" height="' + plotH + '" fill="#f9fafb" stroke="#eee" stroke-width="1"/>\n';

      // Y-axis
      svg += '<line x1="' + margin.left + '" y1="' + margin.top + '" x2="' + margin.left + '" y2="' + (margin.top + plotH) + '" stroke="#ccc" stroke-width="1"/>\n';
      // X-axis
      svg += '<line x1="' + margin.left + '" y1="' + (margin.top + plotH) + '" x2="' + (w - margin.right) + '" y2="' + (margin.top + plotH) + '" stroke="#ccc" stroke-width="1"/>\n';

      // Y-axis labels
      const ySteps = 5;
      for(let i = 0; i <= ySteps; i++){
        const val = Math.round((maxVal / ySteps) * i);
        const y = margin.top + plotH - (val * yScale);
        svg += '<text x="' + (margin.left - 8) + '" y="' + (y + 4) + '" text-anchor="end" class="chart-text">$' + val + '</text>\n';
        svg += '<line x1="' + (margin.left - 4) + '" y1="' + y + '" x2="' + margin.left + '" y2="' + y + '" stroke="#ccc" stroke-width="1"/>\n';
      }

      // X-axis labels
      for(let i = 0; i < labels.length; i++){
        const x = margin.left + (i * xStep);
        svg += '<text x="' + x + '" y="' + (margin.top + plotH + 20) + '" text-anchor="middle" class="chart-text">' + labels[i] + '</text>\n';
      }

      // Helper: build smoothed path (quadratic bezier using midpoints)
      function buildSmoothedPath(data, strokeColor){
        if(!data || data.length === 0) return '';
        const pts = data.map((v,i)=>({ x: margin.left + (i * xStep), y: margin.top + plotH - (v * yScale) }));
        if(pts.length === 1) return '<circle cx="'+pts[0].x+'" cy="'+pts[0].y+'" class="chart-point" stroke="'+strokeColor+'"/>';
        let dPath = 'M ' + pts[0].x + ',' + pts[0].y;
        for(let i = 1; i < pts.length; i++){
          const prev = pts[i-1];
          const cur = pts[i];
          const cx = (prev.x + cur.x) / 2;
          const cy = (prev.y + cur.y) / 2;
          dPath += ' Q ' + prev.x + ',' + prev.y + ' ' + cx + ',' + cy;
        }
        // finish curve to the last point
        const last = pts[pts.length-1];
        dPath += ' T ' + last.x + ',' + last.y;
        // build SVG elements: path + points
        let out = '<path d="' + dPath + '" class="chart-line" stroke="' + strokeColor + '" fill="none"/>';
        for(let i=0;i<pts.length;i++){
          out += '<circle cx="' + pts[i].x + '" cy="' + pts[i].y + '" class="chart-point" stroke="' + strokeColor + '"/>';
        }
        return out;
      }

      svg += buildSmoothedPath(baseData, '#16a34a');
      svg += buildSmoothedPath(totalData, '#2563eb');

      // Legend removed (interactive buttons handle labels)

      svg += '</svg>';
      return svg;
    }

    // Attach change listener to the range selector (attach only once per element)
    const __rangeEl = document.getElementById('earningsRange');
    if(__rangeEl && !__rangeEl.dataset.earningsRangeAttached){ __rangeEl.addEventListener('change', fetchAndRender); __rangeEl.dataset.earningsRangeAttached = '1'; }
    // Attach refresh button handler once
    const __refreshBtn = document.getElementById('refreshEarnings');
    if(__refreshBtn && !__refreshBtn.dataset.refreshAttached){ __refreshBtn.addEventListener('click', fetchAndRender); __refreshBtn.dataset.refreshAttached = '1'; }
    // Export button removed per user request

    // Tab loading: show chart for Overview and replace the chart area with list/table for other tabs
    function loadTab(tab){
      const contentEl = document.getElementById('earningsTabContent');
      const chartContainer = document.querySelector('.earnings-chart-card') || contentEl;
      const _rangeEl = document.getElementById('earningsRange');
      const days = parseInt(_rangeEl ? _rangeEl.value : '7',10);
      // Clear any previous tab content area
      if(contentEl) contentEl.innerHTML = '';

      // Helper to ensure Chart canvas exists and init chart
      function showChartArea(){
        try{
          // destroy any existing SVG-based content
          if(chartContainer){
            Array.from(chartContainer.querySelectorAll('svg, .svg-holder')).forEach(n=>n.remove());
            // ensure canvas exists
            let canvas = chartContainer.querySelector('#earningsChart');
            if(!canvas){
              canvas = document.createElement('canvas');
              canvas.id = 'earningsChart';
              canvas.style.width = '100%'; canvas.style.height = '320px';
              chartContainer.innerHTML = '';
              chartContainer.appendChild(canvas);
            }
          }
          // initialize chart (Chart.js path) or fallback will be handled by initEarningsChart
          if(typeof initEarningsChart === 'function') initEarningsChart();
        }catch(e){ console.warn('showChartArea error', e); }
      }

      // Overview: show the line graph
      if(tab === 'overview'){
        showChartArea();
        return;
      }

      // For other tabs we replace the chart area with the requested content
      // Ensure any existing Chart.js instance is destroyed to avoid overlap
      try{ if(window.earningsChartInstance && typeof window.earningsChartInstance.destroy === 'function'){ window.earningsChartInstance.destroy(); window.earningsChartInstance = null; } }catch(e){}

      // Breakdown: replace chart with breakdown list
      if(tab === 'breakdown'){
        if(chartContainer) chartContainer.innerHTML = '<div class="p-2 text-muted">Loading breakdown...</div>';
        const _ac = _makeController('tab_breakdown');
        _dispatchAjaxEvent('rider:ajax-start',{ action:'breakdown' });
        fetch('get_earnings.php?range=' + encodeURIComponent(days), _ac ? { signal: _ac.signal } : undefined)
          .then(r => r.json())
          .then(j => {
            try{
              if(!chartContainer || !chartContainer.isConnected) return;
              if(!j || !j.has_data){ chartContainer.innerHTML = '<div class="p-3 text-muted">No data for breakdown.</div>'; return; }
              const labels = j.labels || [];
              const totals = j.total || [];
              let html = '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Date</th><th>Day</th><th>Total</th></tr></thead><tbody>';
              for(let i=0;i<labels.length;i++){
                const d = labels[i];
                const t = Number(totals[i] || 0).toFixed(2);
                html += '<tr><td>'+d+'</td><td>'+d+'</td><td>$'+t+'</td></tr>';
              }
              html += '</tbody></table></div>';
              chartContainer.innerHTML = html;
            }catch(err){ console.warn('breakdown render failed', err); }
          }).catch(e=>{
            if(e && e.name === 'AbortError'){
              _dispatchAjaxEvent('rider:ajax-abort',{ action:'breakdown' });
            } else {
              if(chartContainer && chartContainer.isConnected) chartContainer.innerHTML = '<div class="p-3 text-danger">Failed to load breakdown</div>';
            }
          }).finally(()=>{ _clearController('tab_breakdown'); _dispatchAjaxEvent('rider:ajax-finished',{ action:'breakdown' }); });
        return;
      }

      // Payouts: replace chart with payouts table (server-rendered HTML)
      if(tab === 'payouts'){
        if(chartContainer) chartContainer.innerHTML = '<div class="p-2 text-muted">Loading payouts...</div>';
        const _ac = _makeController('tab_payouts');
        _dispatchAjaxEvent('rider:ajax-start',{ action:'payouts' });
        fetch('get_payouts.php', _ac ? { credentials: 'same-origin', signal: _ac.signal } : { credentials: 'same-origin' })
          .then(r => r.text())
          .then(html => { try{ if(chartContainer && chartContainer.isConnected) chartContainer.innerHTML = html; }catch(err){ console.warn('payouts render failed', err); } })
          .catch(e => {
            if(e && e.name === 'AbortError'){
              _dispatchAjaxEvent('rider:ajax-abort',{ action:'payouts' });
            } else {
              if(chartContainer && chartContainer.isConnected) chartContainer.innerHTML = '<div class="p-3 text-danger">Failed to load payouts</div>';
            }
          }).finally(()=>{ _clearController('tab_payouts'); _dispatchAjaxEvent('rider:ajax-finished',{ action:'payouts' }); });
        return;
      }

      // Comparison: replace chart with comparison summary
      if(tab === 'comparison'){
        if(chartContainer) chartContainer.innerHTML = '<div class="p-2 text-muted">Loading comparison...</div>';
        const _ac = _makeController('tab_comparison');
        _dispatchAjaxEvent('rider:ajax-start',{ action:'comparison' });
        fetch('get_earnings_comparison.php?range=' + encodeURIComponent(days), _ac ? { signal: _ac.signal } : undefined)
          .then(r => r.json())
          .then(j => {
            try{
              if(!chartContainer || !chartContainer.isConnected) return;
              if(!j || j.error){ chartContainer.innerHTML = '<div class="p-3 text-muted">No comparison data</div>'; return; }
              const curr = Number(j.current || 0).toFixed(2);
              const prev = Number(j.previous || 0).toFixed(2);
              const diff = Number(j.diff || 0).toFixed(2);
              const pct = (j.pct === null) ? '—' : (j.pct + '%');
              let html = '<div class="d-flex gap-3 align-items-center">';
              html += '<div><small class="text-muted">Current</small><div class="fw-bold">$'+curr+'</div></div>';
              html += '<div><small class="text-muted">Previous</small><div class="fw-bold">$'+prev+'</div></div>';
              html += '<div><small class="text-muted">Change</small><div class="fw-bold">$'+diff+' ('+pct+')</div></div>';
              html += '</div>';
              chartContainer.innerHTML = html;
            }catch(err){ console.warn('comparison render failed', err); }
          }).catch(e=>{
            if(e && e.name === 'AbortError'){
              _dispatchAjaxEvent('rider:ajax-abort',{ action:'comparison' });
            } else {
              if(chartContainer && chartContainer.isConnected) chartContainer.innerHTML = '<div class="p-3 text-danger">Failed to load comparison</div>';
            }
          }).finally(()=>{ _clearController('tab_comparison'); _dispatchAjaxEvent('rider:ajax-finished',{ action:'comparison' }); });
        return;
      }
    }

    // attach tab click handlers (attach only once per button)
    document.querySelectorAll('.btn-group [data-tab]').forEach(function(btn){
      if(btn.dataset.earningsTabAttached) return;
      btn.dataset.earningsTabAttached = '1';
      btn.addEventListener('click', function(){
        document.querySelectorAll('.btn-group [data-tab]').forEach(b=>b.classList.remove('active'));
        this.classList.add('active');
        loadTab(this.dataset.tab);
      });
    });

    // initial render
    fetchAndRender();
    
    // fetch persisted stats (DB) and populate summary cards with available balance info
    function fetchWeeklyStats(){
      const _ac = _makeController('fetchWeeklyStats');
      _dispatchAjaxEvent('rider:ajax-start',{ action:'fetchWeeklyStats' });
      return fetch('get_weekly_earnings.php', _ac ? { signal: _ac.signal } : undefined)
        .then(r => r.json())
        .then(j => {
          if(!j || !j.ok) return j;
          const todayEl = document.getElementById('todayTotal');
          const weekEl = document.getElementById('weekTotal');
          const dailyEl = document.getElementById('dailyAvg');
          const totalOrdersEl = document.getElementById('totalOrders');
          if(todayEl) todayEl.textContent = '$' + Number(j.today || 0).toFixed(2);
          if(weekEl) weekEl.textContent = '$' + Number(j.total || 0).toFixed(2);
          if(dailyEl) dailyEl.textContent = '$' + Number(j.daily_avg || 0).toFixed(2);
          if(totalOrdersEl) totalOrdersEl.textContent = (j.total_orders || 0);
          console.log('fetchWeeklyStats', j);
          return j;
        })
        .catch(err => { if(err && err.name === 'AbortError'){ _dispatchAjaxEvent('rider:ajax-abort',{ action:'fetchWeeklyStats' }); } else { console.warn('Failed to load weekly stats', err); } return null; })
        .finally(()=>{ _dispatchAjaxEvent('rider:ajax-finished',{ action:'fetchWeeklyStats' }); _clearController('fetchWeeklyStats'); });
    }
    fetchWeeklyStats();

    // payouts summary fetch & cashout handling
    function fetchPayoutSummary(){
      const _ac = _makeController('fetchPayoutSummary');
      _dispatchAjaxEvent('rider:ajax-start',{ action:'fetchPayoutSummary' });
      return fetch('get_payouts_summary.php?debug=1', _ac ? { signal: _ac.signal } : undefined)
        .then(r=>r.json())
        .then(j=>{
          console.log('payoutSummary', j);
          // debug hook removed to avoid extra AJAX on page load
          const recentEl=document.getElementById('recentPayoutAmount');
          const recentMeta=document.getElementById('recentPayoutMeta');
          const pendingEl=document.getElementById('pendingPayoutAmount');
          const pendingMeta=document.getElementById('pendingPayoutMeta');
          const btn=document.getElementById('cashoutBtn'); const availEl=document.getElementById('availableBalance'); const hintEl=document.getElementById('availableBalanceHint');
          if(!j || !j.ok){
            if(recentEl) recentEl.textContent='$0.00';
            if(recentMeta) recentMeta.textContent='—';
            if(pendingEl) pendingEl.textContent='$0.00';
            if(pendingMeta) pendingMeta.textContent='Waiting for confirmation';
            if(btn){ btn.disabled=true; btn.dataset.available='0'; btn.title='Balance below minimum'; }
            return;
          }
          const recent=j.recent||null;
          // Prefer direct ledger field if provided
          let available = (typeof j.available_amount==='number') ? j.available_amount : ((typeof j.available==='number') ? j.available : parseFloat((j.available||'').toString().replace(/[^0-9.\-]/g,''))||0);
          const pending=j.pending||{total:0,count:0};
          if(recent){
            if(recentEl) recentEl.textContent='$'+Number(recent.amount||0).toFixed(2);
            if(recentMeta){
              if(recent.status && recent.status!=='paid'){
                recentMeta.textContent=(recent.method||'Direct deposit')+' • '+recent.status.toUpperCase();
              }else{
                recentMeta.textContent=(recent.method||'Direct deposit')+' • '+(recent.paid_date||'—');
              }
            }
          }else{
            if(recentEl) recentEl.textContent='$0.00';
            if(recentMeta) recentMeta.textContent='—';
          }
          if(pendingEl) pendingEl.textContent='$'+Number(pending.total||0).toFixed(2);
          if(pendingMeta) pendingMeta.textContent=(pending.count||0)+' request(s) pending';
          // update total payouts count if provided by summary endpoint
          const totalPayoutsEl = document.getElementById('totalPayouts');
          if(totalPayoutsEl && typeof j.total_payouts !== 'undefined'){
            totalPayoutsEl.textContent = '$' + Number(j.total_payouts || 0).toFixed(2);
          }
          const min = Number(SERVER_CASHOUT_MIN)||10;
          if(availEl) availEl.textContent='$'+Number(available).toFixed(2);
          if(hintEl) hintEl.textContent = (available >= min ? 'Eligible to cash out' : 'Need $'+min.toFixed(2)+' minimum');
          if(btn){
            btn.dataset.available=Number(available).toFixed(2);
            btn.title='Available: $'+Number(available).toFixed(2)+' (Minimum $'+min.toFixed(2)+')';
            btn.disabled = available < min;
            btn.classList.toggle('cashout-ready', available >= min);
            btn.classList.toggle('cashout-disabled', available < min);
          }
          // Display debug mismatch hint inline if zero but history suggests funds
          const dbg = j.debug || {};
          if(availEl && typeof dbg.historical_expected_available === 'number'){
            const expected = dbg.historical_expected_available;
            if(Number(available) === 0 && expected > 0){
              let extra = document.getElementById('availMismatchNote');
              if(!extra){
                extra = document.createElement('small');
                extra.id='availMismatchNote';
                extra.style.cssText='display:block;color:#b45309;margin-top:2px';
                availEl.parentElement.appendChild(extra);
              }
              extra.textContent='Syncing ledger... expected $'+expected.toFixed(2)+' (auto-correct pending)';
            }else{
              const extra = document.getElementById('availMismatchNote');
              if(extra) extra.remove();
            }
          }
          return j;
        })
        .catch(err=>{ if(err && err.name === 'AbortError'){ _dispatchAjaxEvent('rider:ajax-abort',{ action:'fetchPayoutSummary' }); } else { console.warn('payout summary failed',err); } return null; })
        .finally(()=>{ _dispatchAjaxEvent('rider:ajax-finished',{ action:'fetchPayoutSummary' }); _clearController('fetchPayoutSummary'); });
    }

    // Cash Out: delegated click handler attached to fragment root
    (function(){
      const root = document.getElementById('riderEarningsFragment');
      if(!root) return;
      // avoid adding multiple handlers for the same DOM node
      if(root.dataset.cashoutHandlerAttached) return; root.dataset.cashoutHandlerAttached = '1';

      window._riderEarningsClickHandler = function(e){
        try{
          const btn = e.target.closest && e.target.closest('#cashoutBtn');
          if(!btn) return;
          // guard: ignore if already processing
          if(btn.dataset.cashoutProcessing) return;
          btn.dataset.cashoutProcessing = '1';
          const orig = btn.innerHTML;
          btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';

          const availEl = document.getElementById('availableBalance');
          const minAmt = Number(SERVER_CASHOUT_MIN) || 10.00;

          // Step 1: fetch authoritative available amount from server
          const _ac = _makeController('cashout');
          _dispatchAjaxEvent('rider:ajax-start',{ action:'cashout' });
          fetch('get_payouts_summary.php', { credentials: 'same-origin', signal: _ac ? _ac.signal : undefined })
            .then(r => r.json())
            .then(summary => {
              if(!summary) throw new Error('Failed to fetch balance');
              if(!summary.ok) throw new Error(summary.error || 'Unauthorized');
              const available = Number(summary.available_amount ?? summary.available ?? 0);
              if(available < minAmt){
                alert('Minimum cash out is $' + Number(minAmt).toFixed(2) + '. Your available balance is $' + Number(available).toFixed(2));
                throw new Error('Below minimum');
              }
              const amount = Math.round((available + Number.EPSILON) * 100) / 100;
              const ok = window.confirm('Are you sure you want to cash out $' + Number(amount).toFixed(2) + '?');
              if(!ok) throw new Error('user_cancelled');
              return fetch('rider_earnings.php', {
                method: 'POST', credentials: 'same-origin', headers: { 'Content-Type':'application/x-www-form-urlencoded' },
                body: 'action=request_payout&amount=' + encodeURIComponent(amount)
              }).then(r=>r.json());
            })
            .then(resp => {
              if(!resp) throw new Error('Invalid server response');
              if(!resp.ok) throw new Error(resp.error || 'Cash out failed');
              const newAvail = (typeof resp.available !== 'undefined') ? Number(resp.available) : null;
              if(newAvail !== null && availEl) availEl.textContent = '$' + Number(newAvail).toFixed(2);
              alert('Payout requested: $' + Number(resp.amount || 0).toFixed(2) + ' (pending approval)');
              try{ window.dispatchEvent(new CustomEvent('rider:refresh-panel', { detail: { source: 'earnings', payout: resp } })); }catch(e){ console.warn('dispatch rider:refresh-panel failed', e); }
            })
            .catch(err => {
              if(err && err.name === 'AbortError'){
                _dispatchAjaxEvent('rider:ajax-abort',{ action:'cashout' });
              } else if(err && err.message === 'user_cancelled'){
                // noop
              } else if(err && err.message === 'Below minimum'){
                // already alerted
              } else {
                console.error('Cash out error', err);
                alert('Cash out failed: ' + (err && err.message ? err.message : 'Unknown error'));
              }
            })
            .finally(()=>{
              try{ btn.innerHTML = orig; delete btn.dataset.cashoutProcessing; }catch(e){}
              // refresh authoritative summary
              fetch('get_payouts_summary.php', { credentials: 'same-origin' }).then(r=>r.json()).then(j=>{ if(j && j.ok){ if(availEl) availEl.textContent = '$'+Number(j.available_amount ?? j.available ?? 0).toFixed(2); } }).catch(()=>{});
              _clearController('cashout');
              _dispatchAjaxEvent('rider:ajax-finished',{ action:'cashout' });
            });
        }catch(e){ console.warn('delegated cashout handler error', e); }
      };

      root.addEventListener('click', window._riderEarningsClickHandler);
    })();

    // View Payouts: fetch payout table HTML and display inline
    // View Payouts feature removed — no client-side handlers required

    // default overview
    loadTab('overview');

    // Observe fragment removal: if the fragment is removed from DOM without host calling cleanup,
    // run cleanup to ensure no stale handlers remain.
    try{
      if(_root && _root.parentNode && typeof MutationObserver !== 'undefined'){
        _riderEarningsObserver = new MutationObserver(function(records){
          records.forEach(r => {
            if(r.removedNodes && r.removedNodes.length){
              Array.from(r.removedNodes).forEach(n => {
                if(n === _root){
                  try{ if(typeof window.riderEarningsCleanup === 'function') window.riderEarningsCleanup(); }catch(e){}
                }
              });
            }
          });
        });
        _riderEarningsObserver.observe(_root.parentNode, { childList: true });
      }
    }catch(e){ /* ignore observer failures */ }
    // allow other fragments to notify this page to refresh summaries
    // attach a named handler so we can remove it on cleanup and avoid duplicates
    try{
      window._riderEarningsAccountsUpdatedHandler = function(){ try{ fetchWeeklyStats(); fetchPayoutSummary(); }catch(e){} };
      document.addEventListener('rider:accounts-updated', window._riderEarningsAccountsUpdatedHandler);
    }catch(e){ console.warn('failed to attach accounts-updated handler', e); }
  })();
</script>
<script>
// Provide a global initializer that the dashboard can call when loading this fragment via AJAX.
// Uses Chart.js when available; falls back to existing SVG renderer if Chart is not present.
function initEarningsChart(){
  const canvas = document.getElementById('earningsChart');
  if(!canvas) return;
  const rangeEl = document.getElementById('earningsRange');
  const days = rangeEl ? parseInt(rangeEl.value||'7',10) : 7;

  // If Chart.js is available, use it for a consistent line chart across dashboard and fragment.
  if(typeof Chart !== 'undefined'){
    fetch('get_earnings.php?range=' + encodeURIComponent(days))
      .then(r => r.json())
      .then(payload => {
        const labels = payload.labels || [];
        const baseData = payload.base || labels.map(()=>0);
        const totalData = payload.total || labels.map(()=>0);
        // reuse global instance if present
        if(window.earningsChartInstance && typeof window.earningsChartInstance.destroy === 'function'){
          try{ window.earningsChartInstance.destroy(); }catch(e){}
          window.earningsChartInstance = null;
        }
        window.earningsChartInstance = new Chart(canvas, {
          type: 'line',
          data: {
            labels: labels,
            datasets: [
              { label: 'Base Pay', data: baseData, borderColor:'#16a34a', backgroundColor:'rgba(22,163,74,0.06)', tension:0.3, pointRadius:3 },
              { label: 'Total', data: totalData, borderColor:'#2563eb', backgroundColor:'rgba(37,99,235,0.06)', tension:0.3, pointRadius:3 }
            ]
          },
          options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } }, scales:{ y:{ beginAtZero:true } } }
        });
      }).catch(err => { console.warn('initEarningsChart fetch failed', err); });
    return;
  }

  // Chart.js not available — attempt to call the fragment's SVG renderer if exposed
  if(typeof fetchAndRender === 'function'){
    try{ fetchAndRender(); }catch(e){ console.warn('fallback fetchAndRender failed', e); }
  }
}
window.initEarningsChart = initEarningsChart;
</script>
<script>
// Cleanup hook called by the host page before this fragment is removed.
// Destroys chart instances and clears fragment-local markers to ensure a clean re-init.
window.riderEarningsCleanup = function(){
  try{ console.debug('riderEarnings: cleanup start'); }catch(e){}
  try{
    if(window.earningsChartInstance && typeof window.earningsChartInstance.destroy === 'function'){
      try{ window.earningsChartInstance.destroy(); }catch(e){}
    }
  }catch(e){}
  try{ window.earningsChartInstance = null; }catch(e){}
  try{ if(window.initEarningsChart) delete window.initEarningsChart; }catch(e){}
  try{ const root = document.getElementById('riderEarningsFragment'); if(root && root.dataset) delete root.dataset.riderEarningsInit; }catch(e){}
  // abort any outstanding controllers
  try{ if(window._riderEarningsControllers){ Object.keys(window._riderEarningsControllers).forEach(k=>{ try{ window._riderEarningsControllers[k].abort(); }catch(e){} }); window._riderEarningsControllers = {}; } }catch(e){}
  // remove delegated click handler if present
  try{
    const root = document.getElementById('riderEarningsFragment');
    if(root && window._riderEarningsClickHandler){ try{ root.removeEventListener('click', window._riderEarningsClickHandler); }catch(e){} delete window._riderEarningsClickHandler; if(root && root.dataset) delete root.dataset.cashoutHandlerAttached; }
  }catch(e){}
  // remove modal hidden listener if set
  try{ if(window._riderEarningsModalHiddenHandler && typeof document !== 'undefined'){ document.removeEventListener('hidden.bs.modal', window._riderEarningsModalHiddenHandler); delete window._riderEarningsModalHiddenHandler; } }catch(e){}
  // disconnect observer if present
  try{ if(_riderEarningsObserver){ try{ _riderEarningsObserver.disconnect(); }catch(e){} _riderEarningsObserver = null; } }catch(e){}
  // clear timers
  try{ _clearAllTimers(); }catch(e){}
  // abort any outstanding controllers (again) and clear registry
  try{ riderEarningsAbortAll(); }catch(e){}
  // Ensure controls inside fragment are enabled (defensive)
  try{
    const root = document.getElementById('riderEarningsFragment');
    if(root){ Array.from(root.querySelectorAll('button, input, select, textarea')).forEach(el=>{ try{ el.disabled = false; }catch(e){} try{ if(el.dataset) delete el.dataset.cashoutProcessing; }catch(e){} }); }
  }catch(e){ }
  // remove accounts-updated handler if attached
  try{ if(window._riderEarningsAccountsUpdatedHandler){ try{ document.removeEventListener('rider:accounts-updated', window._riderEarningsAccountsUpdatedHandler); }catch(e){} delete window._riderEarningsAccountsUpdatedHandler; } }catch(e){}
  // Defensive UI cleanup: re-enable any controls that might have been left disabled
  try{
    const rangeEl = document.getElementById('earningsRange'); if(rangeEl) rangeEl.disabled = false;
    const refreshBtn = document.getElementById('refreshEarnings'); if(refreshBtn) refreshBtn.disabled = false;
    const cashBtn = document.getElementById('cashoutBtn'); if(cashBtn){ try{ delete cashBtn.dataset.cashoutProcessing; }catch(e){} cashBtn.disabled = false; }
    // remove any transient small spinners appended to menu items
    try{ document.querySelectorAll('.menu-spinner').forEach(s=>s.remove()); }catch(e){}
  }catch(e){}
  // Notify host to refresh the rider panel (ensure global state is up-to-date)
  try{
    if(typeof window !== 'undefined' && typeof window.dispatchEvent === 'function'){
      try{ window.dispatchEvent(new CustomEvent('rider:refresh-panel', { detail: { source: 'riderEarningsCleanup' } })); }catch(e){}
    }
  }catch(e){}
  try{ console.debug('riderEarnings: cleanup end'); }catch(e){}
};
</script>
<script>
// Public refresh API: safe to call any time after this fragment's scripts have run.
// Re-runs the fragment's AJAX fetches and reattaches handlers if needed.
window.riderEarningsRefresh = function(){
  // run all available refresh operations and return a promise that resolves when done
  const ops = [];
  // Abort any previous in-flight operations to ensure a clean refresh
  try{ if(typeof riderEarningsAbortAll === 'function') riderEarningsAbortAll(); }catch(e){}
  try{ if(typeof fetchAndRender === 'function') ops.push(fetchAndRender()); }catch(e){ console.warn('fetchAndRender unavailable', e); }
  try{ if(typeof fetchWeeklyStats === 'function') ops.push(fetchWeeklyStats()); }catch(e){ console.warn('fetchWeeklyStats unavailable', e); }
  try{ if(typeof fetchPayoutSummary === 'function') ops.push(fetchPayoutSummary()); }catch(e){ console.warn('fetchPayoutSummary unavailable', e); }
  try{ if(typeof initEarningsChart === 'function') ops.push(Promise.resolve().then(initEarningsChart)); }catch(e){ console.warn('initEarningsChart unavailable', e); }
  return Promise.all(ops);
};
</script>
