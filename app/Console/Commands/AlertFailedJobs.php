<?php

namespace App\Console\Commands;

use App\Notifications\FailedJobsAlertNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class AlertFailedJobs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:alert-failed';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'E-mail ADMIN_EMAIL quando novos jobs caírem em failed_jobs desde a última checagem';

    public function handle(): int
    {
        $adminEmail = config('services.admin.email');

        if (blank($adminEmail)) {
            $this->warn('ADMIN_EMAIL não configurado — pulando checagem de failed_jobs.');

            return self::SUCCESS;
        }

        // Dedupe via cache (mesma ideia da coluna last_due_soon_notified_at em Bill, só que aqui não há
        // uma linha própria pra carimbar): guarda o maior id de failed_jobs já alertado.
        $lastAlertedId = (int) Cache::get('failed_jobs:last_alerted_id', 0);

        $newFailedCount = DB::table('failed_jobs')->where('id', '>', $lastAlertedId)->count();

        if ($newFailedCount === 0) {
            return self::SUCCESS;
        }

        $maxId = (int) DB::table('failed_jobs')->max('id');

        Notification::route('mail', $adminEmail)->notify(new FailedJobsAlertNotification($newFailedCount));

        Cache::forever('failed_jobs:last_alerted_id', $maxId);

        $this->info("Alertado sobre {$newFailedCount} job(s) falhado(s).");

        return self::SUCCESS;
    }
}
