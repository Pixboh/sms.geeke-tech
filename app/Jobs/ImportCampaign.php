<?php

namespace App\Jobs;

use App\Library\SMSCounter;
use App\Library\Tool;
use App\Models\Campaigns;
use App\Models\SpamWord;
use App\Models\User;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use Throwable;

class ImportCampaign implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $customer_id;
    protected $campaign_id;
    protected $list;
    protected $db_fields;
    protected $campaign_fields;

    /**
     * Delete the job if its models no longer exist.
     *
     * @var bool
     */
    public $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     *
     * @param $customer_id
     * @param $campaign_id
     * @param $list
     * @param $db_fields
     * @param $campaign_fields
     */
    public function __construct($customer_id, $campaign_id, $list, $db_fields, $campaign_fields)
    {
        $this->list            = $list;
        $this->customer_id     = $customer_id;
        $this->campaign_id     = $campaign_id;
        $this->db_fields       = $db_fields;
        $this->campaign_fields = $campaign_fields;
    }


    /**
     * render sms with tag
     *
     * @param $msg
     * @param $data
     *
     * @return string|string[]
     */
    public function renderSMS($msg, $data)
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
     * Execute the job.
     *
     * @return void
     * @throws NumberParseException
     * @throws Throwable
     */
    public function handle()
    {

        $prepareForTemplateTag = [];
        $cutting_array         = [];

        $lines           = $this->list;
        $db_fields       = $this->db_fields;
        $campaign_fields = $this->campaign_fields;

        $user     = User::find($this->customer_id);
        $campaign = Campaigns::find($this->campaign_id);

        if ($this->batch()->cancelled()) {
            $campaign->cancelled();

            return;
        }

        $cutting_system = $user->customer->getOption('cutting_system');
        $sending_server = $campaign->getSendingServers();
        $coverage       = $campaign->getCoverage();

        $key        = 0;
        $cost       = 0;
        $total_unit = 0;

        if ($cutting_system == 'yes') {
            $cutting_value = $user->customer->getOption('cutting_value');
            $cutting_unit  = $user->customer->getOption('cutting_unit');
            $cutting_logic = $user->customer->getOption('cutting_logic');

            if ($cutting_unit == 'percentage') {
                $cutting_value = ($cutting_value / 100) * $lines->count();
            }

            $cutting_amount = (int) round($cutting_value);

            if ($cutting_logic == 'random') {
                $cutting_array = $lines->random($cutting_amount)->all();
            }

            if ($cutting_logic == 'start') {
                $cutting_array = $lines->slice(0, $cutting_amount)->all();
            }

            if ($cutting_logic == 'end') {
                $cutting_array = $lines->slice(-$cutting_amount)->all();
            }
        }

        collect($cutting_array)->chunk(1000)->each(function ($lines) use (&$prepareForTemplateTag, $cost, $campaign, $db_fields, $user, &$key, &$total_unit, $sending_server, $coverage, $campaign_fields) {

            $check_sender_id = $campaign->getSenderIds();

            foreach ($lines as $line) {
                $message = null;
                if (count($check_sender_id) > 0) {
                    $sender_id = $campaign->pickSenderIds();
                } else {
                    $sender_id = null;
                }

                $data = array_combine($db_fields, $line);
                if ($data['phone'] != null) {

                    $phone = str_replace(['+', '(', ')', '-', ' '], '', $data['phone']);

                    $sms_type  = $campaign->sms_type;
                    $sms_count = 1;

                    if (Tool::validatePhone($phone)) {

                        if ($campaign->message) {
                            $b           = array_map('trim', $line);
                            $modify_data = array_combine($campaign_fields, $b);
                            $message     = $this->renderSMS($campaign->message, $modify_data);

                            $sms_counter  = new SMSCounter();
                            $message_data = $sms_counter->count($message);
                            $sms_count    = $message_data->messages;

                            if ($sms_type == 'plain') {
                                if ($message_data->encoding == 'UTF16') {
                                    $sms_type = 'unicode';
                                }
                            }
                        }

                        try {
                            $phoneUtil         = PhoneNumberUtil::getInstance();
                            $phoneNumberObject = $phoneUtil->parse('+'.$phone);
                            if ($phoneUtil->isPossibleNumber($phoneNumberObject)) {
                                $country_code = $phoneNumberObject->getCountryCode();
                                if (is_array($coverage) && array_key_exists($country_code, $coverage)) {
                                    if ($sms_type == 'plain' || $sms_type == 'unicode') {
                                        $cost = $coverage[$country_code]['plain_sms'];
                                    }

                                    if ($sms_type == 'voice') {
                                        $cost = $coverage[$country_code]['voice_sms'];
                                    }

                                    if ($sms_type == 'mms') {
                                        $cost = $coverage[$country_code]['mms_sms'];
                                    }

                                    if ($sms_type == 'whatsapp') {
                                        $cost = $coverage[$country_code]['whatsapp_sms'];
                                    }

                                    $sms_price  = $cost * $sms_count;
                                    $total_unit += (int) $sms_price;

                                    $key++;


                                    $preparedData['id']        = $key;
                                    $preparedData['user_id']   = $user->id;
                                    $preparedData['phone']     = $phone;
                                    $preparedData['sender_id'] = $sender_id;
                                    $preparedData['message']   = $message;
                                    $preparedData['sms_type']  = $sms_type;
                                    $preparedData['cost']      = (int) $sms_price;
                                    $preparedData['status']    = 'Delivered';
                                } else {

                                    $sms_price  = 1 * $sms_count;
                                    $total_unit += (int) $sms_price;

                                    $key++;

                                    $preparedData['id']        = $key;
                                    $preparedData['user_id']   = $user->id;
                                    $preparedData['phone']     = $phone;
                                    $preparedData['sender_id'] = $sender_id;
                                    $preparedData['message']   = $message;
                                    $preparedData['sms_type']  = $sms_type;
                                    $preparedData['cost']      = (int) $sms_price;
                                    $preparedData['status']    = "Permission to send an SMS has not been enabled for the region indicated by the 'To' number: ".$phone;
                                }
                            } else {

                                $sms_price  = 1 * $sms_count;
                                $total_unit += (int) $sms_price;

                                $key++;

                                $preparedData['id']        = $key;
                                $preparedData['user_id']   = $user->id;
                                $preparedData['phone']     = $phone;
                                $preparedData['sender_id'] = $sender_id;
                                $preparedData['message']   = $message;
                                $preparedData['sms_type']  = $sms_type;
                                $preparedData['cost']      = (int) $sms_price;
                                $preparedData['status']    = __('locale.customer.invalid_phone_number', ['phone' => $phone]);
                            }
                        } catch (NumberParseException $exception) {

                            $sms_price  = 1 * $sms_count;
                            $total_unit += (int) $sms_price;

                            $key++;

                            $preparedData['id']        = $key;
                            $preparedData['user_id']   = $user->id;
                            $preparedData['phone']     = $phone;
                            $preparedData['sender_id'] = $sender_id;
                            $preparedData['message']   = $message;
                            $preparedData['sms_type']  = $sms_type;
                            $preparedData['cost']      = (int) $sms_price;
                            $preparedData['status']    = $exception->getMessage();
                        }

                    } else {

                        $total_unit += $sms_count;

                        $key++;

                        $preparedData['id']        = $key;
                        $preparedData['user_id']   = $user->id;
                        $preparedData['phone']     = $phone;
                        $preparedData['sender_id'] = $sender_id;
                        $preparedData['message']   = null;
                        $preparedData['sms_type']  = $sms_type;
                        $preparedData['cost']      = $sms_count;
                        $preparedData['status']    = __('locale.customer.invalid_phone_number', ['phone' => $phone]);
                    }

                    if ($user->customer->getOption('send_spam_message') == 'no') {
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


                    $preparedData['campaign_id']    = $campaign->id;
                    $preparedData['sending_server'] = $sending_server;

                    $prepareForTemplateTag[] = $preparedData;
                }
            }
        });

        $insertData = Tool::check_diff_multi($lines->all(), $cutting_array);

        collect($insertData)->chunk(1000)->each(function ($lines) use (&$prepareForTemplateTag, $cost, $campaign, $db_fields, $user, &$key, &$total_unit, $sending_server, $coverage, $campaign_fields) {

            $check_sender_id = $campaign->getSenderIds();

            foreach ($lines as $line) {

                if (count($check_sender_id) > 0) {
                    $sender_id = $campaign->pickSenderIds();
                } else {
                    $sender_id = null;
                }

                $message = null;

                $data = array_combine($db_fields, $line);

                if ($data['phone'] != null) {

                    $phone = str_replace(['+', '(', ')', '-', ' '], '', $data['phone']);

                    $sms_type  = $campaign->sms_type;
                    $sms_count = 1;

                    if (Tool::validatePhone($phone)) {
                        if ($campaign->message) {
                            $b           = array_map('trim', $line);
                            $modify_data = array_combine($campaign_fields, $b);
                            $message     = $this->renderSMS($campaign->message, $modify_data);

                            $sms_counter  = new SMSCounter();
                            $message_data = $sms_counter->count($message);
                            $sms_count    = $message_data->messages;

                            if ($sms_type == 'plain') {
                                if ($message_data->encoding == 'UTF16') {
                                    $sms_type = 'unicode';
                                }
                            }
                        }

                        try {
                            $phoneUtil         = PhoneNumberUtil::getInstance();
                            $phoneNumberObject = $phoneUtil->parse('+'.$phone);

                            if ($phoneUtil->isPossibleNumber($phoneNumberObject)) {
                                $country_code = $phoneNumberObject->getCountryCode();
                                if (is_array($coverage) && array_key_exists($country_code, $coverage)) {
                                    if ($sms_type == 'plain' || $sms_type == 'unicode') {
                                        $cost = $coverage[$country_code]['plain_sms'];
                                    }

                                    if ($sms_type == 'voice') {
                                        $cost = $coverage[$country_code]['voice_sms'];
                                    }

                                    if ($sms_type == 'mms') {
                                        $cost = $coverage[$country_code]['mms_sms'];
                                    }

                                    if ($sms_type == 'whatsapp') {
                                        $cost = $coverage[$country_code]['whatsapp_sms'];
                                    }

                                    $sms_price  = $cost * $sms_count;
                                    $total_unit += (int) $sms_price;

                                    $key++;


                                    $preparedData['id']        = $key;
                                    $preparedData['user_id']   = $user->id;
                                    $preparedData['phone']     = $phone;
                                    $preparedData['sender_id'] = $sender_id;
                                    $preparedData['message']   = $message;
                                    $preparedData['sms_type']  = $sms_type;
                                    $preparedData['cost']      = (int) $sms_price;
                                    $preparedData['status']    = null;
                                } else {

                                    $sms_price  = 1 * $sms_count;
                                    $total_unit += (int) $sms_price;

                                    $key++;

                                    $preparedData['id']        = $key;
                                    $preparedData['user_id']   = $user->id;
                                    $preparedData['phone']     = $phone;
                                    $preparedData['sender_id'] = $sender_id;
                                    $preparedData['message']   = $message;
                                    $preparedData['sms_type']  = $sms_type;
                                    $preparedData['cost']      = (int) $sms_price;
                                    $preparedData['status']    = "Permission to send an SMS has not been enabled for the region indicated by the 'To' number: ".$phone;
                                }
                            } else {
                                $sms_price  = 1 * $sms_count;
                                $total_unit += (int) $sms_price;

                                $key++;

                                $preparedData['id']        = $key;
                                $preparedData['user_id']   = $user->id;
                                $preparedData['phone']     = $phone;
                                $preparedData['sender_id'] = $sender_id;
                                $preparedData['message']   = $message;
                                $preparedData['sms_type']  = $sms_type;
                                $preparedData['cost']      = (int) $sms_price;
                                $preparedData['status']    = __('locale.customer.invalid_phone_number', ['phone' => $phone]);
                            }

                        } catch (NumberParseException $exception) {

                            $sms_price  = 1 * $sms_count;
                            $total_unit += (int) $sms_price;

                            $key++;

                            $preparedData['id']        = $key;
                            $preparedData['user_id']   = $user->id;
                            $preparedData['phone']     = $phone;
                            $preparedData['sender_id'] = $sender_id;
                            $preparedData['message']   = $message;
                            $preparedData['sms_type']  = $sms_type;
                            $preparedData['cost']      = (int) $sms_price;
                            $preparedData['status']    = $exception->getMessage();
                        }

                    } else {

                        $total_unit += $sms_count;

                        $key++;

                        $preparedData['id']        = $key;
                        $preparedData['user_id']   = $user->id;
                        $preparedData['phone']     = $phone;
                        $preparedData['sender_id'] = $sender_id;
                        $preparedData['message']   = null;
                        $preparedData['sms_type']  = $sms_type;
                        $preparedData['cost']      = $sms_count;
                        $preparedData['status']    = __('locale.customer.invalid_phone_number', ['phone' => $phone]);
                    }

                    if ($user->customer->getOption('send_spam_message') == 'no') {
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

                    $preparedData['campaign_id']    = $campaign->id;
                    $preparedData['sending_server'] = $sending_server;

                    $prepareForTemplateTag[] = $preparedData;
                }
            }
        });

        if ($user->sms_unit != '-1' && $total_unit > $user->sms_unit) {
            $campaign->failed(sprintf("Campaign `%s` (%s) halted, customer exceeds sms credit", $campaign->campaign_name, $campaign->uid));
            sleep(60);
        } else {
            try {
                $failed_cost = 0;

                if ($user->sms_unit != '-1') {

                    DB::transaction(function () use ($user, $total_unit) {
                        $remaining_balance = $user->sms_unit - $total_unit;
                        $user->lockForUpdate();
                        $user->update(['sms_unit' => $remaining_balance]);
                    });
                }

                $campaign->processing();

                collect($prepareForTemplateTag)->sortBy('id')->values()->chunk(3000)->each(function ($sendData) use ($user, $campaign, &$failed_cost) {
                    foreach ($sendData as $data) {
                        $status = 'Invalid sms type';

                        if ($campaign->sms_type == 'plain' || $campaign->sms_type == 'unicode') {
                            $status = $campaign->sendPlainSMS($data);
                        }

                        if ($campaign->sms_type == 'voice') {
                            $data['language'] = $campaign->language;
                            $data['gender']   = $campaign->gender;
                            $status           = $campaign->sendVoiceSMS($data);
                        }

                        if ($campaign->sms_type == 'mms') {
                            $data['media_url'] = $campaign->media_url;
                            $status            = $campaign->sendMMS($data);
                        }

                        if ($campaign->sms_type == 'whatsapp') {
                            $status = $campaign->sendWhatsApp($data);
                        }

                        if (substr_count($status, 'Delivered') == 1) {
                            $campaign->updateCache('DeliveredCount');
                        } else {
                            $failed_cost += $data['cost'];
                            $campaign->updateCache('FailedDeliveredCount');
                        }
                    }
                });

                unset($user);
                $user = User::find($this->customer_id);

                if ($user->sms_unit != '-1') {

                    DB::transaction(function () use ($user, $failed_cost) {
                        $remaining_balance = $user->sms_unit + $failed_cost;
                        $user->lockForUpdate();
                        $user->update(['sms_unit' => $remaining_balance]);
                    });
                }

                $campaign->delivered();

            } catch (Exception $exception) {
                $campaign->failed($exception->getMessage());
            } finally {
                $campaign::resetServerPools();
                $data            = json_decode($campaign->cache, true);
                $total           = $data['DeliveredCount'] + $data['FailedDeliveredCount'];
                $campaign->cache = json_encode([
                        'ContactCount'         => $total,
                        'DeliveredCount'       => $data['DeliveredCount'],
                        'FailedDeliveredCount' => $data['FailedDeliveredCount'],
                        'NotDeliveredCount'    => 0,
                ]);
                $campaign->delivered();
            }
        }
    }

    /**
     * @param  Throwable  $exception
     */
    public function failed(Throwable $exception)
    {
        $campaign = Campaigns::find($this->campaign_id);
        $campaign->failed($exception->getMessage());
    }
}
