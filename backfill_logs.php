<?php
// backfill_logs.php - view recent backfill logs (admin only)
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

if(empty($_SESSION['user_id']) || !in_array(strtolower(($_SESSION['user_role'] ?? '')), ['admin','owner'])){
    http_response_code(403);
    echo '<div class="p-3"><strong>Forbidden</strong> — sign in as admin to view logs.</div>';
    exit;
}

try{
    $rows = $pdo->query('SELECT l.*, u.name as rider_name, a.name as admin_name FROM rider_accounts_backfill_log l LEFT JOIN users u ON u.id = l.rider_id LEFT JOIN users a ON a.id = l.admin_id ORDER BY l.created_at DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows){ echo '<div class="p-3 text-muted">No backfill logs yet.</div>'; exit; }
    echo '<div class="p-3"><h4>Backfill Logs</h4><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Time</th><th>Rider</th><th>Total Earned</th><th>Pending</th><th>Available</th><th>Admin</th><th>Note</th></tr></thead><tbody>';
    foreach($rows as $r){
        $t = htmlspecialchars($r['created_at']);
        $rname = htmlspecialchars($r['rider_name'] ?? ('#' . $r['rider_id']));
        $aid = htmlspecialchars($r['admin_name'] ?? ($r['admin_id'] ?? ''));
        echo '<tr><td>' . $t . '</td><td>' . $rname . '</td><td>$' . number_format($r['total_earned'],2) . '</td><td>$' . number_format($r['pending_amount'],2) . '</td><td>$' . number_format($r['available_amount'],2) . '</td><td>' . $aid . '</td><td>' . htmlspecialchars($r['note'] ?? '') . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
    exit;
}catch(Exception $e){ echo '<div class="p-3 text-danger">Failed to load logs.</div>'; exit; }

?>
