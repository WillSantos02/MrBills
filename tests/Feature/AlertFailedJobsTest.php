<?php

namespace Tests\Feature;

use App\Notifications\FailedJobsAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlertFailedJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.admin.email' => 'admin@example.com']);
    }

    private function insertFailedJob(): int
    {
        return (int) DB::table('failed_jobs')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'connection' => 'rabbitmq',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Exception: something broke',
            'failed_at' => now(),
        ]);
    }

    public function test_alerts_when_there_are_new_failed_jobs(): void
    {
        Notification::fake();

        $this->insertFailedJob();
        $this->insertFailedJob();

        $this->artisan('queue:alert-failed');

        Notification::assertSentOnDemand(
            FailedJobsAlertNotification::class,
            fn (FailedJobsAlertNotification $notification, array $channels, object $notifiable) => $notifiable->routes['mail'] === 'admin@example.com'
        );
    }

    public function test_does_not_alert_again_for_the_same_failed_jobs(): void
    {
        Notification::fake();

        $this->insertFailedJob();

        $this->artisan('queue:alert-failed');
        $this->artisan('queue:alert-failed');

        Notification::assertSentOnDemandTimes(FailedJobsAlertNotification::class, 1);
    }

    public function test_alerts_again_only_for_jobs_that_failed_after_the_last_check(): void
    {
        Notification::fake();

        $this->insertFailedJob();
        $this->artisan('queue:alert-failed');

        $this->insertFailedJob();
        $this->artisan('queue:alert-failed');

        Notification::assertSentOnDemandTimes(FailedJobsAlertNotification::class, 2);
    }

    public function test_does_not_alert_when_there_are_no_failed_jobs(): void
    {
        Notification::fake();

        $this->artisan('queue:alert-failed');

        Notification::assertNothingSent();
    }

    public function test_does_not_alert_when_admin_email_is_not_configured(): void
    {
        config(['services.admin.email' => null]);
        Notification::fake();

        $this->insertFailedJob();

        $this->artisan('queue:alert-failed');

        Notification::assertNothingSent();
    }

    public function test_cache_key_tracks_the_highest_alerted_failed_job_id(): void
    {
        Notification::fake();

        $id = $this->insertFailedJob();

        $this->artisan('queue:alert-failed');

        $this->assertSame($id, Cache::get('failed_jobs:last_alerted_id'));
    }
}
