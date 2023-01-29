<?php

namespace App\Models;

use App\Library\QuotaTrackerFile;
use App\Library\Tool;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;


/**
 * @method static status(bool $true)
 * @method static where(string $string, string $uid)
 * @method static cursor()
 * @method static whereIn(string $string, array $sending_servers_ids)
 * @method static find(int|mixed|string $id)
 * @method static create(array $server)
 * @method static limit(mixed $limit)
 * @method static whereLike(string[] $array, mixed $search)
 * @method static count()
 * @property mixed quota_value
 * @property mixed quota_base
 * @property mixed quota_unit
 * @property mixed quotaTracker
 * @property mixed created_at
 * @property mixed uid
 * @property mixed name
 */
class SendingServer extends Model
{

    // sending server type

    const TYPE_TWILIO            = 'Twilio';
    const TYPE_TWILIOCOPILOT     = 'TwilioCopilot';
    const TYPE_EASYSENDSMS       = 'EasySendSMS';
    const TYPE_CHEAPGLOBALSMS    = 'CheapGlobalSMS';
    const TYPE_SAFARICOM         = 'Safaricom';
    const TYPE_FACILITAMOVEL     = 'FacilitaMovel';
    const TYPE_SMSDELIVERER      = 'Smsdeliverer';
    const TYPE_ROUNDSMS          = 'RoundSMS';
    const TYPE_YOSMS             = 'YoSMS';
    const TYPE_DIGINTRA          = 'Digintra';
    const TYPE_ALLMYSMS          = 'AllMySMS';
    const TYPE_ESOLUTIONS        = 'ESolutions';
    const TYPE_GUPSHUPIO         = 'GupshupIO';
    const TYPE_SEMAPHORE         = 'SemaPhore';
    const TYPE_ESTORESMS         = 'EStoreSMS';
    const TYPE_GOIP              = 'GoIP';
    const TYPE_MAILJET           = 'Mailjet';
    const TYPE_ADVANCEMSGSYS     = 'AdvanceMSGSys';
    const TYPE_UIPAPP            = 'UIPApp';
    const TYPE_SMSFRL            = 'SMSFRL';
    const TYPE_IMARTGROUP        = 'IMartGroup';
    const TYPE_GOSMSFUN          = 'GoSMSFun';
    const TYPE_VIBER             = 'Viber';
    const TYPE_TEXT_CALIBUR      = 'TextCalibur';
    const TYPE_D7NETWORKS        = 'D7Networks';
    const TYPE_WHATSAPP          = 'WhatsApp';
    const TYPE_ARKESEL           = 'Arkesel';
    const TYPE_SAVEWEBHOSTNET    = 'SaveWebHostNet';
    const TYPE_FAST2SMS          = 'Fast2SMS';
    const TYPE_MSG91             = 'MSG91';
    const TYPE_TELEAPI           = 'TeleAPI';
    const TYPE_BUDGETSMS         = 'BudgetSMS';
    const TYPE_OZONEDESK         = 'OzoneDesk';
    const TYPE_SKEBBY            = 'Skebby';
    const TYPE_BULKREPLY         = 'BulkReply';
    const TYPE_BULkSMS4BTC       = 'Bulksms4btc';
    const TYPE_INFOBIP           = 'Infobip';
    const TYPE_CLICKATELLTOUCH   = 'ClickatellTouch';
    const TYPE_CLICKATELLCENTRAL = 'ClickatellCentral';
    const TYPE_ROUTEMOBILE       = 'RouteMobile';
    const TYPE_TEXTLOCAL         = 'TextLocal';
    const TYPE_PLIVO             = 'Plivo';
    const TYPE_PLIVOPOWERPACK    = 'PlivoPowerpack';
    const TYPE_SMSGLOBAL         = 'SMSGlobal';
    const TYPE_BULKSMS           = 'BulkSMS';
    const TYPE_VONAGE            = 'Vonage';
    const TYPE_1S2U              = '1s2u';
    const TYPE_MESSAGEBIRD       = 'MessageBird';
    const TYPE_AMAZONSNS         = 'AmazonSNS';
    const TYPE_TYNTEC            = 'Tyntec';
    const TYPE_WHATSAPPCHATAPI   = 'WhatsAppChatApi';
    const TYPE_KARIXIO           = 'KarixIO';
    const TYPE_SIGNALWIRE        = 'SignalWire';
    const TYPE_TELNYX            = 'Telnyx';
    const TYPE_TELNYXNUMBERPOOL  = 'TelnyxNumberPool';
    const TYPE_BANDWIDTH         = 'Bandwidth';
    const TYPE_SMPP              = 'SMPP';
    const TYPE_ROUTEENET         = 'RouteeNet';
    const TYPE_HUTCHLK           = 'HutchLk';
    const TYPE_TELETOPIASMS      = 'Teletopiasms';
    const TYPE_BROADCASTERMOBILE = 'BroadcasterMobile';
    const TYPE_SOLUTIONS4MOBILES = 'Solutions4mobiles';
    const TYPE_BEEMAFRICA        = 'BeemAfrica';
    const TYPE_BULKSMSONLINE     = 'BulkSMSOnline';
    const TYPE_FLOWROUTE         = 'FlowRoute';
    const TYPE_WAAPI             = 'WaApi';
    const TYPE_ELITBUZZBD        = 'ElitBuzzBD';
    const TYPE_GREENWEBBD        = 'GreenWebBD';
    const TYPE_HABLAMEV2         = 'HablameV2';
    const TYPE_ZAMTELCOZM        = 'ZamtelCoZm';
    const TYPE_CELLCAST          = 'CellCast';
    const TYPE_AFRICASTALKING    = 'AfricasTalking';
    const TYPE_CAIHCOM           = 'CaihCom';
    const TYPE_KECCELSMS         = 'KeccelSMS';
    const TYPE_JOHNSONCONNECT    = 'JohnsonConnect';
    const TYPE_SPEEDAMOBILE      = 'SpeedaMobile';
    const TYPE_SMSALA            = 'SMSala';
    const TYPE_TEXT2WORLD        = 'Text2World';
    const TYPE_ENABLEX           = 'EnableX';
    const TYPE_SPOOFSEND         = 'SpoofSend';
    const TYPE_ALHAJSMS          = 'AlhajSms';
    const TYPE_SENDROIDULTIMATE  = 'SendroidUltimate';
    const TYPE_REALSMS           = 'RealSMS';
    const TYPE_CALLR             = 'Callr';
    const TYPE_SKYETEL           = 'Skyetel';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     * @note important! consider updating the $fillable variable, it will affect some other methods
     */
    protected $fillable = [
            'name',
            'user_id',
            'settings',
            'api_link',
            'port',
            'username',
            'password',
            'route',
            'sms_type',
            'account_sid',
            'auth_id',
            'auth_token',
            'access_key',
            'access_token',
            'secret_access',
            'api_key',
            'api_secret',
            'user_token',
            'project_id',
            'api_token',
            'auth_key',
            'device_id',
            'region',
            'application_id',
            'c1',
            'c2',
            'c3',
            'c4',
            'c5',
            'c6',
            'c7',
            'type',
            'sms_per_request',
            'quota_value',
            'quota_base',
            'quota_unit',
            'custom_order',
            'schedule',
            'custom',
            'status',
            'two_way',
            'plain',
            'mms',
            'voice',
            'whatsapp',
            'source_addr_ton',
            'source_addr_npi',
            'dest_addr_ton',
            'dest_addr_npi',
            'success_keyword',
    ];

    /**
     *  The attributes that should be cast.
     *
     * @var string[]
     */
    protected $casts = [
            'schedule'        => 'boolean',
            'custom'          => 'boolean',
            'status'          => 'boolean',
            'two_way'         => 'boolean',
            'plain'           => 'boolean',
            'mms'             => 'boolean',
            'voice'           => 'boolean',
            'whatsapp'        => 'boolean',
            'quota_value'     => 'integer',
            'quota_base'      => 'integer',
            'sms_per_request' => 'integer',
            'port'            => 'integer',
    ];


    /**
     * Plans
     *
     * @return BelongsToMany
     *
     */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plans_sending_servers');
    }

    /**
     * Customer
     *
     * @return BelongsTo
     */

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Admin
     *
     * @return BelongsTo
     *
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * get custom sending server
     *
     * @return BelongsTo
     *
     */
    public function customSendingServer(): BelongsTo
    {
        return $this->belongsTo(CustomSendingServer::class, 'id', 'server_id');
    }

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
     * Find item by uid.
     *
     * @param $uid
     *
     * @return object
     */
    public static function findByUid($uid): object
    {
        return self::where('uid', $uid)->first();
    }

    /**
     * Active status scope
     *
     * @param        $query
     * @param  bool  $status
     *
     * @return mixed
     */
    public function scopeStatus($query, bool $status): mixed
    {
        return $query->where('status', $status);
    }


    /**
     * Get sending limit types.
     *
     * @return array
     */
    public static function sendingLimitValues(): array
    {
        return [
                'unlimited'      => [
                        'quota_value' => -1,
                        'quota_base'  => -1,
                        'quota_unit'  => 'day',
                ],
                '100_per_minute' => [
                        'quota_value' => 100,
                        'quota_base'  => 1,
                        'quota_unit'  => 'minute',
                ],
                '1000_per_hour'  => [
                        'quota_value' => 1000,
                        'quota_base'  => 1,
                        'quota_unit'  => 'hour',
                ],
                '10000_per_day'  => [
                        'quota_value' => 10000,
                        'quota_base'  => 1,
                        'quota_unit'  => 'day',
                ],
        ];
    }


    /**
     * Get sending server's quota.
     *
     * @return string
     */
    public function getSendingQuota(): string
    {
        return $this->quota_value;
    }

    /**
     * Get sending server's sending quota.
     *
     * @return string
     * @throws Exception
     */
    public function getSendingQuotaUsage(): string
    {
        $tracker = $this->getQuotaTracker();

        return $tracker->getUsage();
    }


    /**
     * Quota display.
     *
     * @return string
     */
    public function displayQuota(): string
    {
        if ($this->quota_value == -1) {
            return __('locale.plans.unlimited');
        }

        return $this->quota_value.'/'.$this->quota_base.' '.__('locale.labels.'.Tool::getPluralParse($this->quota_unit, $this->quota_base));
    }

    /**
     * Quota display.
     *
     * @return string
     */
    public function displayQuotaHtml(): string
    {
        if ($this->quota_value == -1) {
            return __('locale.plans.unlimited');
        }

        return '<code>'.$this->quota_value.'</code>/<code>'.$this->quota_base.' '.__('locale.labels.'.Tool::getPluralParse($this->quota_unit, $this->quota_base)).'</code>';
    }


    /**
     * Get sending server's QuotaTracker.
     *
     * @return mixed
     * @throws Exception
     */
    public function getQuotaTracker(): mixed
    {
        if ( ! $this->quotaTracker) {
            $this->initQuotaTracker();
        }

        return $this->quotaTracker;
    }

    /**
     * Initialize the quota tracker.
     *
     * @throws Exception
     */
    public function initQuotaTracker()
    {
        $this->quotaTracker = new QuotaTrackerFile($this->getSendingQuotaLockFile(), ['start' => $this->created_at->timestamp, 'max' => -1], [$this->getQuotaIntervalString() => $this->getSendingQuota()]);
        $this->quotaTracker->cleanupSeries();
        // @note: in case of multi-process, the following command must be issued manually
        //     $this->renewQuotaTracker();
    }


    /**
     * Clean up the quota tracking files to prevent it from growing too large.
     *
     * @throws Exception
     */
    public function cleanupQuotaTracker()
    {
        // @todo: hard-coded for 1 month
        $this->getQuotaTracker()->cleanupSeries(null, '1 month');
    }

    /**
     * Get sending quota lock file.
     *
     * @return string file path
     */
    public function getSendingQuotaLockFile(): string
    {
        return storage_path("app/server/quota/{$this->uid}");
    }

    /**
     * Get quota starting time.
     *
     * @return string
     */
    public function getQuotaIntervalString(): string
    {
        return "{$this->quota_base} {$this->quota_unit}";
    }

    /**
     * Get quota starting time.
     *
     * @return string
     */
    public function getQuotaStartingTime(): string
    {
        return "{$this->getQuotaIntervalString()} ago";
    }


    /**
     * Increment quota usage.
     *
     * @param  Carbon|null  $timePoint
     *
     * @return mixed
     * @throws Exception
     */
    public function countUsage(Carbon $timePoint = null): mixed
    {
        return $this->getQuotaTracker($timePoint)->add();
    }

    /**
     * Check if user has used up all quota allocated.
     *
     * @return bool
     * @throws Exception
     */
    public function overQuota(): bool
    {
        return ! $this->getQuotaTracker()->check();
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
     * @return string
     */
    public function __toString(): string
    {
        return $this->name;
    }

}
