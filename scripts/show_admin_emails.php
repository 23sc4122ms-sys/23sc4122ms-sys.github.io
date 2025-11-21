<?php
// Utility: show admin/owner emails from the `users` table
// Usage (browser): http://localhost/JapanFoodOrder/scripts/show_admin_emails.php
// Usage (CLI): & 'C:\xampp\php\php.exe' 'C:\xampp\htdocs\JapanFoodOrder\scripts\show_admin_emails.php'

require_once __DIR__ . '/../db.php';
$pdo = getPDO();

try{
    $sth = $pdo->prepare("SELECT id,name,email,role,created_at FROM users WHERE role IN ('owner','admin') ORDER BY id ASC");
    $sth->execute();
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
}catch(Exception $e){
    echo "Query failed: " . $e->getMessage() . "\n";
    exit(1);
}

// If run in CLI, output plain text. Otherwise, render a small HTML table.
if(php_sapi_name() === 'cli'){
    if(empty($rows)){
        echo "No owner/admin accounts found.\n";
        exit(0);
    }
    echo "Found " . count($rows) . " owner/admin account(s):\n\n";
    foreach($rows as $r){
        echo "ID: " . $r['id'] . "\n";
        echo "Name: " . ($r['name'] ?: '(no name)') . "\n";
        echo "Email: " . $r['email'] . "\n";
        echo "Role: " . $r['role'] . "\n";
        echo "Created: " . $r['created_at'] . "\n";
        echo "-------------------------\n";
    }
    exit(0);
}

?><!doctype html>
<html><head><meta charset="utf-8"><title>Owner/Admin emails</title>
<style>body{font-family:Segoe UI,Arial,Helvetica,sans-serif;margin:18px}table{border-collapse:collapse;width:800px}th,td{padding:8px;border:1px solid #ddd;text-align:left}th{background:#f6f6f6}</style>
</head><body>
  <h2>Owner / Admin accounts</h2>
  <?php if(empty($rows)): ?>
    <p>No owner/admin accounts found.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Created</th></tr></thead>
      <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?php echo htmlspecialchars($r['id']) ?></td>
            <td><?php echo htmlspecialchars($r['name'] ?: '(no name)') ?></td>
            <td><?php echo htmlspecialchars($r['email']) ?></td>
            <td><?php echo htmlspecialchars($r['role']) ?></td>
            <td><?php echo htmlspecialchars($r['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <p style="margin-top:18px;color:#666">Note: remove this script after use for security.</p>
</body></html>