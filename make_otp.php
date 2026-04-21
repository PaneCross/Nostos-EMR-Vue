<?php
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$user = App\Models\User::where('email','margaret.primary_care@sunrisepace-demo.test')->first();
if (!$user) { echo 'USER NOT FOUND'; exit; }
$otp = rand(100000, 999999);
$hash = \Illuminate\Support\Facades\Hash::make((string)$otp);
Illuminate\Support\Facades\DB::table('shared_otp_codes')->insert([
    'user_id'    => $user->id,
    'code_hash'  => $hash,
    'expires_at' => now()->addMinutes(10),
    'ip_address' => '127.0.0.1',
    'attempts'   => 0,
    'created_at' => now(),
]);
echo $otp;
