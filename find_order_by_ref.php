<?php
// find_order_by_ref.php
// Usage: open in browser: find_order_by_ref.php?q=ORD-F5056B
// Searches textual columns in `orders` and `order_items` for the provided query string.
require_once __DIR__ . '/db.php';
$pdo = getPDO();

$q = trim((string)($_GET['q'] ?? ''));
if($q === ''){
    echo '<div style="font-family:Arial,Helvetica,sans-serif;padding:12px;max-width:900px;margin:8px auto;">';
    echo '<h3>Find Order By Ref</h3>';
    echo '<form method="get"><input name="q" placeholder="ORD-F5056B" class="form-control" style="padding:6px;width:300px;display:inline-block;margin-right:6px"> <button class="btn">Search</button></form>';
    echo '</div>';
    exit;
}

function print_rows($title, $rows){
    echo '<h3>'.htmlspecialchars($title).' (' . count($rows) . ')</h3>';
    if(!$rows) { echo '<div class="small text-muted">No matches</div>'; return; }
    echo '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin-bottom:16px;width:100%;">';
    echo '<thead><tr>';
    foreach(array_keys($rows[0]) as $h) echo '<th style="text-align:left;padding:6px;background:#f4f4f4;">'.htmlspecialchars($h).'</th>';
    echo '</tr></thead><tbody>';
    foreach($rows as $r){ echo '<tr>'; foreach($r as $c) echo '<td style="padding:6px;">'.htmlspecialchars((string)$c).'</td>'; echo '</tr>'; }
    echo '</tbody></table>';
}

echo '<div style="font-family:Arial,Helvetica,sans-serif;padding:12px;max-width:1100px;margin:8px auto;">';
echo '<h2>Search results for: ' . htmlspecialchars($q) . '</h2>';

// Inspect orders columns and find textual columns to search
try{
    $cols = $pdo->query('DESCRIBE orders')->fetchAll(PDO::FETCH_ASSOC);
    $textCols = [];
    foreach($cols as $c){ $type = strtolower($c['Type']); if(strpos($type,'char')!==false || strpos($type,'text')!==false || strpos($type,'varchar')!==false) $textCols[] = $c['Field']; }
    if(!empty($textCols)){
        $orParts = [];
        $params = [];
        foreach($textCols as $i => $col){ $orParts[] = "`$col` LIKE :q"; }
        $sql = 'SELECT * FROM orders WHERE ' . implode(' OR ', $orParts) . ' ORDER BY created_at DESC LIMIT 100';
        $sth = $pdo->prepare($sql);
        $sth->execute([':q' => "%$q%"]);
        $orders = $sth->fetchAll(PDO::FETCH_ASSOC);
        print_rows('Matching orders', $orders);
    } else {
        echo '<div class="text-muted">No textual columns in orders to search.</div>';
    }
}catch(Exception $e){ echo '<div class="text-danger">Error searching orders: ' . htmlspecialchars($e->getMessage()) . '</div>'; }

// Search order_items textual columns
try{
    $cols = $pdo->query('DESCRIBE order_items')->fetchAll(PDO::FETCH_ASSOC);
    $textCols = [];
    foreach($cols as $c){ $type = strtolower($c['Type']); if(strpos($type,'char')!==false || strpos($type,'text')!==false || strpos($type,'varchar')!==false) $textCols[] = $c['Field']; }
    if(!empty($textCols)){
        $orParts = [];
        foreach($textCols as $col){ $orParts[] = "`$col` LIKE :q"; }
        $sql = 'SELECT * FROM order_items WHERE ' . implode(' OR ', $orParts) . ' ORDER BY created_at DESC LIMIT 200';
        $sth = $pdo->prepare($sql);
        $sth->execute([':q' => "%$q%"]);
        $items = $sth->fetchAll(PDO::FETCH_ASSOC);
        print_rows('Matching order_items', $items);
    }
}catch(Exception $e){ echo '<div class="text-danger">Error searching order_items: ' . htmlspecialchars($e->getMessage()) . '</div>'; }

// If orders found with an id, show combined items for the first match (helpful)
if(!empty($orders) && count($orders) > 0){
    $firstId = (int)$orders[0]['id'];
    try{
        $sth = $pdo->prepare('SELECT * FROM order_items WHERE order_id = :oid ORDER BY created_at ASC');
        $sth->execute([':oid' => $firstId]);
        $it = $sth->fetchAll(PDO::FETCH_ASSOC);
        print_rows('Order #' . $firstId . ' items', $it);
    }catch(Exception $e){ echo '<div class="text-danger">Error fetching items: ' . htmlspecialchars($e->getMessage()) . '</div>'; }
}

echo '<div style="margin-top:18px;font-size:12px;color:#666;">Debug helper — remove when finished.</div>';
echo '</div>';

?>
