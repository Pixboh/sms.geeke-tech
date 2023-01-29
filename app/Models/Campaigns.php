<?php

namespace App\Models;

use App\Exceptions\CampaignErrorException;
use App\Exceptions\CampaignPausedException;
use App\Helpers\Helper;
use App\Jobs\RunCampaignJob;
use App\Library\RouletteWheel;
use App\Library\SMSCounter;
use App\Library\Tool;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use Throwable;
use Illuminate\Support\Facades\Log as MailLog;

/**
 * @method static where(string $string, string $uid)
 * @method static create(array $array)
 * @method static find($campaign_id)
 * @method static cursor()
 * @method static whereIn(string $string, mixed $ids)
 * @method static count()
 */
class Campaigns extends SendCampaignSMS
{

    /**
     * Campaign status
     */
    const STATUS_NEW        = 'new';
    const STATUS_QUEUED     = 'queued';
    const STATUS_SENDING    = 'sending';
    const STATUS_FAILED     = 'failed';
    const STATUS_DELIVERED  = 'delivered';
    const STATUS_CANCELLED  = 'cancelled';
    const STATUS_SCHEDULED  = 'scheduled';
    const STATUS_PROCESSING = 'processing';
    const STATUS_PAUSED     = 'paused';


    /*
     * Campaign type
     */
    const TYPE_ONETIME   = 'onetime';
    const TYPE_RECURRING = 'recurring';


    // Campaign settings
    const WORKER_DELAY = 1;

    public static $serverPools   = [];
    public static $senderIdPools = [];
    protected     $sendingSevers = null;
    protected     $senderIds     = null;
    protected     $currentSubscription;

    protected $fillable = [
            'user_id',
            'campaign_name',
            'message',
            'media_url',
            'language',
            'gender',
            'sms_type',
            'upload_type',
            'status',
            'reason',
            'api_key',
            'cache',
            'timezone',
            'schedule_time',
            'schedule_type',
            'frequency_cycle',
            'frequency_amount',
            'frequency_unit',
            'recurring_end',
            'run_at',
            'delivery_at',
            'batch_id',
            'running_pid',
    ];

    protected $dates = ['created_at', 'updated_at', 'run_at', 'delivery_at', 'schedule_time', 'recurring_end'];

    /**
     * Bootstrap any application services.
     */
    public static function boot()
    {
        parent::boot();

        // Create uid when creating list.
        static::creating(function ($item) {
            // Create new uid
            $uid = uniqid();
            while (self::where('uid', $uid)->count() > 0) {
                $uid = uniqid();
            }
            $item->uid = $uid;
        });
    }


    /**
     * get user
     *
     * @return BelongsTo
     *
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * get customer
     *
     * @return BelongsTo
     *
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'user_id');
    }

    /**
     * get sending server
     *
     * @return BelongsTo
     *
     */
    public function sendingServer(): BelongsTo
    {
        return $this->belongsTo(SendingServer::class);
    }

    /**
     * get reports
     *
     * @return HasMany
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Reports::class, 'campaign_id', 'id');
    }

    /**
     * associate with contact groups
     *
     * @return HasMany
     */
    public function contactList(): HasMany
    {
        return $this->hasMany(CampaignsList::class, 'campaign_id');
    }

    /**
     * associate with recipients
     *
     * @return HasMany
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignsRecipients::class, 'campaign_id');
    }


    /**
     * Get schedule recurs available values.
     *
     * @return array
     */
    public static function scheduleCycleValues(): array
    {
        return [
                'daily'   => [
                        'frequency_amount' => 1,
                        'frequency_unit'   => 'day',
                ],
                'monthly' => [
                        'frequency_amount' => 1,
                        'frequency_unit'   => 'month',
                ],
                'yearly'  => [
                        'frequency_amount' => 1,
                        'frequency_unit'   => 'year',
                ],
        ];
    }

    /**
     * Frequency time unit options.
     *
     * @return array
     */
    public static function timeUnitOptions(): array
    {
        return [
                ['value' => 'day', 'text' => 'day'],
                ['value' => 'week', 'text' => 'week'],
                ['value' => 'month', 'text' => 'month'],
                ['value' => 'year', 'text' => 'year'],
        ];
    }


    /**
     * contact count
     *
     * @param  false  $cache
     *
     * @return mixed|null
     */
    public function contactCount(bool $cache = false): mixed
    {
        if ($cache) {
            return $this->readCache('ContactCount', 0);
        }
        $list_ids = $this->contactList()->select('contact_list_id')->cursor()->pluck('contact_list_id')->all();

        return Contacts::whereIn('group_id', $list_ids)->where('status', 'subscribe')->count();

    }

    /**
     * show delivered count
     *
     * @param  false  $cache
     *
     * @return int
     */
    public function deliveredCount(bool $cache = false): int
    {
        if ($cache) {
            return $this->readCache('DeliveredCount', 0);
        }

        return $this->reports()->where('campaign_id', $this->id)->where('status', 'like', '%Delivered%')->count();
    }

    /**
     * show failed count
     *
     * @param  false  $cache
     *
     * @return int
     */
    public function failedCount(bool $cache = false): int
    {
        if ($cache) {
            return $this->readCache('FailedDeliveredCount', 0);
        }

        return $this->reports()->where('campaign_id', $this->id)->where('status', 'not like', '%Delivered%')->count();
    }

    /**
     * show not delivered count
     *
     * @param  false  $cache
     *
     * @return int
     */
    public function notDeliveredCount(bool $cache = false): int
    {
        if ($cache) {
            return $this->readCache('NotDeliveredCount', 0);
        }

        return $this->reports()->where('campaign_id', $this->id)->where('status', 'like', '%Sent%')->count();
    }

    public function nextScheduleDate($startDate, $interval, $intervalCount)
    {

        return match ($interval) {
            'month' => $startDate->addMonthsNoOverflow($intervalCount),
            'day'   => $startDate->addDay($intervalCount),
            'week'  => $startDate->addWeek($intervalCount),
            'year'  => $startDate->addYearsNoOverflow($intervalCount),
            default => null,
        };
    }

    /**
     * Update Campaign cached data.
     *
     * @param  null  $key
     */
    public function updateCache($key = null)
    {
        // cache indexes
        $index = [
                'DeliveredCount'       => function ($campaign) {
                    return $campaign->deliveredCount();
                },
                'FailedDeliveredCount' => function ($campaign) {
                    return $campaign->failedCount();
                },
                'NotDeliveredCount'    => function ($campaign) {
                    return $campaign->notDeliveredCount();
                },
                'ContactCount'         => function ($campaign) {
                    return $campaign->contactCount(true);
                },
        ];

        // retrieve cached data
        $cache = json_decode($this->cache, true);
        if (is_null($cache)) {
            $cache = [];
        }

        if (is_null($key)) {
            foreach ($index as $key => $callback) {
                $cache[$key] = $callback($this);
            }
        } else {
            $callback    = $index[$key];
            $cache[$key] = $callback($this);
        }

        // write back to the DB
        $this->cache = json_encode($cache);
        $this->save();
    }

    /**
     * Retrieve Campaign cached data.
     *
     * @param $key
     * @param  null  $default
     *
     * @return mixed
     */
    public function readCache($key, $default = null): mixed
    {
        $cache = json_decode($this->cache, true);
        if (is_null($cache)) {
            return $default;
        }
        if (array_key_exists($key, $cache)) {
            if (is_null($cache[$key])) {
                return $default;
            } else {
                return $cache[$key];
            }
        } else {
            return $default;
        }
    }


    /**
     * get active plan sending servers
     *
     * @return PlansSendingServer
     */
    public function activePlanSendingServers(): PlansSendingServer
    {
        return PlansSendingServer::where('plan_id', $this->user->customer->activeSubscription()->plan_id);
    }

    /**
     * get active customer sending servers
     *
     * @return SendingServer
     */
    public function activeCustomerSendingServers(): SendingServer
    {
        return SendingServer::where('user_id', $this->user->id)->where('status', true);
    }

    public function getCurrentSubscription()
    {
        if (empty($this->currentSubscription)) {
            $this->currentSubscription = $this->user->customer->activeSubscription();
        }

        return $this->currentSubscription;
    }

    public function getSendingServers()
    {
        if ( ! is_null($this->sendingSevers)) {
            return $this->sendingSevers;
        }

        $sending_server_id   = CampaignsSendingServer::where('campaign_id', $this->id)->first()->sending_server_id;
        $this->sendingSevers = SendingServer::find($sending_server_id);

        return $this->sendingSevers;
    }

    /**
     * get sender ids
     *
     * @return array
     */
    public function getSenderIds(): array
    {

        if ( ! is_null($this->senderIds)) {
            return $this->senderIds;
        }

        $result = CampaignsSenderid::where('campaign_id', $this->id)->cursor()->map(function ($sender_id) {
            return [$sender_id->sender_id, $sender_id->id];
        })->all();

        $assoc = [];
        foreach ($result as $server) {
            [$key, $fitness] = $server;
            $assoc[$key] = $fitness;
        }

        $this->senderIds = $assoc;

        return $this->senderIds;
    }

    /**
     * mark campaign as queued to processing
     */
    public function running()
    {
        $this->status = self::STATUS_PROCESSING;
        $this->run_at = Carbon::now();
        $this->save();
    }

    /**
     * mark campaign as failed
     *
     * @param  null  $reason
     */
    public function failed($reason = null)
    {
        $this->status = self::STATUS_FAILED;
        $this->reason = $reason;
        $this->save();
    }

    /**
     * set campaign warning
     *
     * @param  null  $reason
     */
    public function warning($reason = null)
    {
        $this->reason = $reason;
        $this->save();
    }

    public function preparedDataToSend()
    {

        try {
            // clean up the tracker to prevent the log file from growing very big
            $this->user->customer->cleanupQuotaTracker();

            // set campaign queued to processing
            $this->running();

            // Reset max_execution_time so that command can run for a long time without being terminated
            Tool::resetMaxExecutionTime();

            $this->singleProcess();

        } catch (Exception $exception) {
            $this->failed($exception->getMessage());
        } catch (Throwable $e) {
            $this->failed($e->getMessage());
        }

    }

    /**
     * @return $this
     */
    public function refreshStatus(): Campaigns
    {
        $campaign     = self::find($this->id);
        $this->status = $campaign->status;
        $this->save();

        return $this;
    }


    /**
     * Mark the campaign as delivered.
     */
    public function delivered()
    {
        $this->status      = self::STATUS_DELIVERED;
        $this->delivery_at = Carbon::now();
        $this->save();
    }

    /**
     * Mark the campaign as delivered.
     */
    public function cancelled()
    {
        $this->status = self::STATUS_CANCELLED;
        $this->save();
    }

    /**
     * Mark the campaign as processing.
     */
    public function processing()
    {
        $this->status      = self::STATUS_PROCESSING;
        $this->running_pid = getmypid();
        $this->run_at      = Carbon::now();
        $this->save();
    }

    /**
     * check if the campaign is in the "Processing Status"
     *
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->status == self::STATUS_PROCESSING;
    }

    /**
     * render sms with tag
     *
     * @param $msg
     * @param $data
     *
     * @return string|string[]
     */
    public function renderSMS($msg, $data): array|string
    {
        preg_match_all('~{(.*?)}~s', $msg, $datas);

        foreach ($datas[1] as $value) {
            if (array_key_exists($value, $data)) {
                $msg = preg_replace("/\b$value\b/u", $data[$value], $msg);
            } else {
                $msg = str_ireplace($value, '', $msg);
            }
        }

        return str_ireplace(["{", "}"], '', $msg);
    }


    /**
     * get coverage
     *
     * @return array
     */
    public function getCoverage(): array
    {
        $data          = [];
        $plan_coverage = PlansCoverageCountries::where('plan_id', $this->user->customer->activeSubscription()->plan->id)->cursor();
        foreach ($plan_coverage as $coverage) {
            $data[$coverage->country->country_code] = json_decode($coverage->options, true);
        }

        return $data;

    }

    /**
     * reset server pools
     */
    public static function resetServerPools()
    {
        self::$serverPools = [];
    }

    /**
     * @throws Exception
     */
    public function pickSendingServer()
    {
        $selection = $this->getSendingServers();

        // do not raise an exception, just wait if sending servers are available but exceeding sending limit
        $blacklisted = [];

        while (true) {
            $id = RouletteWheel::generate($selection);
            if (empty(self::$serverPools[$id])) {
                $server = SendingServer::find($id);
                if ($server->custom) {
                    $server['custom_info'] = $server->customSendingServer;
                }
                $server->cleanupQuotaTracker();
                self::$serverPools[$id] = $server;
            }

            if (self::$serverPools[$id]->overQuota()) {
                // log every 60 seconds
                if ( ! array_key_exists($id, $blacklisted) || time() - $blacklisted[$id] >= 60) {
                    $blacklisted[$id] = time();
                    $this->warning(sprintf('Sending server `%s` exceeds sending limit, skipped', self::$serverPools[$id]->name));
                }

                // if all sending servers are blacklisted
                if (sizeof($blacklisted) == sizeof($selection)) {
                    $this->warning(__('locale.campaigns.sending_server_exceed_sending_limit'));
                    sleep(30);
                }

                continue;
            }

            return self::$serverPools[$id];
        }
    }

    /**
     * pick sender id
     *
     *
     */
    public function pickSenderIds(): int|string
    {
        $selection = array_values(array_flip($this->getSenderIds()));
        shuffle($selection);
        while (true) {
            $element = array_pop($selection);
            if ($element) {
                return (string) $element;
            }
        }
    }

    /**
     * get sms type
     *
     * @return string
     */
    public function getSMSType(): string
    {
        $sms_type = $this->sms_type;

        if ($sms_type == 'plain') {
            return '<span class="badge bg-primary text-uppercase me-1 mb-1">'.__('locale.labels.plain').'</span>';
        }
        if ($sms_type == 'unicode') {
            return '<span class="badge bg-primary text-uppercase me-1 mb-1">'.__('locale.labels.unicode').'</span>';
        }

        if ($sms_type == 'voice') {
            return '<span class="badge bg-success text-uppercase me-1 mb-1">'.__('locale.labels.voice').'</span>';
        }

        if ($sms_type == 'mms') {
            return '<span class="badge bg-info text-uppercase me-1 mb-1">'.__('locale.labels.mms').'</span>';
        }

        if ($sms_type == 'whatsapp') {
            return '<span class="badge bg-warning text-uppercase mb-1">'.__('locale.labels.whatsapp').'</span>';
        }

        return '<span class="badge bg-danger text-uppercase mb-1">'.__('locale.labels.invalid').'</span>';
    }

    /**
     * get sms type
     *
     * @return string
     */
    public function getCampaignType(): string
    {
        $sms_type = $this->schedule_type;

        if ($sms_type == 'onetime') {
            return '<div>
                        <span class="badge badge-light-info text-uppercase me-1 mb-1">'.__('locale.labels.scheduled').'</span>
                        <p class="text-muted">'.Tool::customerDateTime($this->schedule_time).'</p>
                    </div>';
        }
        if ($sms_type == 'recurring') {
            return '<div>
                        <span class="badge badge-light-success text-uppercase me-1 mb-1">'.__('locale.labels.recurring').'</span>
                        <p class="text-muted">'.__('locale.labels.every').' '.$this->displayFrequencyTime().'</p>
                        <p class="text-muted">'.__('locale.labels.next_schedule_time').': '.Tool::formatDateTime($this->schedule_time).'</p>
                        <p class="text-muted">'.__('locale.labels.end_time').': '.Tool::formatDateTime($this->recurring_end).'</p>
                    </div>';
        }

        return '<span class="badge badge-light-primary text-uppercase me-1 mb-1">'.__('locale.labels.normal').'</span>';
    }

    /**
     * Display frequency time
     *
     * @return string
     */
    public function displayFrequencyTime(): string
    {
        return $this->frequency_amount.' '.Tool::getPluralParse($this->frequency_unit, $this->frequency_amount);
    }


    /**
     * get campaign status
     *
     * @return string
     */
    public function getStatus(): string
    {
        $status = $this->status;

        if ($status == self::STATUS_FAILED || $status == self::STATUS_CANCELLED) {
            return '<div>
                        <span class="badge bg-danger text-uppercase me-1 mb-1">'.__('locale.labels.'.$status).'</span>
                        <p class="text-muted" data-toggle="tooltip" data-placement="top" title="'.$this->reason.'">'.str_limit($this->reason, 40).'</p>
                    </div>';
        }
        if ($status == self::STATUS_SENDING || $status == self::STATUS_PROCESSING) {
            return '<div>
                        <span class="badge bg-primary text-uppercase mr-1 mb-1">'.__('locale.labels.'.$status).'</span>
                        <p class="text-muted">'.__('locale.labels.run_at').': '.Tool::customerDateTime($this->run_at).'</p>
                    </div>';
        }

        if ($status == self::STATUS_SCHEDULED) {
            return '<span class="badge bg-info text-uppercase mr-1 mb-1">'.__('locale.labels.scheduled').'</span>';
        }

        if ($status == self::STATUS_PAUSED) {
            return '<div>
                        <span class="badge bg-warning text-uppercase mr-1 mb-1">'.__('locale.labels.paused').'</span>
                        <p class="text-muted">'.__('locale.labels.paused_at').': '.Tool::customerDateTime($this->updated_at).'</p>
                    </div>';
        }
        if ($status == self::STATUS_NEW || $status == self::STATUS_QUEUED) {
            return '<span class="badge bg-primary text-uppercase mr-1 mb-1">'.__('locale.labels.'.$status).'</span>';
        }


        return '<div>
                        <span class="badge bg-success text-uppercase mr-1 mb-1">'.__('locale.labels.delivered').'</span>
                        <p class="text-muted">'.__('locale.labels.delivered_at').': '.Tool::customerDateTime($this->delivery_at).'</p>
                    </div>';
    }


    /**
     * get route key by uid
     *
     * @return string
     */
    public function getRouteKeyName(): string
    {
        return 'uid';
    }


    /**
     * make ready to send
     *
     * @return void
     */
    public function queued(): void
    {
        $this->status = self::STATUS_QUEUED;
        $this->save();
    }


    /**
     * Check if the campaign is ready to start.
     *
     * @return bool
     */
    public function isQueued(): bool
    {
        return $this->status == self::STATUS_QUEUED;
    }

    /**
     * get another running process
     *
     * @return bool
     */
    public function occupiedByOtherAnotherProcess(): bool
    {
        if ( ! function_exists('posix_getpid')) {
            return false;
        }

        return ( ! is_null($this->running_pid) && posix_getpgid($this->running_pid));
    }


    /**
     * Get the delay time before sending.
     *
     * @return float|int
     */
    public function getDelayInSeconds(): float|int
    {
        $now = Carbon::now();

        if ($now->gte($this->run_at)) {
            return 0;
        } else {
            return $this->run_at->diffInSeconds($now);
        }
    }

    /**
     * Queue campaign for sending.
     *
     *
     * @return void
     */
    public function queue(): void
    {
        $this->queued();
        $job = (new RunCampaignJob($this))->delay($this->getDelayInSeconds());
        dispatch($job);
    }


    /**
     * Re-queue the campaign for sending.
     *
     * @return void
     */
    public function requeue(): void
    {
        // clear all campaign's sending jobs which are being queued
        $this->clearAllJobs();

        // and queue again
        $this->queue();
    }


    /**
     * Queue campaign for sending.
     */
    public function clearAllJobs()
    {
        // cleanup jobs and system_jobs
        $systemJobs = SystemJob::where('name', RunCampaignJob::class)->where('data', $this->id)->get();
        foreach ($systemJobs as $systemJob) {
            $systemJob->clear();
        }
    }


    /**
     * Overwrite the delete() method to also clear the pending jobs.
     *
     * @return bool|null
     */
    public function delete()
    {
        $this->clearAllJobs();

        return parent::delete();
    }


    /**
     * Check if campaign is paused.
     *
     * @return bool
     */
    public function isPaused(): bool
    {
        return $this->status == self::STATUS_PAUSED;
    }

    /**
     * @throws Exception
     */
    public function track_message($response, $subscriber, $server)
    {

        $params = [
                'message_id'        => $response->id,
                'customer_id'       => $response->user_id,
                'sending_server_id' => $server->id,
                'campaign_id'       => $this->id,
                'contact_id'        => $subscriber->id,
                'contact_group_id'  => $subscriber->group_id,
                'status'            => $response->status,
        ];

        if ( ! isset($params['runtime_message_id'])) {
            $params['runtime_message_id'] = $response->id;
        }

        TrackingLog::create($params);

        $this->user->customer->countUsage();
        $server->countUsage();

    }

    /**
     * Get Pending Subscribers
     * Select only subscribers that are ready for sending. Those whose status is `blacklisted`, `pending` or `unconfirmed` are not included.
     */
    public function getPendingContacts($partition, $callback)
    {

        $builder = $this->getContactList()
                        ->whereRaw(sprintf(Helper::table('contacts').'.phone NOT IN (SELECT phone FROM %s t JOIN %s s ON t.contact_id = s.id WHERE t.campaign_id = %s)', Helper::table('tracking_logs'), Helper::table('contacts'), $this->id));

        if ( ! is_null($partition)) {
            // retrieve the subscribers list for this partition only
            // partitioning is based on email to prevent one email from being taken by more than one process
            // The idea is to generate a unique integer for an email address, then partition base on the number
            [$partitionId, $count] = $partition;
            $builder = $builder->whereRaw(sprintf('CONV(SUBSTR(MD5(%s),1, 4), 16, 10) MOD %s = %s', Helper::table('contacts.phone'), $count, $partitionId));
        }

        $total   = $builder->count();
        $builder = $builder->groupBy('contacts.phone');

        $page  = 1;
        $limit = 2000;

        while ($builder->count()) { // IMPORTANT: count() for a grouped query will always return 0 or 1
            // @note eager loading will result in a very long query
            // which could exceed the [max_allowed_packet] setting of MySQL
            // so the value of $limit must be considered carefully

            $subscribers = $builder->limit($limit)->get();
            $callback($subscribers, $page, $total);
            $page += 1;
        }
    }


    /**
     * send campaign
     *
     * @throws NumberParseException
     * @throws Throwable
     */
    public function singleProcess($partition = null)
    {

        // check if the method is trigger by a child process (triggered by runMultiProcess method)
        $asChildProcess = ! ($partition == null);

        // try/catch to make sure child process does not stop without reporting any error
        try {
            $i              = 0;
            $cost           = 0;
            $sending_server = $this->getSendingServers();
            $coverage       = $this->getCoverage();
            $this->getPendingContacts($partition, function ($subscribers, $page, $total) use (&$i, $sending_server, $coverage, $cost) {
                //       MailLog::info("Fetching page $page (count: {$subscribers->count()})");

                foreach ($subscribers as $subscriber) {
                    while ($this->user->customer->overQuota()) {
                        // throw new \Exception('Customer has reached sending limit');
                        //     MailLog::warning(sprintf('Campaign `%s` (%s) halted, user exceeds sending limit', $this->campaign_name, $this->uid));
                        sleep(60);
                    }

                    $i += 1;
                    //       MailLog::info("Sending to subscriber `{$subscriber->phone}` ({$i}/{$total})");

                    if ($this->refreshStatus()->isPaused()) {
                        $this->updateCache();
                        throw new CampaignPausedException();
                    }


                    $renderData   = $subscriber->toArray();
                    $customFields = $subscriber->custom_fields;
                    foreach ($customFields as $field) {
                        $renderData[$field->tag] = $field->value;
                    }

                    $check_sender_id = $this->getSenderIds();
                    $message         = $this->renderSMS($this->message, $renderData);

                    if (count($check_sender_id) > 0) {
                        $sender_id = $this->pickSenderIds();
                    } else {
                        $sender_id = null;
                    }
                    $phone = str_replace(['+', '(', ')', '-', ' '], '', $subscriber->phone);

                    if (Tool::validatePhone($phone)) {

                        try {
                            $phoneUtil         = PhoneNumberUtil::getInstance();
                            $phoneNumberObject = $phoneUtil->parse('+'.$phone);
                            $country_code      = $phoneNumberObject->getCountryCode();
                            if (is_array($coverage) && array_key_exists($country_code, $coverage)) {

                                if ($this->sms_type == 'plain' || $this->sms_type == 'unicode') {
                                    $cost = $coverage[$country_code]['plain_sms'];
                                }

                                if ($this->sms_type == 'voice') {
                                    $cost = $coverage[$country_code]['voice_sms'];
                                }

                                if ($this->sms_type == 'mms') {
                                    $cost = $coverage[$country_code]['mms_sms'];
                                }

                                if ($this->sms_type == 'whatsapp') {
                                    $cost = $coverage[$country_code]['whatsapp_sms'];
                                }

                                $sms_counter  = new SMSCounter();
                                $message_data = $sms_counter->count($message);
                                $sms_count    = $message_data->messages;

                                $price = $cost * $sms_count;

                                $preparedData['user_id']   = $this->user_id;
                                $preparedData['phone']     = $phone;
                                $preparedData['sender_id'] = $sender_id;
                                $preparedData['message']   = $message;
                                $preparedData['sms_type']  = $this->sms_type;
                                $preparedData['cost']      = (int) $price;
                                $preparedData['status']    = null;

                            } else {

                                $sms_counter  = new SMSCounter();
                                $message_data = $sms_counter->count($message);
                                $sms_count    = $message_data->messages;

                                $price = 1 * $sms_count;

                                $preparedData['user_id']   = $this->user_id;
                                $preparedData['phone']     = $phone;
                                $preparedData['sender_id'] = $sender_id;
                                $preparedData['message']   = $message;
                                $preparedData['sms_type']  = $this->sms_type;
                                $preparedData['cost']      = (int) $price;
                                $preparedData['status']    = "Permission to send an SMS has not been enabled for the region indicated by the 'To' number: ".$phone;

                            }
                        } catch (NumberParseException $exception) {
                            $sms_counter  = new SMSCounter();
                            $message_data = $sms_counter->count($message);
                            $sms_count    = $message_data->messages;

                            $price = 1 * $sms_count;

                            $preparedData['user_id']   = $this->user_id;
                            $preparedData['phone']     = $phone;
                            $preparedData['sender_id'] = $sender_id;
                            $preparedData['message']   = $message;
                            $preparedData['sms_type']  = $this->sms_type;
                            $preparedData['cost']      = (int) $price;
                            $preparedData['status']    = $exception->getMessage();

                        }
                    } else {
                        $sms_counter  = new SMSCounter();
                        $message_data = $sms_counter->count($message);
                        $sms_count    = $message_data->messages;

                        $price = 1 * $sms_count;

                        $preparedData['user_id']   = $this->user_id;
                        $preparedData['phone']     = $phone;
                        $preparedData['sender_id'] = $sender_id;
                        $preparedData['message']   = $message;
                        $preparedData['sms_type']  = $this->sms_type;
                        $preparedData['cost']      = (int) $price;
                        $preparedData['status']    = __('locale.customer.invalid_phone_number', ['phone' => $phone]);
                    }

                    if ($this->user->customer->getOption('send_spam_message') == 'no') {
                        $spamWords = SpamWord::all()->filter(function ($spamWord) use ($message) {
                            if (true === str_contains(strtolower($message), strtolower($spamWord->word))) {
                                return true;
                            }

                            return false;
                        });

                        if ($spamWords->count()) {
                            $preparedData['status'] = 'Your message contains spam words.';
                        }
                    }

                    $preparedData['campaign_id']    = $this->id;
                    $preparedData['sending_server'] = $sending_server;

                    $status = null;
                    if ($this->sms_type == 'plain' || $this->sms_type == 'unicode') {
                        $status = $this->sendPlainSMS($preparedData);
                    }

                    if ($this->sms_type == 'voice') {

                        $preparedData['language'] = $this->language;
                        $preparedData['gender']   = $this->gender;

                        $status = $this->sendVoiceSMS($preparedData);
                    }

                    if ($this->sms_type == 'mms') {
                        $preparedData['media_url'] = $this->media_url;
                        $status                    = $this->sendMMS($preparedData);
                    }

                    if ($this->sms_type == 'whatsapp') {
                        if (isset($this->media_url)) {
                            $preparedData['media_url'] = $this->media_url;
                        }
                        $status = $this->sendWhatsApp($preparedData);
                    }

                    $this->track_message($status, $subscriber, $sending_server);


                    if (substr_count($status, 'Delivered') == 1) {
                        $user = User::find($this->user->id);
                        if ($user->sms_unit != '-1') {
                            if ($price > $user->sms_unit) {
                                throw new CampaignPausedException(sprintf("Campaign `%s` (%s) halted, customer exceeds sms credit", $this->campaign_name, $this->uid));
                            }

                            $remaining_balance = $user->sms_unit - $price;
                            $user->lockForUpdate();
                            $user->update([
                                    'sms_unit' => $remaining_balance,
                            ]);
                        }
                        $this->updateCache('DeliveredCount');
                    } else {
                        $this->updateCache('FailedDeliveredCount');
                    }
                }
            });

            //    MailLog::info("{$i} phones sent for the process");

            // only mark campaign as done when running as its own process
            // as a child process, just finish and leave the parent process to update campaign status
            if ( ! $asChildProcess) {
                $this->delivered();
            }
        } catch (CampaignPausedException $e) {
            // just finish
            MailLog::warning('Campaign paused: '.$e->getMessage());
        } catch (CampaignErrorException $e) {
            // just finish
            MailLog::warning('Campaign terminated: '.$e->getMessage());
        } catch (Exception $e) {
            MailLog::error($e->getMessage());
            $this->failed($e->getMessage());
        } finally {

            self::resetServerPools();
            // update the campaign cache information
            $this->updateCache();
        }
    }


    /**
     * start campaign
     *
     * @return void
     */
    public function run(): void
    {

        try {
//            if ( ! $this->refreshStatus()->isQueued()) {
//                $this->warning("Campaign ID: {$this->id} is not queued or already started");
//
//                return;
//            }
//
//            if ($this->refreshStatus()->occupiedByOtherAnotherProcess()) {
//                $this->warning("Campaign ID: {$this->id} is occupied by another process PID: {$this->running_pid}");
//
//                return;
//            }

            $this->user->customer->cleanupQuotaTracker();

            $this->processing();
//            MailLog::info('Starting campaign `'.$this->name.'`');

            Tool::resetMaxExecutionTime();

            // Only run multi-process if pcntl is enabled
            $processes = (int) $this->user->customer->getOption('max_process');
            if (extension_loaded('pcntl') && function_exists('pcntl_fork') && $processes > 1) {
                //       MailLog::info('Run in multi-process mode');
                $this->runMultiProcesses();
            } else {
                //       MailLog::info('Run in single-process mode');
                $this->singleProcess();
            }
            //    MailLog::info('Finish campaign `'.$this->name.'`');

        } catch (Exception $ex) {
            $this->failed($ex->getMessage());
            //    MailLog::error('Starting campaign failed. '.$ex->getMessage());
        } catch (Throwable $e) {
            $this->failed($e->getMessage());
            //  MailLog::error('Starting campaign failed. '.$e->getMessage());
        }
    }


    /**
     * Start the campaign, using PHP fork() to launch multiple processes.
     *
     * @return void
     */
    public function runMultiProcesses(): void
    {
        // processes to fork
        $count = (int) $this->user->customer->getOption('max_process');
        $count = ($count > 2) ? 2 : $count;

//        MailLog::info("Forking {$count} process(es)");
        $parentPid = $this->running_pid;
        $children  = [];
        for ($i = 0; $i < $count; $i += 1) {
            $pid = pcntl_fork();

            if ( ! $pid) {

                DB::reconnect('mysql');

                //      MailLog::info(sprintf('Start child process %s of %s (forked from %s)', $i + 1, $count, $parentPid));
                sleep(self::WORKER_DELAY);
                $partition = [$i, $count];
                try {
                    $this->singleProcess($partition);
                } catch (NumberParseException|Throwable $e) {
                    $this->failed($e->getMessage());
                }

                exit($i + 1);
                // end child process
            } else {
                $children[] = $pid;
            }
        }

        // wait for child processes to finish
        foreach ($children as $ignored) {
            $pid = pcntl_wait($status);
            if (pcntl_wifexited($status)) {
                $code = pcntl_wexitstatus($status);
//                MailLog::info("Child process $pid finished, status code: $code");
            } else {
                MailLog::warning("Child process $pid did not normally exit");
                $this->failed("Child process $pid did not normally exit");
            }
        }

        // after all child processes are done
        $this->refreshStatus();

        if ($this->status == self::STATUS_PROCESSING) {
            $this->delivered();
        }
    }


    /*Version 3.5*/

    /**
     * return contacts data
     *
     */
    public function getContactList()
    {
        if ($this->contactList->isEmpty()) {
            return (new Contacts)->limit(0);
        }

        $list_id = (new CampaignsList)->where('campaign_id', $this->id)->pluck('contact_list_id')->unique()->all();

        return Contacts::whereIn('group_id', $list_id)->where('status', 'subscribe');
    }
}
