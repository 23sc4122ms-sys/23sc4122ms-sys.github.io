<?php
// admin_read_file.php
// Read a file's content (admin-only). Limits size to avoid large binary dumps.
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();
$role = strtolower($_SESSION['user_role'] ?? '');
if(!in_array($role, ['admin','owner'])){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }

$path = isset($_GET['path']) ? trim($_GET['path']) : '';
if(!$path){ echo json_encode(['ok'=>false,'error'=>'Missing path']); exit; }

$base = realpath(__DIR__);
$real = realpath($base . DIRECTORY_SEPARATOR . $path);
if(!$real || strpos($real, $base) !== 0){ echo json_encode(['ok'=>false,'error'=>'Invalid path']); exit; }

// limit to text files under 200KB
$maxSize = 200 * 1024;
$size = filesize($real);
if($size > $maxSize){ echo json_encode(['ok'=>false,'error'=>'File too large']); exit; }

// quick mime check
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($real);
if(strpos($mime, 'text') === false && strpos($mime, 'json') === false && strpos($mime, 'xml') === false && strpos($mime, 'javascript') === false && strpos($mime, 'php') === false){
  echo json_encode(['ok'=>false,'error'=>'Not a readable text file','mime'=>$mime]); exit;
}

$content = file_get_contents($real);
// return base64 if binary-ish
echo json_encode(['ok'=>true,'path'=>str_replace('\\','/',$path),'mime'=>$mime,'content'=>$content]);
exit;
?>