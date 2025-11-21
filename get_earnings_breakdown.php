<?php
// get_earnings_breakdown.php - returns HTML fragment listing recent earnings by order/delivery
require_once __DIR__ . '/db.php';
header('Content-Type: text/html; charset=utf-8');

$range = isset($_GET['range']) ? (int)$_GET['range'] : 7;
if($range <= 0) $range = 7;

try{
    $pdo = getPDO();
    // Attempt to query deliveries; adjust fields if your schema differs
    $sql = "SELECT d.id, d.order_id, DATE(d.delivered_at) as delivered_date, d.amount, d.base_pay
            FROM deliveries d
            WHERE DATE(d.delivered_at) >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            ORDER BY d.delivered_at DESC
            LIMIT 200";
    $sth = $pdo->prepare($sql);
    $sth->execute([$range]);
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);

    if(!$rows){
        echo '<div class="p-3 text-muted">No breakdown data yet.</div>';
        exit;
    }

    echo '<div class="table-responsive">';
    echo '<table class="table table-sm">';
    echo '<thead><tr><th>Date</th><th>Delivery ID</th><th>Order ID</th><th>Base Pay</th><th>Total</th></tr></thead><tbody>';
    foreach($rows as $r){
        $d = htmlspecialchars($r['delivered_date'] ?? '');
        $did = htmlspecialchars($r['id'] ?? '');
        $oid = htmlspecialchars($r['order_id'] ?? '');
        $base = number_format((float)($r['base_pay'] ?? 0),2);
        $tot = number_format((float)($r['amount'] ?? 0),2);
        echo '<tr><td>' . $d . '</td><td>#' . $did . '</td><td>#' . $oid . '</td><td>' . $base . '</td><td>' . $tot . '</td></tr>';
    }
    echo '</tbody></table></div>';
    exit;

}catch(Exception $e){
    echo '<div class="p-3 text-danger">Failed to load breakdown (no data or invalid schema).</div>';
    exit;
}
