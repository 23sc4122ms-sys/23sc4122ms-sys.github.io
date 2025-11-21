<?php
// admin_list_files.php
// Returns a JSON list of files in the project for admins only
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

$role = strtolower($_SESSION['user_role'] ?? '');
if(!in_array($role, ['admin','owner'])){
  http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit;
}

$base = realpath(__DIR__);
$excludeDirs = ['vendor','node_modules','.git','uploads','control','main','css','js'];
$files = [];

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
foreach($it as $file){
  if(!$file->isFile()) continue;
  $rel = substr($file->getRealPath(), strlen($base) + 1);
  // skip this admin script
  if($rel === basename(__FILE__)) continue;
  // skip excluded dirs
  $skip = false;
  foreach($excludeDirs as $ex){ if(strpos($rel, $ex . DIRECTORY_SEPARATOR) === 0) { $skip = true; break; } }
  if($skip) continue;
  $files[] = [
    'path' => str_replace('\\','/',$rel),
    'size' => $file->getSize(),
    'mtime' => date('c', $file->getMTime())
  ];
}

echo json_encode(['ok'=>true,'files'=>$files]);
exit;
?>