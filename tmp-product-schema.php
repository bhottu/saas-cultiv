<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$rows = DB::select('PRAGMA table_info(products)');
foreach ($rows as $r) {
    echo $r->name . '|';
}
echo PHP_EOL;

$rows2 = DB::select('PRAGMA table_info(warehouses)');
foreach ($rows2 as $r) {
    echo $r->name . '|';
}
echo PHP_EOL;
