<?php
// inspect_orders.php — simple debug page to view `orders` and `order_items` table contents
// Usage: open this file in your browser (only run on a local/dev environment)
require_once __DIR__ . '/db.php';
$pdo = getPDO();

function render_table($rows){
    if(!$rows || !is_array($rows) || count($rows) === 0) { echo '<div class="small text-muted">No rows</div>'; return; }
    echo '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin-bottom:16px;width:100%;">';
    echo '<thead><tr>';
    foreach(array_keys($rows[0]) as $h) echo '<th style="text-align:left;padding:6px;background:#f4f4f4;">'.htmlspecialchars($h).'</th>';
    echo '</tr></thead>';
    echo '<tbody>';
    foreach($rows as $r){ echo '<tr>'; foreach($r as $c) echo '<td style="padding:6px;">'.htmlspecialchars((string)$c).'</td>'; echo '</tr>'; }
    echo '</tbody></table>';
}

try{
    echo '<div style="font-family:Arial,Helvetica,sans-serif;padding:12px;max-width:1100px;margin:8px auto;">';
    echo '<h2>DB Connection</h2>';
    echo '<div class="small">Connected to: ' . htmlspecialchars(DB_HOST . ' / ' . DB_NAME) . '</div>';

    echo '<h3>orders table structure</h3>';
    try{ $cols = $pdo->query('DESCRIBE orders')->fetchAll(); render_table($cols); }catch(Exception $e){ echo '<div class="text-danger">Failed to DESCRIBE orders: ' . htmlspecialchars($e->getMessage()) . '</div>'; }

    echo '<h3>order_items table structure</h3>';
    try{ $cols2 = $pdo->query('DESCRIBE order_items')->fetchAll(); render_table($cols2); }catch(Exception $e){ echo '<div class="text-danger">Failed to DESCRIBE order_items: ' . htmlspecialchars($e->getMessage()) . '</div>'; }

    echo '<h3>Recent orders (limit 30)</h3>';
    try{ $rows = $pdo->query('SELECT * FROM orders ORDER BY created_at DESC LIMIT 30')->fetchAll(); render_table($rows); }catch(Exception $e){ echo '<div class="text-danger">Failed to SELECT orders: ' . htmlspecialchars($e->getMessage()) . '</div>'; }

    echo '<h3>Recent order_items (limit 50)</h3>';
    try{ $rows2 = $pdo->query('SELECT * FROM order_items ORDER BY created_at DESC LIMIT 50')->fetchAll(); render_table($rows2); }catch(Exception $e){ echo '<div class="text-danger">Failed to SELECT order_items: ' . htmlspecialchars($e->getMessage()) . '</div>'; }

    echo '<div style="margin-top:18px;font-size:12px;color:#666;">This is a debug helper — remove it when finished.</div>';
    echo '</div>';
}catch(Exception $e){
    echo '<div style="padding:12px;color:red;">Error: '.htmlspecialchars($e->getMessage()).'</div>';
}

?>
