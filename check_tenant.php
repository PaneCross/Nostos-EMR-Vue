<?php
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$rows = DB::select('SELECT name, auto_logout_minutes FROM shared_tenants LIMIT 5');
foreach ($rows as $r) {
    echo $r->name . ': auto_logout_minutes=' . $r->auto_logout_minutes . PHP_EOL;
}
