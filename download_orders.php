<?php
// download_orders.php - stream orders as CSV, filter by year (GET: year=YYYY or 'all')
require_once __DIR__ . '/db.php';
$pdo = getPDO();

$year = isset($_GET['year']) ? trim($_GET['year']) : 'all';

try{
  $sql = "SELECT o.id, o.total, o.created_at, o.status AS db_status, COALESCE(u.name, CONCAT('Guest ', LEFT(o.session_id,8))) AS customer_name,
                 GROUP_CONCAT(CONCAT(oi.product_name, ' x', oi.quantity) SEPARATOR ', ') AS items
          FROM orders o
          LEFT JOIN users u ON u.id = o.user_id
          LEFT JOIN order_items oi ON oi.order_id = o.id
          ";
  $params = [];
  if($year !== 'all' && preg_match('/^[0-9]{4}$/', $year)){
    $sql .= " WHERE DATE_FORMAT(o.created_at, '%Y') = :yr ";
    $params[':yr'] = $year;
  }
  $sql .= " GROUP BY o.id ORDER BY o.created_at DESC";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // stream CSV
  $filename = 'orders_' . ($year === 'all' ? 'all' : $year) . '.csv';
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');

  $out = fopen('php://output', 'w');
  fputcsv($out, ['order_id','customer','items','total','status','created_at']);
  foreach($rows as $r){
    $status = $r['db_status'];
    if(empty($status)){
      // compute simple fallback: if items look empty, set processing
      $status = 'processing';
    }
    fputcsv($out, [
      $r['id'], $r['customer_name'], $r['items'], number_format((float)$r['total'],2,'.',''), $status, $r['created_at']
    ]);
  }
  fclose($out);
  exit;
}catch(Exception $e){
  http_response_code(500);
  echo 'Error: ' . $e->getMessage();
  exit;
}
?>