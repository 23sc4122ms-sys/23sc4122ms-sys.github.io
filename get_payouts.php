<?php
// get_payouts.php - returns HTML fragment showing recent payouts
require_once __DIR__ . '/db.php';
header('Content-Type: text/html; charset=utf-8');
session_start();

try{
    $pdo = getPDO();

    // If a rider is logged in, show that rider's payouts. If an admin, show pending payouts with actions.
    $isRider = !empty($_SESSION['user_id']) && strtolower(($_SESSION['user_role'] ?? '')) === 'rider';
    $isAdmin = !empty($_SESSION['user_id']) && in_array(strtolower(($_SESSION['user_role'] ?? '')), ['admin','owner']);

    if($isRider){
        $rid = (int)$_SESSION['user_id'];
        // detect optional columns to build a compatible select
        try{
            $hasStatus = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','status');
            $hasRequested = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','requested_at');
            $hasPaidAt = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','paid_at');
            $hasMethod = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','method');
            $hasNotes = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','notes');
        }catch(Exception $e){ $hasStatus = $hasRequested = $hasPaidAt = $hasMethod = $hasNotes = false; }

        $statusExpr = $hasStatus ? 'status' : 'NULL AS status';
        $dateExpr = $hasRequested ? 'DATE(requested_at) as requested_date' : 'DATE(created_at) as requested_date';
        $paidExpr = $hasPaidAt ? 'DATE(paid_at) as paid_date' : "NULL AS paid_date";
        $methodExpr = $hasMethod ? 'method' : "NULL AS method";
        $notesExpr = $hasNotes ? 'notes' : "NULL AS notes";

        // Rider: show pending/in-progress payouts in the main table
        if($hasStatus){
            $pendingWhere = "status IN ('pending','processing')";
        }elseif($hasPaymentStatus = (function_exists('schema_has_column') && schema_has_column($pdo,'payouts','payment_status'))){
            $pendingWhere = "payment_status IN ('pending','processing')";
        }elseif($hasPaidAt){
            $pendingWhere = 'paid_at IS NULL';
        }else{
            $pendingWhere = '1';
        }

        $sql = "SELECT id, {$statusExpr}, {$dateExpr}, {$paidExpr}, amount, {$methodExpr}, {$notesExpr} FROM payouts WHERE rider_id = :rid AND {$pendingWhere} ORDER BY created_at DESC LIMIT 200";
        $sth = $pdo->prepare($sql);
        $sth->execute([':rid'=>$rid]);
        $rows = $sth->fetchAll(PDO::FETCH_ASSOC);

        if(!$rows){
            echo '<div class="p-3 text-muted">You have no payouts yet.</div>';
        } else {
            echo '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Requested</th><th>Payout ID</th><th>Amount</th><th>Status</th><th>Method</th></tr></thead><tbody>';
            foreach($rows as $r){
                $d = htmlspecialchars($r['requested_date'] ?? '');
                $id = htmlspecialchars($r['id'] ?? '');
                $amt = number_format((float)($r['amount'] ?? 0),2);
                $rawStatus = $r['status'] ?? null;
                $statusLabel = 'In progress';
                if($rawStatus !== null && trim((string)$rawStatus) !== ''){
                    $s = strtolower(trim((string)$rawStatus));
                    if(in_array($s, ['pending','processing','in_progress','in progress'], true)){
                        $statusLabel = 'In progress';
                    }elseif($s === 'paid' || $s === 'completed'){
                        $statusLabel = 'Paid';
                    }else{
                        $statusLabel = ucfirst($s);
                    }
                }
                $method = htmlspecialchars($r['method'] ?? '');
                echo '<tr><td>' . $d . '</td><td>#' . $id . '</td><td>$' . $amt . '</td><td>' . htmlspecialchars($statusLabel) . '</td><td>' . $method . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        // --- Completed payouts for this rider ---
        // Determine completed/filter expression for rider
        try{ $hasPaymentStatus = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','payment_status'); }catch(Exception $e){ $hasPaymentStatus = false; }

        if($hasStatus){
            $compWhere = "p.status IN ('paid','completed') AND p.rider_id = :rid";
        }elseif($hasPaidAt){
            $compWhere = 'p.paid_at IS NOT NULL AND p.rider_id = :rid';
        }elseif($hasPaymentStatus){
            $compWhere = "p.payment_status IN ('completed','paid') AND p.rider_id = :rid";
        }else{
            $compWhere = '0';
        }

        if($compWhere !== '0'){
            $requestedSel = $hasRequested ? 'DATE(p.requested_at) as requested_date' : 'DATE(p.created_at) as requested_date';
            $paidSel = $hasPaidAt ? 'DATE(p.paid_at) as paid_date' : 'NULL AS paid_date';
            $methodSel = $hasMethod ? 'p.method' : 'NULL AS method';
            $notesSel = $hasNotes ? 'p.notes' : 'NULL AS notes';
            $statusSel = $hasStatus ? 'p.status' : 'NULL AS status';

            $orderBy2 = $hasPaidAt ? 'p.paid_at' : ($hasRequested ? 'p.requested_at' : 'p.created_at');
            $doneSql = "SELECT p.id, p.rider_id, p.amount, {$statusSel}, {$requestedSel}, {$paidSel}, {$methodSel}, {$notesSel}, u.name as rider_name
                FROM payouts p LEFT JOIN users u ON u.id = p.rider_id
                WHERE {$compWhere} ORDER BY {$orderBy2} DESC LIMIT 200";
            $sth2 = $pdo->prepare($doneSql);
            $sth2->execute([':rid'=>$rid]);
            $doneRows = $sth2->fetchAll(PDO::FETCH_ASSOC);
        }else{
            $doneRows = [];
        }

        if(!empty($doneRows)){
            echo '<h5 class="mt-4">Completed Payouts</h5>';
            echo '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Paid</th><th>Payout ID</th><th>Amount</th><th>Method</th><th>Notes</th></tr></thead><tbody>';
            foreach($doneRows as $r){
                $paidDate = htmlspecialchars($r['paid_date'] ?? '');
                $id = htmlspecialchars($r['id'] ?? '');
                $amt = number_format((float)($r['amount'] ?? 0),2);
                $method = htmlspecialchars($r['method'] ?? '');
                $notes = htmlspecialchars(substr((string)($r['notes'] ?? ''),0,140));
                echo '<tr><td>' . $paidDate . '</td><td>#' . $id . '</td><td>$' . $amt . '</td><td>' . $method . '</td><td>' . $notes . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        exit;
    }

    if($isAdmin){
        // admin view: show pending payouts; (button row removed per request)
        // We'll provide per-row actions (Mark Paid, View Details) instead of global buttons.
        // admin view: show pending payouts; be defensive about columns
        try{ $hasStatus = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','status'); }catch(Exception $e){ $hasStatus = false; }
        try{ $hasPaymentStatus = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','payment_status'); }catch(Exception $e){ $hasPaymentStatus = false; }
        try{ $hasRequested = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','requested_at'); }catch(Exception $e){ $hasRequested = false; }
        try{ $hasPaidAt = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','paid_at'); }catch(Exception $e){ $hasPaidAt = false; }
        try{ $hasMethod = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','method'); }catch(Exception $e){ $hasMethod = false; }
        try{ $hasNotes = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','notes'); }catch(Exception $e){ $hasNotes = false; }

        $statusSel = $hasStatus ? 'p.status' : 'NULL AS status';
        $requestedSel = $hasRequested ? 'DATE(p.requested_at) as requested_date' : 'DATE(p.created_at) as requested_date';
        $paidSel = $hasPaidAt ? 'DATE(p.paid_at) as paid_date' : 'NULL AS paid_date';
        $methodSel = $hasMethod ? 'p.method' : 'NULL AS method';
        $notesSel = $hasNotes ? 'p.notes' : 'NULL AS notes';

        if($hasStatus){
            $orderBy = $hasRequested ? 'p.requested_at' : 'p.created_at';
            $sth = $pdo->query("SELECT p.id, p.rider_id, p.amount, {$statusSel}, {$requestedSel}, {$paidSel}, {$methodSel}, {$notesSel}, u.name as rider_name
                FROM payouts p LEFT JOIN users u ON u.id = p.rider_id
                WHERE p.status IN ('pending','processing') ORDER BY {$orderBy} ASC LIMIT 200");
        }elseif($hasPaymentStatus){
            // schema uses payment_status column
            $orderBy = $hasRequested ? 'p.requested_at' : 'p.created_at';
            $sth = $pdo->query("SELECT p.id, p.rider_id, p.amount, {$statusSel}, {$requestedSel}, {$paidSel}, {$methodSel}, {$notesSel}, u.name as rider_name
                FROM payouts p LEFT JOIN users u ON u.id = p.rider_id
                WHERE p.payment_status IN ('pending','processing') ORDER BY {$orderBy} ASC LIMIT 200");
        }else{
            // fallback: treat unpaid (paid_at IS NULL) as pending
            $orderBy = $hasRequested ? 'p.requested_at' : 'p.created_at';
            $paidWhere = $hasPaidAt ? 'p.paid_at IS NULL' : '1';
            $sth = $pdo->query("SELECT p.id, p.rider_id, p.amount, {$statusSel}, {$requestedSel}, {$paidSel}, {$methodSel}, {$notesSel}, u.name as rider_name
                FROM payouts p LEFT JOIN users u ON u.id = p.rider_id
                WHERE {$paidWhere} ORDER BY {$orderBy} ASC LIMIT 200");
        }
        $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
        if(!$rows){
            echo '<div class="p-3 text-muted">No pending payouts.</div>';
        } else {
            echo '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Requested</th><th>Rider</th><th>Payout ID</th><th>Amount</th><th>Status</th><th>Method</th><th>Action</th></tr></thead><tbody>';
            foreach($rows as $r){
            $d = htmlspecialchars($r['requested_date'] ?? '');
            $rid = (int)($r['rider_id'] ?? 0);
            $rname = htmlspecialchars($r['rider_name'] ?? ('#' . $rid));
            $id = htmlspecialchars($r['id'] ?? '');
            $amt = number_format((float)($r['amount'] ?? 0),2);
            $rawStatus = $r['status'] ?? null;
            $statusLabel = 'In progress';
            if($rawStatus !== null && trim((string)$rawStatus) !== ''){
                $s = strtolower(trim((string)$rawStatus));
                if(in_array($s, ['pending','processing','in_progress','in progress'], true)){
                    $statusLabel = 'In progress';
                }elseif($s === 'paid' || $s === 'completed'){
                    $statusLabel = 'Paid';
                }else{
                    $statusLabel = ucfirst($s);
                }
            }
            $method = htmlspecialchars($r['method'] ?? '');
                echo '<tr id="payout-row-' . $id . '"><td>' . $d . '</td><td>' . $rname . '</td><td>#' . $id . '</td><td>$' . $amt . '</td><td>' . htmlspecialchars($statusLabel) . '</td><td>' . $method . '</td><td><div class="d-flex gap-2"><button class="btn btn-sm btn-success confirm-payout" data-id="' . $id . '">Mark Paid</button><button class="btn btn-sm btn-outline-primary view-rider" data-rider="' . $rid . '">View Details</button></div></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        // --- Completed payouts table (admin/owner only) ---
        try{ $hasPaymentStatus = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','payment_status'); }catch(Exception $e){ $hasPaymentStatus = false; }

        if($hasStatus){
            $compWhere = "p.status IN ('paid','completed')";
        }elseif($hasPaidAt){
            $compWhere = 'p.paid_at IS NOT NULL';
        }elseif($hasPaymentStatus){
            $compWhere = "p.payment_status IN ('completed','paid')";
        }else{
            $compWhere = '0';
        }

        if($compWhere !== '0'){
            $orderBy2 = $hasPaidAt ? 'p.paid_at' : ($hasRequested ? 'p.requested_at' : 'p.created_at');
            $doneSql = "SELECT p.id, p.rider_id, p.amount, {$statusSel}, {$requestedSel}, {$paidSel}, {$methodSel}, {$notesSel}, u.name as rider_name
                FROM payouts p LEFT JOIN users u ON u.id = p.rider_id
                WHERE {$compWhere} ORDER BY {$orderBy2} DESC LIMIT 200";
            $sth2 = $pdo->query($doneSql);
            $doneRows = $sth2->fetchAll(PDO::FETCH_ASSOC);
        }else{
            $doneRows = [];
        }

        if(!empty($doneRows)){
            echo '<h5 class="mt-4">Completed Payouts</h5>';
            echo '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Paid</th><th>Rider</th><th>Payout ID</th><th>Amount</th><th>Method</th><th>Notes</th></tr></thead><tbody>';
            foreach($doneRows as $r){
                $paidDate = htmlspecialchars($r['paid_date'] ?? '');
                $rid = (int)($r['rider_id'] ?? 0);
                $rname = htmlspecialchars($r['rider_name'] ?? ('#' . $rid));
                $id = htmlspecialchars($r['id'] ?? '');
                $amt = number_format((float)($r['amount'] ?? 0),2);
                $method = htmlspecialchars($r['method'] ?? '');
                $notes = htmlspecialchars(substr((string)($r['notes'] ?? ''),0,140));
                echo '<tr><td>' . $paidDate . '</td><td>' . $rname . '</td><td>#' . $id . '</td><td>$' . $amt . '</td><td>' . $method . '</td><td>' . $notes . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        // include small script to handle admin confirm actions and view details (AJAX)
        echo <<<'JS'
<script>
    // handle confirm payout clicks and backfill buttons (AJAX, credentials included)
    document.addEventListener('click', function(e){
        if(e.target && e.target.classList && e.target.classList.contains('confirm-payout')){
            var btn = e.target;
            var id = btn.dataset.id;
            if(!confirm('Mark payout #' + id + ' as paid?')) return;
            btn.disabled = true;
            fetch('confirm_payout.php', { method:'POST', credentials: 'same-origin', headers:{ 'Content-Type':'application/x-www-form-urlencoded' }, body:'payout_id=' + encodeURIComponent(id) })
            .then(r=>r.json())
                .then(j=>{
                if(j && j.ok){
                    var row = document.getElementById('payout-row-' + id);
                    if(row) row.parentNode.removeChild(row);
                    document.dispatchEvent(new CustomEvent('payout:marked-paid', { detail: { payout_id: id } }));
                    alert('Payout marked paid');
                } else {
                    var errMsg = (j && j.error) ? j.error : 'Server error';
                    if(j && j.message) errMsg += '\n' + j.message;
                    alert('Failed: ' + errMsg);
                    btn.disabled = false;
                }
            }).catch(err=>{ alert('Server error'); btn.disabled = false; console.warn(err); });
        }

        // View rider details (orders list)
        if(e.target && e.target.classList && e.target.classList.contains('view-rider')){
            var vr = e.target;
            var riderId = vr.dataset.rider;
            vr.disabled = true;
            // create or reuse modal
            var modalId = 'riderOrdersModal';
            var modalEl = document.getElementById(modalId);
            if(!modalEl){
                var tpl = '<div id="' + modalId + '" class="modal fade" tabindex="-1">' +
                          '<div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">' +
                          '<div class="modal-header"><h5 class="modal-title">Rider Orders</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
                          '<div class="modal-body" id="' + modalId + '-body">Loading...</div>' +
                          '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>' +
                          '</div></div></div>';
                document.body.insertAdjacentHTML('beforeend', tpl);
                modalEl = document.getElementById(modalId);
            }
            var body = modalEl.querySelector('#' + modalId + '-body');
            if(body) body.innerHTML = 'Loading...';
            var modal = new bootstrap.Modal(modalEl);
            modal.show();
            // remove modal DOM when hidden to clear backdrop and avoid stacking
            modalEl.addEventListener('hidden.bs.modal', function(){ try{ modalEl.remove(); }catch(e){} });
            fetch('admin_rider_orders.php?rider_id=' + encodeURIComponent(riderId), { credentials: 'same-origin' }).then(r=>r.text()).then(html=>{
                if(body) body.innerHTML = html;
                // attach handlers for view-order buttons inside the modal
                body.querySelectorAll('.view-order').forEach(function(b){
                    if(b.dataset.attach=='1') return; b.dataset.attach='1';
                    b.addEventListener('click', function(){
                        var orderId = this.dataset.order;
                        var target = document.getElementById('orderDetail-'+orderId);
                        if(!target){ var container = document.createElement('div'); container.id = 'orderDetail-'+orderId; this.parentNode.parentNode.insertAdjacentElement('afterend', container); target = container; }
                        target.innerHTML = 'Loading order...';
                        fetch('admin_get_order.php?order=' + encodeURIComponent(orderId), { credentials: 'same-origin' }).then(r=>r.text()).then(h=>{ target.innerHTML = h; try{ var closeBtn = target.querySelector('.close-order'); if(closeBtn){ closeBtn.addEventListener('click', function(){ try{ target.remove(); }catch(e){} }); } }catch(e){} }).catch(()=>{ target.innerHTML = '<div class="text-danger">Failed to load order</div>'; });
                    });
                });
            }).catch(err=>{ if(body) body.innerHTML = '<div class="text-danger">Failed to load rider orders</div>'; console.warn(err); }).finally(()=>{ vr.disabled = false; });
        }
    });

    (function(){
        function fetchAndShow(url, btn){
            var out = document.getElementById('backfillOutput');
            if(!out){ out = document.createElement('div'); out.id = 'backfillOutput'; out.style.marginTop = '12px'; document.currentScript.parentNode.insertBefore(out, document.currentScript); }
            if(btn) btn.disabled = true;
            out.innerHTML = '<div class="text-muted">Working...</div>';
            fetch(url, { credentials: 'same-origin' }).then(r=>r.text()).then(t=>{ out.innerHTML = t; if(btn) btn.disabled = false; }).catch(e=>{ out.innerHTML = '<div class=\'text-danger\'>Failed to run</div>'; console.warn(e); if(btn) btn.disabled = false; });
        }
        var runBtn = document.getElementById('runBackfill');
        var fixBtn = document.getElementById('checkFixAccounts');
        if(runBtn){ runBtn.addEventListener('click', function(){ if(!confirm('Run backfill to compute rider accounts from rider_earnings?')) return; fetchAndShow('backfill_rider_accounts.php', this); }); }
        if(fixBtn){ fixBtn.addEventListener('click', function(){ if(!confirm('Check for negative account balances and fix them?')) return; fetchAndShow('backfill_rider_accounts.php?fix=1', this); }); }
    })();
</script>
JS;
        exit;
        exit;
    }

    // fallback: not logged in or unknown role
    echo '<div class="p-3 text-muted">Please sign in to view payouts.</div>';
    exit;

}catch(Exception $e){
    // Log the exception for debugging
    try{
        $logDir = __DIR__ . '/storage'; if(!is_dir($logDir)) @mkdir($logDir,0755,true);
        $entry = ['ts'=>date('c'),'event'=>'get_payouts_error','message'=>$e->getMessage(),'trace'=>substr($e->getTraceAsString(),0,2000)];
        @file_put_contents($logDir . '/debug_payouts.log', json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
    }catch(Exception $ee){ /* ignore logging failures */ }
    $msg = htmlspecialchars($e->getMessage());
    echo '<div class="p-3 text-danger">Failed to load payouts: ' . $msg . '</div>';
    exit;
}
