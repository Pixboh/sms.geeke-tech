<?php

namespace App\Jobs;

use App\Models\Blacklists;
use App\Models\ContactGroups;
use App\Models\Contacts;
use Exception;
use libphonenumber\PhoneNumberUtil;

/**
 * @method batch()
 */
class ImportContacts extends ContactsJob
{

    protected $customer_id;
    protected $group_id;
    protected $list;
    protected $db_fields;

    /**
     * Delete the job if its models no longer exist.
     *
     * @var bool
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     *
     * @param $customer_id
     * @param $group_id
     * @param $list
     * @param $db_fields
     */
    public function __construct($customer_id, $group_id, $list, $db_fields)
    {
        $this->list        = $list;
        $this->customer_id = $customer_id;
        $this->group_id    = $group_id;
        $this->db_fields   = $db_fields;
        parent::__construct();

        // This line must go after the constructor
        $this->linkJobToCampaign();
    }


    /**
     * Execute the job.
     *
     * @return void
     */
    public function linkJobToCampaign(): void
    {
        $systemJob       = $this->getSystemJob();
        $systemJob->data = $this->group_id;
        $systemJob->save();
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        $phone_numbers = Contacts::where('group_id', $this->group_id)->where('customer_id', $this->customer_id)->pluck('phone')->toArray();
        $blacklists    = Blacklists::where('user_id', $this->customer_id)->pluck('number')->toArray();

        $list = [];
        foreach ($this->list as $line) {
            $get_data = array_combine($this->db_fields, $line);
            unset($get_data['--']);
            $get_data['uid']         = uniqid();
            $get_data['customer_id'] = $this->customer_id;
            $get_data['group_id']    = $this->group_id;
            $get_data['status']      = 'subscribe';
            $get_data['created_at']  = now()->toDateTimeString();
            $get_data['updated_at']  = now()->toDateTimeString();

            if (isset($get_data['phone'])) {

                $phone = str_replace(['(', ')', '+', '-', ' '], '', $get_data['phone']);

                try {
                    $phoneUtil         = PhoneNumberUtil::getInstance();
                    $phoneNumberObject = $phoneUtil->parse('+'.$phone);
                    if ($phoneUtil->isPossibleNumber($phoneNumberObject)) {

                        if ( ! in_array($phone, $phone_numbers) && ! in_array($phone, $blacklists)) {
                            $get_data['phone'] = $phone;
                            $list[]            = $get_data;
                        }
                    }
                } catch (Exception) {
                    continue;
                }
            }
        }
        Contacts::insert($list);

        $check_group = ContactGroups::find($this->group_id);
        $check_group?->updateCache('SubscribersCount');
    }

}
