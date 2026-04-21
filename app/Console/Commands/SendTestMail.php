<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendTestMail extends Command
{
    protected $signature = 'mail:send-test {to}';
    protected $description = 'Send a test email to verify SMTP configuration';

    public function handle(): int
    {
        $to = $this->argument('to');

        $this->info("Sending test email to {$to}...");

        Mail::raw(
            "Gmail SMTP is wired up for NostosEMR.\n\nYou will now receive email notifications when Claude steps away to run long tasks.\n\n— NostosEMR",
            function ($message) use ($to) {
                $message->to($to)
                        ->subject('NostosEMR: Gmail SMTP Connected Successfully');
            }
        );

        $this->info('Email sent successfully!');

        return Command::SUCCESS;
    }
}
