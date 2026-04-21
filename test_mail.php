<?php
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->boot();

Illuminate\Support\Facades\Mail::raw(
    'Gmail SMTP is wired up for NostosEMR. You will now receive email notifications when Claude steps away to run long tasks. — NostosEMR',
    function ($msg) {
        $msg->to('tj@nostos.tech')->subject('NostosEMR: Gmail SMTP Connected Successfully');
    }
);
echo "Email sent successfully.\n";
