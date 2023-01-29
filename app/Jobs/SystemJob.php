<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use App\Models\SystemJob as SystemJobModel;

class SystemJob extends Job implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels, Dispatchable;

    protected $systemJob;


    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($objectId = null, $objectName = null)
    {
        // init the SystemJob record to keep track of
        $systemJob = SystemJobModel::create([
                'status'      => SystemJobModel::STATUS_NEW,
                'name'        => get_called_class(),
                'object_id'   => $objectId,
                'object_name' => $objectName,
        ]);

        $this->systemJob = $systemJob;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        //
    }


    /**
     * Create a new job instance.
     *
     * @return mixed
     */
    public function getSystemJob(): mixed
    {
        return SystemJobModel::find($this->systemJob->id);
    }


    /**
     * check the SystemJob record as RUNNING
     *
     * @return void
     */
    public function setStarted(): void
    {
        $systemJob = $this->getSystemJob();
        $systemJob->setStarted();
    }

    /**
     * Check the SystemJob record as DONE
     *
     * @return void
     */
    public function setDone(): void
    {
        $systemJob = $this->getSystemJob();
        $systemJob->setDone();
    }

    /**
     * Mark the SystemJob record as FAILED
     *
     * @param  null  $msg
     *
     * @return void
     */
    public function setFailed($msg = null): void
    {
        $systemJob = $this->getSystemJob();
        $systemJob->setFailed($msg);
    }
}
