<?php
// test_db_connection.php — attempts to connect using db.php and lists tables
require_once __DIR__ . '/db.php';
echo "Testing DB connection using settings from db.php\n";
try{
    $pdo = getPDO();
    echo "Connected to database: " . (defined('DB_NAME') ? DB_NAME : '(unknown)') . "\n";
    // get server version
    $ver = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    echo "MySQL server version: " . $ver . "\n";
    // list tables
    $sth = $pdo->query('SHOW TABLES');
    $tables = $sth->fetchAll(PDO::FETCH_NUM);
    if(!$tables) {
        echo "No tables found (or insufficient privileges).\n";
    } else {
        echo "Tables in database:\n";
        foreach($tables as $t){ echo " - " . $t[0] . "\n"; }
    }
    exit(0);
}catch(Exception $e){
    echo "Connection failed: " . $e->getMessage() . "\n";
    exit(1);
}
