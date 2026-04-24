<?php

namespace App\Providers;

use App\Events\ParticipantFlagUpdated;
use App\Listeners\SyncFlagsToTransport;
use App\Models\AdlRecord;
use App\Models\User;
use App\Observers\AdlRecordObserver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

// Phase 4 services
use App\Services\AlertService;
use App\Services\SdrDeadlineService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Services\OtpService::class);
        $this->app->singleton(\App\Services\PermissionService::class);
        $this->app->singleton(\App\Services\TransportBridgeService::class);
        $this->app->singleton(\App\Services\MrnService::class);
        $this->app->singleton(\App\Services\NoteTemplateService::class);
        // Phase 4 services (AlertService must be registered before AdlThresholdService,
        // which now receives it via constructor injection)
        $this->app->singleton(AlertService::class);
        $this->app->singleton(SdrDeadlineService::class);
        $this->app->singleton(\App\Services\AdlThresholdService::class);

        // Phase G7 — SMS gateway defaults to null (safe; no paywall risk).
        // Swap to TwilioSmsGateway after contracting Twilio + installing SDK.
        $this->app->bind(\App\Services\Sms\SmsGateway::class, function ($app) {
            return config('services.sms.driver') === 'twilio'
                ? new \App\Services\Sms\TwilioSmsGateway()
                : new \App\Services\Sms\NullSmsGateway();
        });

        // Phase G6 — OCR gateway defaults to null (safe + free). Swap to
        // TesseractOcrGateway when tesseract is installed, or to a paid
        // cloud gateway in production.
        $this->app->bind(\App\Services\Ocr\OcrGateway::class, function ($app) {
            return config('services.ocr.driver') === 'tesseract'
                ? new \App\Services\Ocr\TesseractOcrGateway()
                : new \App\Services\Ocr\NullOcrGateway();
        });
    }

    public function boot(): void
    {
        // IT Admin can do anything (Horizon, etc.)
        Gate::define('viewHorizon', function (User $user) {
            return $user->department === 'it_admin';
        });

        // Transport bridge event listener
        Event::listen(ParticipantFlagUpdated::class, SyncFlagsToTransport::class);

        // ADL threshold breach observer
        AdlRecord::observe(AdlRecordObserver::class);
    }
}
