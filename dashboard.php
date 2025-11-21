<?php
// Dashboard fragment — fetch values from DB
include_once __DIR__ . '/db.php';
$pdo = getPDO();

// totals
$totalOrders = (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$totalUsers = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalRevenue = $pdo->query("SELECT IFNULL(SUM(total),0) FROM orders")->fetchColumn();

// order item statuses
$pendingOrders = (int) $pdo->query("SELECT COUNT(*) FROM order_items WHERE status IN ('processing','pending')")->fetchColumn();
$completedOrders = (int) $pdo->query("SELECT COUNT(*) FROM order_items WHERE status IN ('completed','delivered')")->fetchColumn();

// sales by category (for chart)
$stmt = $pdo->query("SELECT COALESCE(mi.category,'Uncategorized') AS category, SUM(oi.quantity * oi.price) AS sales FROM order_items oi JOIN menu_items mi ON mi.id = oi.menu_item_id GROUP BY category ORDER BY sales DESC LIMIT 6");
$chart_labels = [];
$chart_data = [];
while($row = $stmt->fetch()){
    $chart_labels[] = $row['category'];
    $chart_data[] = (float) $row['sales'];
}

?>

<div class="container py-3">
  <div class="row g-3">
    <!-- Card 1: Total Orders -->
    <div class="col-md-4">
      <div class="card shadow-sm p-3">
        <h5>Total Orders</h5>
        <p class="fs-4"><?php echo number_format($totalOrders); ?></p>
      </div>
    </div>

    <!-- Card 2: Total Users -->
    <div class="col-md-4">
      <div class="card shadow-sm p-3">
        <h5>Total Users</h5>
        <p class="fs-4"><?php echo number_format($totalUsers); ?></p>
      </div>
    </div>

    <!-- Card 3: Total Revenue -->
    <div class="col-md-4">
      <div class="card shadow-sm p-3">
        <h5>Total Revenue</h5>
        <p class="fs-4">$<?php echo number_format((float)$totalRevenue,2); ?></p>
      </div>
    </div>

    <!-- Card 4: Pending Orders -->
    <div class="col-md-6">
      <div class="card shadow-sm p-3">
        <h5>Pending Orders</h5>
        <p class="fs-4"><?php echo number_format($pendingOrders); ?></p>
      </div>
    </div>

    <!-- Card 5: Completed Orders -->
    <div class="col-md-6">
      <div class="card shadow-sm p-3">
        <h5>Completed Orders</h5>
        <p class="fs-4"><?php echo number_format($completedOrders); ?></p>
      </div>
    </div>
    
      <!-- Sales chart -->
      <div class="container py-3">
        <div class="row">
          <div class="col-12 mt-4">
            <div class="card shadow-sm p-3" style="margin-top:24px;">
              <h5>Sales chart</h5>
              <div class="d-flex justify-content-center align-items-center">
                <div class="sales-chart-container" style="max-width:360px; width:100%; min-height:200px;">
                  <canvas id="salesChart"></canvas>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

  </div>
</div>

<script>
// Expose chart data to the admin dashboard JS
window.dashboardChart = window.dashboardChart || {};
window.dashboardChart.labels = <?php echo json_encode($chart_labels); ?>;
window.dashboardChart.data = <?php echo json_encode($chart_data); ?>;
</script>
