<?php

namespace App\Models;

use Carbon\Carbon;
use DB;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * @method static create(array $array)
 * @method static find($id)
 * @method static where(string $string, string $class)
 */
class SystemJob extends Model
{
    // status
    const STATUS_NEW       = 'new';
    const STATUS_RUNNING   = 'running';
    const STATUS_DONE      = 'done';
    const STATUS_FAILED    = 'failed';
    const STATUS_CANCELLED = 'cancelled';


    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
            'name', 'status', 'object_id', 'object_name', 'last_error',
    ];


    /**
     * Set job as started.
     *
     * @return void
     */
    public function setStarted(): void
    {
        $this->status   = self::STATUS_RUNNING;
        $this->start_at = Carbon::now();
        $this->save();
    }


    /**
     * Set job as finished.
     *
     * @return void
     */
    public function setDone(): void
    {
        $this->status = self::STATUS_DONE;
        $this->end_at = Carbon::now();
        $this->save();
    }


    /**
     * Set job as failed.
     *
     * @param $msg
     *
     * @return void
     */
    public function setFailed($msg = null): void
    {
        $this->last_error = $msg;
        $this->status     = self::STATUS_FAILED;
        $this->end_at     = Carbon::now();
        $this->save();
    }


    /**
     * Set job status as cancelled.
     *
     * @return void
     */
    public function setCancelled(): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->end_at = Carbon::now();
        $this->save();
    }

    /**
     * run time
     *
     * @return string
     */
    public function runTime(): string
    {
        return gmdate('H:i:s', -$this->updated_at->diffInSeconds($this->created_at, false));
    }


    /**
     * Stop the job as well as delete the Job records.
     *
     * @return void
     * @throws Throwable
     */
    public function clear(): void
    {
        // delete all system_jobs & jobs
        DB::transaction(function () {
            // delete jobs
            $jobs = Job::get();
            foreach ($jobs as $job) {
                $json = json_decode($job->payload, true);
                try {
                    $j = unserialize($json['data']['command']);
                    if ($j->getSystemJob()->id == $this->id) {
                        Job::destroy($job->id);
                    }
                } catch (Exception) {
                    // delete orphan job
                    Job::destroy($job->id);
                }
            }

            // delete system_jobs
            self::destroy($this->id);
        });
    }


    /**
     * Delete the all (Laravel) job records which have not been reserved.
     *
     * @return void
     * @throws Throwable
     */
    public function clearJobs(): void
    {
        // delete all system_jobs & jobs
        DB::transaction(function () {
            // delete jobs
            $jobs = Job::get();
            foreach ($jobs as $job) {
                $json = json_decode($job->payload, true);
                try {
                    $j = unserialize($json['data']['command']);
                    if ($j->getSystemJob()->id == $this->id) {
                        Job::destroy($job->id);
                    }
                } catch (Exception) {
                    // delete orphan job
                    Job::destroy($job->id);
                }
            }
        });
    }


    /**
     * Check if system job is cancelled.
     *
     * @return bool
     */
    public function isCancelled(): bool
    {
        return $this->status == self::STATUS_CANCELLED;
    }

    /**
     * Check if system job is new.
     *
     * @return bool
     */
    public function isNew(): bool
    {
        return $this->status == self::STATUS_NEW;
    }

    /**
     * Check if system job is failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status == self::STATUS_FAILED;
    }


    /**
     * Check if system job is new.
     *
     * @return bool
     *
     */
    public function isRunning(): bool
    {
        $data = json_decode($this->data);

        return ! (in_array($data->status, ['failed', 'done']) || $this->status == self::STATUS_CANCELLED);
    }


    /**
     * Get new pending jobs.
     *
     * @return mixed
     */
    public static function getNewJobs(): mixed
    {
        return self::where('status', '=', self::STATUS_NEW);
    }

    /**
     * Get value from data.
     *
     * @param $name
     *
     * @return mixed|null
     */
    public function getData($name): mixed
    {
        $data = json_decode($this->data, true);

        return $data[$name] ?? null;
    }


    /**
     * get status
     *
     * @return string
     */

    public function getStatus(): string
    {
        $status = $this->status;

        if ($status == self::STATUS_FAILED || $status == self::STATUS_CANCELLED) {
            return '<div class="badge bg-danger text-uppercase me-1 mb-1"><span>'.__('locale.labels.failed').'</span></div>';
        }
        if ($status == self::STATUS_NEW) {
            return '<div class="badge bg-primary text-uppercase me-1 mb-1"><span>'.__('locale.labels.queued').'</span></div>';
        }

        if ($status == self::STATUS_RUNNING) {
            return '<div class="badge bg-primary text-uppercase me-1 mb-1"><span>'.__('locale.labels.processing').'</span></div>';
        }

        return '<div class="badge bg-success text-uppercase me-1 mb-1"><span>'.__('locale.labels.finished').'</span></div>';
    }

    /**
     * get status
     *
     * @return string
     */

    public function getStatusMessage(): string
    {
        $status = $this->status;

        if ($status == self::STATUS_FAILED || $status == self::STATUS_CANCELLED) {
            return 'Import contacts are failed or Cancel';
        }
        if ($status == self::STATUS_NEW) {
            return 'Import contacts are queued';
        }

        if ($status == self::STATUS_RUNNING) {
            return 'Import contacts are running';
        }

        return 'Import contacts was successfully imported';
    }

}
