<?php
// sales-reports.php — DB-backed monthly sales summary
require_once __DIR__ . '/db.php';
$pdo = getPDO();

$msg = '';

// optional date range via GET: from / to in YYYY-MM format
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
// export mode: csv or print (print-friendly HTML)
$export = trim((string)($_GET['export'] ?? ''));

// build where clause for created_at between the first day of from and last day of to
$where = '';
$params = [];
if($from && preg_match('/^\d{4}-\d{2}$/', $from)){
    $where = "WHERE o.created_at >= :from_start";
    $params[':from_start'] = $from . '-01 00:00:00';
}
if($to && preg_match('/^\d{4}-\d{2}$/', $to)){
    $clause = "o.created_at <= :to_end";
    if($where !== '') $where .= ' AND ' . $clause; else $where = 'WHERE ' . $clause;
    // approximate end of month (safe enough)
    $params[':to_end'] = $to . '-31 23:59:59';
}

// Query monthly aggregates (year-month)
try{
    $sql = "SELECT DATE_FORMAT(o.created_at, '%Y-%m') AS ym, COUNT(DISTINCT o.id) AS orders_count, COALESCE(SUM(o.total),0) AS total_sales
            FROM orders o
            " . ($where ? $where : '') . "
            GROUP BY ym
            ORDER BY ym DESC
            LIMIT 24"; // last 24 months by default
    $sth = $pdo->prepare($sql);
    $sth->execute($params);
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
}catch(Exception $e){
    $rows = [];
    $msg = 'Failed to load sales data.';
}

// If export=csv (download completed products as CSV)
if($export === 'csv'){
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="sales_completed_products.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Product','Quantity Sold','Revenue']);
  // prepare completedProducts query
  try{
    $prodWhere = ' WHERE oi.status IN (\'completed\', \'delivered\') ';
    if($from && preg_match('/^\d{4}-\d{2}$/', $from)){
      $prodWhere .= ' AND o.created_at >= :from_start';
    }
    if($to && preg_match('/^\d{4}-\d{2}$/', $to)){
      $prodWhere .= ' AND o.created_at <= :to_end';
    }
    $sqlP = "SELECT oi.product_name, SUM(oi.quantity) AS qty_sold, SUM(oi.quantity * oi.price) AS revenue
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         " . $prodWhere . "
         GROUP BY oi.menu_item_id, oi.product_name
         ORDER BY qty_sold DESC
         LIMIT 200";
    $sthP = $pdo->prepare($sqlP);
    $sthP->execute($params);
    while($row = $sthP->fetch(PDO::FETCH_ASSOC)){
      fputcsv($out, [$row['product_name'], (int)$row['qty_sold'], number_format((float)$row['revenue'],2)]);
    }
  }catch(Exception $e){ /* ignore */ }
  fclose($out);
  exit;
}
?>

<div class="card p-3">
  <h3>Sales & Reports</h3>
  <div class="small mb-2">View sales statistics, reports, and generate CSV/PDF for records.</div>

  <?php if($msg): ?><div class="alert alert-danger"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

  <form method="get" class="row g-2 align-items-end" style="margin-bottom:12px;">
    <div class="col-auto">
      <label class="form-label small">From (YYYY-MM)</label>
      <input name="from" class="form-control" placeholder="2024-01" value="<?= htmlspecialchars($from) ?>">
    </div>
    <div class="col-auto">
      <label class="form-label small">To (YYYY-MM)</label>
      <input name="to" class="form-control" placeholder="2025-11" value="<?= htmlspecialchars($to) ?>">
    </div>
    <div class="col-auto">
      <button class="btn btn-primary">Filter</button>
    </div>
    <div class="col-auto">
      <a href="sales-reports.php" class="btn btn-outline-secondary reset-filters">Reset</a>
    </div>
  </form>

  <div class="mb-3">
    <a class="btn btn-success" href="sales-reports.php?export=csv<?php echo $from? '&from='.urlencode($from):''; ?><?php echo $to? '&to='.urlencode($to):''; ?>" target="_blank">Download CSV</a>
    <a class="btn btn-outline-primary ms-2" href="sales-reports.php?export=print<?php echo $from? '&from='.urlencode($from):''; ?><?php echo $to? '&to='.urlencode($to):''; ?>" target="_blank">Download PDF (Print)</a>
  </div>

</div>

<?php
// Completed products summary (only items marked completed/delivered)
try{
    $prodWhere = ' WHERE oi.status IN (\'completed\', \'delivered\') ';
    // if date filters applied, restrict by order created_at
    if($from && preg_match('/^\d{4}-\d{2}$/', $from)){
        $prodWhere .= ' AND o.created_at >= :from_start';
    }
    if($to && preg_match('/^\d{4}-\d{2}$/', $to)){
        $prodWhere .= ' AND o.created_at <= :to_end';
    }

    $sqlP = "SELECT oi.menu_item_id, oi.product_name, SUM(oi.quantity) AS qty_sold, SUM(oi.quantity * oi.price) AS revenue
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             " . $prodWhere . "
             GROUP BY oi.menu_item_id, oi.product_name
             ORDER BY qty_sold DESC
             LIMIT 200";
    $sthP = $pdo->prepare($sqlP);
    $sthP->execute($params);
    $completedProducts = $sthP->fetchAll(PDO::FETCH_ASSOC);
}catch(Exception $e){
    $completedProducts = [];
}
?>

<div class="card p-3" style="margin-top:12px;">
  <h4>Completed Products</h4>
  <div class="small mb-2">Products sold with status completed/delivered</div>
  <div class="table-responsive">
    <table class="table table-striped table-bordered align-middle">
      <thead class="table-light"><tr><th>#</th><th>Product</th><th class="text-end">Quantity Sold</th><th class="text-end">Revenue</th></tr></thead>
      <tbody>
        <?php if(empty($completedProducts)): ?>
          <tr><td colspan="4" class="text-center small text-muted">No completed products found for the selected period.</td></tr>
        <?php else: $i=0; foreach($completedProducts as $cp): $i++; ?>
          <tr>
            <td><?= $i ?></td>
            <td><?= htmlspecialchars($cp['product_name']) ?></td>
            <td class="text-end"><?= number_format((int)$cp['qty_sold']) ?></td>
            <td class="text-end">$<?= number_format((float)$cp['revenue'],2) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
