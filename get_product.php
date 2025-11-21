<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

$productId = (int)($_GET['product'] ?? 0);

if($productId <= 0){
    echo json_encode(['ok' => false, 'error' => 'Invalid product ID']);
    exit;
}

try {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, name, category, price, image, availability, buy_count, rating_total, rating_count FROM menu_items WHERE id = ?');
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if($product){
        // Get user ratings and comments
        $ratings = [];
        $ratingStmt = $pdo->prepare('
            SELECT ur.rating, ur.created_at, u.name, u.email
            FROM user_ratings ur
            LEFT JOIN users u ON u.id = ur.user_id
            WHERE ur.menu_item_id = ?
            ORDER BY ur.created_at DESC
            LIMIT 10
        ');
        $ratingStmt->execute([$productId]);
        $ratings = $ratingStmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['ok' => true, 'product' => $product, 'ratings' => $ratings]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Product not found']);
    }
} catch(Exception $e){
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}
?>
