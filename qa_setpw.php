<?php
require 'd:/laragon/www/raportku23102026/db.php';
$rows = db_query('SELECT u.username, u.role FROM users u WHERE u.role IN ("superadmin","admin") LIMIT 5');
foreach ($rows as $r) echo $r['username'].' '.$r['role'].PHP_EOL;
