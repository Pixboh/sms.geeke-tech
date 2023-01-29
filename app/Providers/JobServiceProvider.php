<?php

namespace App\Providers;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Queue;

class JobServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        Queue::before(function (JobProcessing $event) {
            $job               = $this->getJobObject($event);
            $systemJob         = $job->getSystemJob();
            $systemJob->job_id = $event->job->getJobId();
            $systemJob->save();

            $job->setStarted();
        });

        // mark the SystemJob record as DONE
        Queue::after(function (JobProcessed $event) {
            $job = $this->getJobObject($event);
            $job->setDone();
        });

        // mark the SystemJob record as FAILED
        Queue::failing(function (JobFailed $event) {
            $error = $event->exception->getMessage();
            $error .= "\r\n".$event->exception->getTraceAsString();
            $job   = $this->getJobObject($event); // Job object, not SystemJob model
            $job->setFailed($error);
        });
    }


    /**
     * Register the application services.
     */
    private function getJobObject($event)
    {
        $data = $event->job->payload();

        return unserialize($data['data']['command']);
    }

}
