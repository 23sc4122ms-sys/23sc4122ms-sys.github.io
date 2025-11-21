<?php
session_start();
// Destroy session and return JSON if requested via AJAX
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
// Clear session array
$_SESSION = [];
// Delete session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'], $params['secure'], $params['httponly']
    );
}
session_destroy();
if($isAjax){
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'redirect' => 'index.php']);
    exit;
}
// Fallback redirect
header('Location: index.php');
exit;
