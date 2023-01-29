<?php

namespace App\Http\Controllers\Customer;

use App\Events\MessageReceived;
use App\Http\Controllers\Controller;
use App\Models\Blacklists;
use App\Models\Campaigns;
use App\Models\ChatBox;
use App\Models\ChatBoxMessage;
use App\Models\ContactGroups;
use App\Models\ContactGroupsOptinKeywords;
use App\Models\ContactGroupsOptoutKeywords;
use App\Models\Contacts;
use App\Models\Country;
use App\Models\Keywords;
use App\Models\Notifications;
use App\Models\PhoneNumbers;
use App\Models\PlansSendingServer;
use App\Models\Reports;
use App\Models\Senderid;
use App\Models\SendingServer;
use App\Models\User;
use App\Repositories\Eloquent\EloquentCampaignRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use Throwable;
use Twilio\TwiML\Messaging\Message;
use Twilio\TwiML\MessagingResponse;

class DLRController extends Controller
{

    /**
     * update dlr
     *
     * @param      $message_id
     * @param      $status
     * @param  null  $sender_id
     * @param  null  $phone
     *
     * @return mixed
     */
    public static function updateDLR($message_id, $status, $phone = null, $sender_id = null): mixed
    {

        $get_data = Reports::query()->when($message_id, function ($query) use ($message_id) {
            $query->whereLike(['status'], $message_id);
        })->when($sender_id, function ($query) use ($sender_id) {
            $query->whereLike(['from'], $sender_id);
        })->when($phone, function ($query) use ($phone) {
            $query->whereLike(['to'], $phone);
        })->first();


        if ($get_data) {

            $get_data->status = $status.'|'.$message_id;
            $get_data->save();

            if ($get_data->campaign_id) {
                Campaigns::find($get_data->campaign_id)->updateCache();
            }

            if ($status != 'Delivered') {
                $user = User::find($get_data->user_id);
                if ($user && $user->sms_unit != '-1') {
                    $remaining_balance = $user->sms_unit + $get_data->cost;
                    $user->update(['sms_unit' => $remaining_balance]);
                }
            }
        }

        return $status;
    }


    /**
     *twilio dlr
     *
     * @param  Request  $request
     */
    public function dlrTwilio(Request $request)
    {
        $message_id = $request->MessageSid;
        $status     = $request->MessageStatus;

        if ($status == 'delivered' || $status == 'sent') {
            $status = 'Delivered';
        }

        $this::updateDLR($message_id, $status);

    }

    /**
     * Route mobile DLR
     *
     * @param  Request  $request
     */
    public function dlrRouteMobile(Request $request)
    {
        $message_id = $request->sMessageId;
        $status     = $request->sStatus;
        $sender_id  = $request->sSender;
        $phone      = $request->sMobileNo;

        if ($status == 'DELIVRD' || $status == 'ACCEPTED') {
            $status = 'Delivered';
        }

        $this::updateDLR($message_id, $status, $sender_id, $phone);
    }


    /**
     * text local DLR
     *
     * @param  Request  $request
     */
    public function dlrTextLocal(Request $request)
    {
        $message_id = $request->customID;
        $status     = $request->status;
        $phone      = $request->number;

        if ($status == 'D') {
            $status = 'Delivered';
        }

        $this::updateDLR($message_id, $status, null, $phone);
    }


    /**
     * Plivo DLR
     *
     * @param  Request  $request
     */
    public function dlrPlivo(Request $request)
    {
        $message_id = $request->MessageUUID;
        $status     = $request->Status;
        $phone      = $request->To;
        $sender_id  = $request->From;

        if ($status == 'delivered' || $status == 'sent') {
            $status = 'Delivered';
        }

        $this::updateDLR($message_id, $status, $phone, $sender_id);
    }

    /**
     * SMS Global DLR
     *
     * @param  Request  $request
     */
    public function dlrSMSGlobal(Request $request)
    {
        $message_id = $request->msgid;
        $status     = $request->dlrstatus;

        if ($status == 'DELIVRD') {
            $status = 'Delivered';
        }

        $this::updateDLR($message_id, $status);
    }


    /**
     * Advance Message System Delivery reports
     *
     * @param  Request  $request
     */
    public function dlrAdvanceMSGSys(Request $request)
    {
        $message_id = $request->MessageId;
        $status     = $request->Status;
        $phone      = $request->Destination;
        $sender_id  = $request->Source;

        if ($status == 'DELIVRD') {
            $status = 'Delivered';
        }

        $this::updateDLR($message_id, $status, $phone, $sender_id);
    }


    /**
     * nexmo now Vonage DLR
     *
     * @param  Request  $request
     */
    public function dlrVonage(Request $request)
    {
        $message_id = $request->messageId;
        $status     = $request->status;
        $phone      = $request->msisdn;
        $sender_id  = $request->to;

        if ($status == 'delivered' || $status == 'accepted') {
            $status = 'Delivered';
        }

        $this::updateDLR($message_id, $status, $phone, $sender_id);
    }

    /**
     * infobip DLR
     *
     * @param  Request  $request
     */
    public function dlrInfobip(Request $request)
    {
        $get_data = $request->getContent();

        $get_data = json_decode($get_data, true);
        if (isset($get_data) && is_array($get_data) && array_key_exists('results', $get_data)) {
            $message_id = $get_data['results']['0']['messageId'];

            foreach ($get_data['results'] as $msg) {

                if (isset($msg['status']['groupName'])) {

                    $status = $msg['status']['groupName'];

                    if ($status == 'DELIVERED') {
                        $status = 'Delivered';
                    }

                    $this::updateDLR($message_id, $status);
                }

            }
        }
    }

    public function dlrEasySendSMS(Request $request)
    {
        $message_id = $request->messageid;
        $status     = $request->status;
        $phone      = $request->mobile;
        $sender_id  = $request->sender;

        if ($status == 'delivered') {
            $status = 'Delivered';
        }

        $this::updateDLR($message_id, $status, $phone, $sender_id);
    }


    /**
     * AfricasTalking delivery reports
     *
     * @param  Request  $request
     */
    public function dlrAfricasTalking(Request $request)
    {
        $message_id = $request->id;
        $status     = $request->status;
        $phone      = str_replace(['(', ')', '+', '-', ' '], '', $request->phoneNumber);

        if ($status == 'Success') {
            $status = 'Delivered';
        }

        $this::updateDLR($message_id, $status, $phone);
    }


    /**
     * 1s2u delivery reports
     *
     * @param  Request  $request
     */
    public function dlr1s2u(Request $request)
    {
        $message_id = $request->msgid;
        $status     = $request->status;
        $phone      = str_replace(['(', ')', '+', '-', ' '], '', $request->mno);
        $sender_id  = $request->sid;

        if ($status == 'DELIVRD') {
            $status = 'Delivered';
        }

        $this::updateDLR($message_id, $status, $phone, $sender_id);
    }


    /**
     * dlrKeccelSMS delivery reports
     *
     * @param  Request  $request
     */
    public function dlrKeccelSMS(Request $request)
    {
        $message_id = $request->messageID;
        $status     = $request->status;

        if ($status == 'DELIVERED') {
            $status = 'Delivered';
        }

        $this::updateDLR($message_id, $status);
    }

    /**
     * dlrGatewayApi delivery reports
     *
     * @param  Request  $request
     */
    public function dlrGatewayApi(Request $request)
    {

        $message_id = $request->id;
        $status     = $request->status;
        $phone      = str_replace(['(', ')', '+', '-', ' '], '', $request->msisdn);

        if ($status == 'DELIVRD' || $status == 'DELIVERED') {
            $status = 'Delivered';
        }

        $this::updateDLR($message_id, $status, $phone);
    }


    /**
     * bulk sms delivery reports
     *
     * @param  Request  $request
     */
    public function dlrBulkSMS(Request $request)
    {

        logger($request->all());

    }

    /**
     * SMSVas delivery reports
     *
     * @param  Request  $request
     */
    public function dlrSMSVas(Request $request)
    {

        logger($request->all());

    }


    /**
     * receive inbound message
     *
     * @param      $to
     * @param      $message
     * @param      $sending_sever
     * @param      $cost
     * @param  null  $from
     * @param  null  $media_url
     * @param  null  $extra
     * @param  int  $user_id
     *
     * @return JsonResponse|string
     * @throws NumberParseException
     * @throws Throwable
     */
    public static function inboundDLR($to, $message, $sending_sever, $cost, $from = null, $media_url = null, $extra = null, int $user_id = 1): JsonResponse|string
    {
        if (config('app.stage') == 'demo') {
            return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry!! This options is not available in demo mode',
            ]);
        }

        $to = str_replace(['(', ')', '+', '-', ' '], '', trim($to));

        if ($from != null) {
            $from = str_replace(['(', ')', '+', '-', ' '], '', trim($from));
        }
        $success = 'Success';
        $failed  = null;

        if ($extra != null) {
            $sender_id = Senderid::where('sender_id', $extra)->first();

            if ($sender_id) {
                $user_id = $sender_id->user_id;
                $user    = User::find($user_id);

                $sending_servers = SendingServer::where('settings', $sending_sever)->where('status', true)->first();

                if (isset($user->customer)) {

                    // Check the customer has permissions using sending servers and has his own sending servers
                    if ($user->customer->getOption('create_sending_server') == 'yes' && in_array('create_sending_servers', json_decode($user->customer->customerPermissions(), true))) {
                        $plan_id = $user->customer->activeSubscription()->plan_id;
                        if (PlansSendingServer::where('plan_id', $plan_id)->count()) {
                            $sending_servers = SendingServer::where('user_id', $user->id)->where('settings', $sending_sever)->where('status', true)->first();
                        } else {
                            $sending_servers = SendingServer::where('settings', $sending_sever)->where('status', true)->first();
                        }
                    } else {
                        // If customer don't have permission creating sending servers
                        $sending_servers = SendingServer::where('settings', $sending_sever)->where('status', true)->first();
                    }

                    //checking chat box
                    $chatBox = ChatBox::where('to', $to)->where('from', $extra)->where('user_id', $user_id)->first();

                    if ($chatBox) {
                        $chatBox->notification += 1;
                        $chatBox->save();
                    } else {
                        $chatBox = ChatBox::create([
                                'user_id'           => $user_id,
                                'from'              => $extra,
                                'to'                => $to,
                                'notification'      => 1,
                                'sending_server_id' => $sending_servers->id,
                        ]);
                    }

                    if ($chatBox && $sending_servers) {

                        Notifications::create([
                                'user_id'           => $user_id,
                                'notification_for'  => 'customer',
                                'notification_type' => 'chatbox',
                                'message'           => 'New chat message arrive',
                        ]);

                        ChatBoxMessage::create([
                                'box_id'            => $chatBox->id,
                                'message'           => $message,
                                'media_url'         => $media_url,
                                'sms_type'          => 'sms',
                                'send_by'           => 'to',
                                'sending_server_id' => $sending_servers->id,
                        ]);

                        $user = User::find($user_id);
                        event(new MessageReceived($user, $message, $chatBox));

                    } else {
                        $failed .= 'Failed to create chat message ';
                    }
                }
                if ($sending_servers) {
                    Reports::create([
                            'user_id'           => $user_id,
                            'from'              => $from,
                            'to'                => $to,
                            'message'           => $message,
                            'sms_type'          => 'plain',
                            'status'            => "Delivered",
                            'send_by'           => "to",
                            'cost'              => $cost,
                            'media_url'         => $media_url,
                            'sending_server_id' => $sending_servers->id,
                    ]);
                }
            }
        } else {

            $phone_number = PhoneNumbers::where('number', $from)->where('status', 'assigned')->first();

            if ($media_url) {
                $sms_type = 'mms';
            } else {
                $sms_type = 'plain';
            }

            if ($phone_number) {
                $user_id = $phone_number->user_id;
            }
            $user = User::find($user_id);

            // Check the customer has permissions using sending servers and has his own sending servers
            if ($user->customer->getOption('create_sending_server') == 'yes' && in_array('create_sending_servers', json_decode($user->customer->customerPermissions(), true))) {
                $plan_id = $user->customer->activeSubscription()->plan_id;

                if (PlansSendingServer::where('plan_id', $plan_id)->count()) {
                    $sending_servers = SendingServer::where('user_id', $user->id)->where('settings', $sending_sever)->where('status', true)->first();
                } else {
                    $sending_servers = SendingServer::where('settings', $sending_sever)->where('status', true)->first();
                }
            } else {
                // If customer don't have permission creating sending servers
                $sending_servers = SendingServer::where('settings', $sending_sever)->where('status', true)->first();
            }

            if ($sending_servers) {

                Reports::create([
                        'user_id'           => $user_id,
                        'from'              => $from,
                        'to'                => $to,
                        'message'           => $message,
                        'sms_type'          => $sms_type,
                        'status'            => "Delivered",
                        'send_by'           => "to",
                        'cost'              => $cost,
                        'media_url'         => $media_url,
                        'sending_server_id' => $sending_servers->id,
                ]);

                if (isset($user->customer)) {

                    //checking chat box
                    $chatBox = ChatBox::where('to', $to)->where('from', $from)->where('user_id', $user_id)->first();

                    if ($chatBox) {
                        $chatBox->notification += 1;
                        $chatBox->save();
                    } else {
                        $chatBox = ChatBox::create([
                                'user_id'           => $user_id,
                                'from'              => $from,
                                'to'                => $to,
                                'notification'      => 1,
                                'sending_server_id' => $sending_servers->id,
                        ]);
                    }

                    if ($chatBox) {

                        Notifications::create([
                                'user_id'           => $user_id,
                                'notification_for'  => 'customer',
                                'notification_type' => 'chatbox',
                                'message'           => 'New chat message arrive',
                        ]);

                        ChatBoxMessage::create([
                                'box_id'            => $chatBox->id,
                                'message'           => $message,
                                'media_url'         => $media_url,
                                'sms_type'          => 'sms',
                                'send_by'           => 'to',
                                'sending_server_id' => $sending_servers->id,
                        ]);

                        $user = User::find($user_id);
                        event(new MessageReceived($user, $message, $chatBox));

                    } else {
                        $failed .= 'Failed to create chat message ';
                    }

                    //check keywords
                    $keyword = Keywords::where('user_id', $user_id)
                                       ->select('*')
                                       ->selectRaw('lower(keyword_name) as keyword,keyword_name')
                                       ->where('keyword_name', strtolower($message))
                                       ->where('status', 'assigned')->first();

                    if ($keyword) {

                        $phoneUtil         = PhoneNumberUtil::getInstance();
                        $phoneNumberObject = $phoneUtil->parse('+'.$to);
                        $country_code      = $phoneNumberObject->getCountryCode();

                        $country = Country::where('country_code', $country_code)->first();
                        if ( ! $country) {
                            $failed .= "Permission to send an SMS has not been enabled for the region indicated by the 'To' number: ".$to;
                        }

                        //checking contact message
                        $contact_groups = ContactGroups::where('customer_id', $user_id)->cursor()->pluck('id')->toArray();
                        $optInContacts  = ContactGroupsOptinKeywords::whereIn('contact_group', $contact_groups)->where('keyword', $message)->cursor();
                        $optOutContacts = ContactGroupsOptoutKeywords::whereIn('contact_group', $contact_groups)->where('keyword', $message)->cursor();

                        $blacklist = Blacklists::where('user_id', $user_id)->where('number', $to)->first();


                        if ($optInContacts->count()) {
                            foreach ($optInContacts as $contact) {
                                $exist = Contacts::where('group_id', $contact->contact_group)->where('phone', $to)->first();

                                $blacklist?->delete();

                                if ( ! $exist) {
                                    $data = Contacts::create([
                                            'customer_id' => $user_id,
                                            'group_id'    => $contact->contact_group,
                                            'phone'       => $to,
                                            'first_name'  => null,
                                            'last_name'   => null,
                                    ]);

                                    if ($data && $country) {

                                        $sendMessage = new EloquentCampaignRepository($campaign = new Campaigns());

                                        if ($contact->ContactGroups->send_keyword_message) {
                                            if ($keyword->reply_text) {

                                                $sendMessage->quickSend($campaign, [
                                                        'sender_id'      => $keyword->sender_id,
                                                        'sms_type'       => 'plain',
                                                        'message'        => $keyword->reply_text,
                                                        'recipient'      => $phoneNumberObject->getNationalNumber(),
                                                        'user_id'        => $user_id,
                                                        'sending_server' => $contact->ContactGroups->sending_server,
                                                        'country_code'   => $country_code,
                                                        'exist_c_code'   => true,
                                                ]);

                                            }
                                        } else {
                                            if ($contact->ContactGroups->send_welcome_sms && $contact->ContactGroups->welcome_sms) {

                                                $sendMessage->quickSend($campaign, [
                                                        'sender_id'      => $contact->ContactGroups->sender_id,
                                                        'sms_type'       => 'plain',
                                                        'message'        => $contact->ContactGroups->welcome_sms,
                                                        'recipient'      => $phoneNumberObject->getNationalNumber(),
                                                        'user_id'        => $user_id,
                                                        'sending_server' => $contact->ContactGroups->sending_server,
                                                        'country_code'   => $country_code,
                                                        'exist_c_code'   => true,
                                                ]);

                                            }
                                        }

                                        $contact->ContactGroups->updateCache('SubscribersCount');
                                    } else {
                                        $failed .= 'Failed to subscribe contact list';
                                    }
                                } else {

                                    if ($country) {
                                        $sendMessage = new EloquentCampaignRepository($campaign = new Campaigns());

                                        $sendMessage->quickSend($campaign, [
                                                'sender_id'      => $keyword->sender_id,
                                                'sms_type'       => 'plain',
                                                'message'        => __('locale.contacts.you_have_already_subscribed', ['contact_group' => $contact->ContactGroups->name]),
                                                'recipient'      => $phoneNumberObject->getNationalNumber(),
                                                'user_id'        => $user_id,
                                                'sending_server' => $contact->ContactGroups->sending_server,
                                                'country_code'   => $country_code,
                                                'exist_c_code'   => true,
                                        ]);
                                    }

                                    $exist->update([
                                            'status' => 'subscribe',
                                    ]);
                                }

                            }
                        } elseif ($optOutContacts->count()) {

                            foreach ($optOutContacts as $contact) {

                                if ( ! $blacklist) {
                                    $exist = Contacts::where('group_id', $contact->contact_group)->where('phone', $to)->first();
                                    if ($exist) {
                                        $data = $exist->update([
                                                'status' => 'unsubscribe',
                                        ]);

                                        if ($data && $country) {
                                            Blacklists::create([
                                                    'user_id' => $user_id,
                                                    'number'  => $to,
                                                    'reason'  => "Optout by User",
                                            ]);

                                            $chatbox_messages = ChatBox::where('user_id', $user_id)->where('to', $to)->cursor();
                                            foreach ($chatbox_messages as $messages) {
                                                $check_delete = ChatBoxMessage::where('box_id', $messages->id)->delete();
                                                if ($check_delete) {
                                                    $messages->delete();
                                                }
                                            }

                                            $sendMessage = new EloquentCampaignRepository($campaign = new Campaigns());

                                            if ($contact->ContactGroups->send_keyword_message) {
                                                if ($keyword->reply_text) {

                                                    $sendMessage->quickSend($campaign, [
                                                            'sender_id'      => $keyword->sender_id,
                                                            'sms_type'       => 'plain',
                                                            'message'        => $keyword->reply_text,
                                                            'recipient'      => $phoneNumberObject->getNationalNumber(),
                                                            'user_id'        => $user_id,
                                                            'sending_server' => $contact->ContactGroups->sending_server,
                                                            'country_code'   => $country_code,
                                                            'exist_c_code'   => true,
                                                    ]);

                                                }
                                            } else {
                                                if ($contact->ContactGroups->unsubscribe_notification && $contact->ContactGroups->unsubscribe_sms) {

                                                    $sendMessage->quickSend($campaign, [
                                                            'sender_id'      => $contact->ContactGroups->sender_id,
                                                            'sms_type'       => 'plain',
                                                            'message'        => $contact->ContactGroups->unsubscribe_sms,
                                                            'recipient'      => $phoneNumberObject->getNationalNumber(),
                                                            'user_id'        => $user_id,
                                                            'sending_server' => $contact->ContactGroups->sending_server,
                                                            'country_code'   => $country_code,
                                                            'exist_c_code'   => true,
                                                    ]);

                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        } else {

                            if ($keyword->reply_text && $country) {
                                $sendMessage = new EloquentCampaignRepository($campaign = new Campaigns());
                                $sendMessage->quickSend($campaign, [
                                        'sender_id'      => $keyword->sender_id,
                                        'sms_type'       => 'plain',
                                        'message'        => $keyword->reply_text,
                                        'recipient'      => $phoneNumberObject->getNationalNumber(),
                                        'user_id'        => $user_id,
                                        'sending_server' => $sending_servers->id,
                                        'country_code'   => $country_code,
                                        'exist_c_code'   => true,
                                ]);

                            } else {
                                $failed .= 'Related keyword reply message not found.';
                            }
                        }
                    }
                }
            }

        }

        if (strtolower($message) == 'stop') {
            $blacklist = Blacklists::where('user_id', $user_id)->where('number', $to)->first();
            if ( ! $blacklist) {
                Blacklists::create([
                        'user_id' => $user_id,
                        'number'  => $to,
                        'reason'  => "Optout by User",
                ]);

                $chatbox_messages = ChatBox::where('user_id', $user_id)->where('to', $to)->cursor();
                foreach ($chatbox_messages as $messages) {
                    $check_delete = ChatBoxMessage::where('box_id', $messages->id)->delete();
                    if ($check_delete) {
                        $messages->delete();
                    }
                }
            }
        }


        if ($failed == null) {
            return $success;
        }

        return $failed;

    }


    /**
     * twilio inbound sms
     *
     * @param  Request  $request
     *
     * @return Message|MessagingResponse
     * @throws NumberParseException
     * @throws Throwable
     */
    public function inboundTwilio(Request $request): Message|MessagingResponse
    {
        $to      = $request->input('From');
        $from    = $request->input('To');
        $message = $request->input('Body');

        if ($message == 'NULL') {
            $message = null;
        }

        $response = new MessagingResponse();

        if ($to == null || $from == null) {
            $response->message('From and To value required');

            return $response;
        }

        $feedback = 'Success';

        $NumMedia = (int) $request->input('NumMedia');
        if ($NumMedia > 0) {
            $cost = 1;
            for ($i = 0; $i < $NumMedia; $i++) {
                $mediaUrl = $request->input("MediaUrl$i");
                $feedback = $this::inboundDLR($to, $message, 'Twilio', $cost, $from, $mediaUrl);
            }
        } else {
            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);

            $feedback = $this::inboundDLR($to, $message, 'Twilio', $cost, $from);
        }


        if ($feedback == 'Success') {
            return $response;
        }

        return $response->message($feedback);
    }

    /**
     * TwilioCopilot inbound sms
     *
     * @param  Request  $request
     *
     * @return Message|MessagingResponse
     * @throws NumberParseException
     * @throws Throwable
     */
    public function inboundTwilioCopilot(Request $request): Message|MessagingResponse
    {
        $to      = $request->input('From');
        $from    = $request->input('To');
        $message = $request->input('Body');
        $extra   = $request->input('MessagingServiceSid');

        if ($message == 'NULL') {
            $message = null;
        }

        $response = new MessagingResponse();

        if ($to == null || $from == null || $extra == null) {
            $response->message('From, To, and MessagingServiceSid value required');

            return $response;
        }

        $feedback = 'Success';

        $NumMedia = (int) $request->input('NumMedia');
        if ($NumMedia > 0) {
            $cost = 1;
            for ($i = 0; $i < $NumMedia; $i++) {
                $mediaUrl = $request->input("MediaUrl$i");
                $feedback = $this::inboundDLR($to, $message, 'TwilioCopilot', $cost, $from, $mediaUrl, $extra);
            }
        } else {
            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);

            $feedback = $this::inboundDLR($to, $message, 'TwilioCopilot', $cost, $from, null, $extra);
        }


        if ($feedback == 'Success') {
            return $response;
        }

        return $response->message($feedback);
    }

    /**
     * text local inbound sms
     *
     * @param  Request  $request
     *
     * @return JsonResponse|string
     * @throws NumberParseException
     * @throws Throwable
     */
    public function inboundTextLocal(Request $request): JsonResponse|string
    {
        $to      = $request->input('sender');
        $from    = $request->input('inNumber');
        $message = $request->input('content');

        if ($to == null || $from == null || $message == null) {
            return 'Sender, inNumber and content value required';
        }

        $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
        $cost          = ceil($message_count);

        return $this::inboundDLR($to, $message, 'TextLocal', $cost, $from);
    }


    /**
     * inbound plivo messages
     *
     * @param  Request  $request
     *
     * @return JsonResponse|string
     * @throws NumberParseException
     * @throws Throwable
     */
    public function inboundPlivo(Request $request): JsonResponse|string
    {
        $to      = $request->input('From');
        $from    = $request->input('To');
        $message = $request->input('Text');

        if ($to == null || $message == null) {
            return 'Destination number and message value required';
        }

        $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
        $cost          = ceil($message_count);

        return $this::inboundDLR($to, $message, 'Plivo', $cost, $from);
    }


    /**
     * inbound plivo powerpack messages
     *
     * @param  Request  $request
     *
     * @return JsonResponse|string
     * @throws NumberParseException
     * @throws Throwable
     */
    public function inboundPlivoPowerPack(Request $request): JsonResponse|string
    {
        $to      = $request->input('From');
        $from    = $request->input('To');
        $message = $request->input('Text');

        if ($to == null || $message == null) {
            return 'Destination number and message value required';
        }

        $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
        $cost          = ceil($message_count);

        return $this::inboundDLR($to, $message, 'PlivoPowerpack', $cost, $from);
    }


    /**
     * inbound bulk sms messages
     *
     * @param  Request  $request
     *
     * @return JsonResponse|string
     * @throws NumberParseException
     * @throws Throwable
     */
    public function inboundBulkSMS(Request $request): JsonResponse|string
    {
        $to      = $request->input('msisdn');
        $from    = $request->input('sender');
        $message = $request->input('message');

        if ($to == null || $message == null) {
            return 'Destination number and message value required';
        }

        $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
        $cost          = ceil($message_count);

        return $this::inboundDLR($to, $message, 'BulkSMS', $cost, $from);
    }

    /**
     * inbound Vonage messages
     *
     * @param  Request  $request
     *
     * @return JsonResponse|string
     * @throws NumberParseException
     * @throws Throwable
     */
    public function inboundVonage(Request $request): JsonResponse|string
    {
        $to      = $request->input('msisdn');
        $from    = $request->input('to');
        $message = $request->input('text');

        if ($to == null || $message == null) {
            return 'Destination number, Source number and message value required';
        }

        $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
        $cost          = ceil($message_count);

        return $this::inboundDLR($to, $message, 'Vonage', $cost, $from);
    }

    /**
     * inbound messagebird messages
     *
     * @param  Request  $request
     *
     * @return JsonResponse|string
     * @throws NumberParseException
     * @throws Throwable
     */
    public function inboundMessagebird(Request $request): JsonResponse|string
    {

        $to      = $request->input('originator');
        $from    = $request->input('recipient');
        $message = $request->input('body');

        if ($to == null || $message == null) {
            return 'Destination number, Source number and message value required';
        }

        $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
        $cost          = ceil($message_count);

        return $this::inboundDLR($to, $message, 'MessageBird', $cost, $from);
    }

    /**
     * inbound signalwire messages
     *
     * @param  Request  $request
     *
     * @return Message|MessagingResponse
     * @throws NumberParseException
     * @throws Throwable
     */
    public function inboundSignalwire(Request $request): Message|MessagingResponse
    {

        $response = new MessagingResponse();

        $to      = $request->input('From');
        $from    = $request->input('To');
        $message = $request->input('Body');

        if ($to == null || $from == null || $message == null) {
            $response->message('From, To and Body value required');

            return $response;
        }

        $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
        $cost          = ceil($message_count);

        $feedback = $this::inboundDLR($to, $message, 'SignalWire', $cost, $from);

        if ($feedback == 'Success') {
            return $response;
        }

        return $response->message($feedback);
    }


    /**
     * inbound telnyx messages
     *
     * @param  Request  $request
     *
     * @return JsonResponse|string
     * @throws NumberParseException
     * @throws Throwable
     */
    public function inboundTelnyx(Request $request): JsonResponse|string
    {

        $get_data = $request->getContent();

        $get_data = json_decode($get_data, true);

        if (isset($get_data) && is_array($get_data) && array_key_exists('data', $get_data) && array_key_exists('payload', $get_data['data'])) {
            if ($get_data['data']['event_type'] == 'message.received') {
                $to      = $get_data['data']['payload']['from']['phone_number'];
                $from    = $get_data['data']['payload']['to'][0]['phone_number'];
                $message = $get_data['data']['payload']['text'];

                if ($to == '' || $message == '' || $from == '') {
                    return 'Destination or Sender number and message value required';
                }

                $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
                $cost          = ceil($message_count);

                return $this::inboundDLR($to, $message, 'Telnyx', $cost, $from);
            }
            if ($get_data['data']['event_type'] == 'message.finalized') {
                $message_id = $get_data['data']['payload']['id'];
                $status     = $get_data['data']['payload']['to'][0]['status'];

                if ($status == 'delivered' || $status == 'webhook_delivered') {
                    $status = 'Delivered';
                }

                $this::updateDLR($message_id, $status);
            }

            return 'Invalid request';
        }

        return 'Invalid request';
    }


    /**
     * inbound Teletopiasms messages
     *
     * @param  Request  $request
     *
     * @return JsonResponse|string
     * @throws NumberParseException
     * @throws Throwable
     */
    public function inboundTeletopiasms(Request $request): JsonResponse|string
    {

        $to      = $request->input('sender');
        $from    = $request->input('recipient');
        $message = $request->input('text');

        if ($to == null || $message == null) {
            return 'Destination number, Source number and message value required';
        }

        $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
        $cost          = ceil($message_count);

        return $this::inboundDLR($to, $message, 'Teletopiasms', $cost, $from);
    }


    /**
     * receive FlowRoute message
     *
     * @param  Request  $request
     *
     * @return JsonResponse|string
     * @throws NumberParseException
     * @throws Throwable
     */
    public function inboundFlowRoute(Request $request): JsonResponse|string
    {

        $data = json_decode($request->getContent(), true);

        if (isset($data) && is_array($data) && array_key_exists('data', $data)) {

            $to      = $data['data']['attributes']['from'];
            $from    = $data['data']['attributes']['to'];
            $message = $data['data']['attributes']['body'];

            if ($from == '' || $message == '' || $to == '') {
                return 'From, To and Body value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);

            return $this::inboundDLR($to, $message, 'FlowRoute', $cost, $from);
        }

        return 'valid data not found';
    }

    /**
     * receive inboundEasySendSMS message
     *
     * @param  Request  $request
     *
     * @return JsonResponse|string
     * @throws NumberParseException
     * @throws Throwable
     */
    public function inboundEasySendSMS(Request $request): JsonResponse|string
    {

        $to      = $request->input('From');
        $from    = null;
        $message = $request->input('message');

        if ($message == '' || $to == '') {
            return 'To and Message value required';
        }

        $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
        $cost          = ceil($message_count);

        return $this::inboundDLR($to, $message, 'FlowRoute', $cost, $from);
    }


    /**
     * receive Skyetel message
     *
     * @param  Request  $request
     *
     * @return JsonResponse|string
     * @throws NumberParseException
     * @throws Throwable
     */
    public function inboundSkyetel(Request $request): JsonResponse|string
    {

        $to      = $request->input('from');
        $from    = $request->input('to');
        $message = $request->input('text');

        if ($to == '' || $from == '') {
            return 'To and From value required';
        }


        if (isset($request->media) && is_array($request->media) && array_key_exists('1', $request->media)) {

            $mediaUrl = $request->media[1];

            return $this::inboundDLR($to, $message, 'Skyetel', 1, $from, $mediaUrl);
        } else {

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);

            return $this::inboundDLR($to, $message, 'Skyetel', $cost, $from);
        }

    }

    /**
     * receive chat-api message
     *
     * @return JsonResponse|bool|string
     * @throws NumberParseException
     * @throws Throwable
     */
    public function inboundChatApi(): JsonResponse|bool|string
    {

        $data = json_decode(file_get_contents('php://input'), true);

        foreach ($data['messages'] as $message) {

            $to      = $message['author'];
            $from    = $message['senderName'];
            $message = $message['body'];

            if ($message == '' || $to == '' || $from == '') {
                return 'Author, Sender Name and Body value required';
            }

            $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
            $cost          = ceil($message_count);

            return $this::inboundDLR($to, $message, 'WhatsAppChatApi', $cost, $from);
        }

        return true;
    }

    /**
     * callr delivery reports
     *
     * @param  Request  $request
     */
    public function dlrCallr(Request $request)
    {

        $get_data = json_decode($request->getContent(), true);

        $message_id = $get_data['data']['user_data'];
        $status     = $get_data['data']['status'];

        if ($status == 'RECEIVED' || $status == 'SENT') {
            $status = 'Delivered|'.$message_id;
        }

        $this::updateDLR($message_id, $status);
    }


    /**
     * receive callr message
     *
     * @param  Request  $request
     *
     * @return JsonResponse|string
     * @throws NumberParseException
     * @throws Throwable
     */
    public function inboundCallr(Request $request): JsonResponse|string
    {

        $get_data = json_decode($request->getContent(), true);

        $to      = str_replace('+', '', $get_data['data']['from']);
        $from    = str_replace('+', '', $get_data['data']['to']);
        $message = $get_data['data']['text'];

        if ($message == '' || $to == '' || $from == '') {
            return 'From, To and Text value required';
        }

        $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
        $cost          = ceil($message_count);

        return $this::inboundDLR($to, $message, 'Callr', $cost, $from);
    }


    /**
     * cm com delivery reports
     *
     * @param  Request  $request
     *
     * @return mixed|string
     */
    public function dlrCM(Request $request)
    {

        $get_data = json_decode($request->getContent(), true);
        if (is_array($get_data) && array_key_exists('messages', $get_data)) {
            $message_id = $get_data['messages']['msg']['reference'];
            $status     = $get_data['messages']['msg']['status']['errorDescription'];

            if ($status == 'Delivered') {
                $status = 'Delivered|'.$message_id;
            }

            return $this::updateDLR($message_id, $status);
        }

        return 'Null Value Return';
    }


    /**
     * receive cm com message
     *
     * @param  Request  $request
     *
     * @return JsonResponse|string
     * @throws NumberParseException
     * @throws Throwable
     */
    public function inboundCM(Request $request): JsonResponse|string
    {

        $get_data = json_decode($request->getContent(), true);

        $to      = str_replace('+', '', $get_data['from']['number']);
        $from    = str_replace('+', '', $get_data['to']['number']);
        $message = $get_data['message']['text'];

        if ($message == '' || $to == '' || $from == '') {
            return 'From, To and Text value required';
        }

        $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
        $cost          = ceil($message_count);

        return $this::inboundDLR($to, $message, 'CMCOM', $cost, $from);
    }


    /**
     * receive bandwidth message
     *
     * @param  Request  $request
     *
     * @return bool|JsonResponse|string|null
     * @throws NumberParseException
     * @throws Throwable
     */
    public function inboundBandwidth(Request $request): bool|JsonResponse|string|null
    {

        $data = $request->all();

        if (isset($data) && is_array($data) && count($data) > 0) {
            if ($data['0']['type'] == 'message-received') {
                if (isset($data[0]['message']) && is_array($data[0]['message'])) {
                    $to      = $data[0]['message']['from'];
                    $from    = $data[0]['to'];
                    $message = $data[0]['message']['text'];


                    if ($message == '' || $to == '' || $from == '') {
                        return 'From, To and Text value required';
                    }

                    $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
                    $cost          = ceil($message_count);

                    return $this::inboundDLR($to, $message, 'Bandwidth', $cost, $from);
                } else {
                    return $request->getContent();
                }
            } else {
                return $request->getContent();
            }
        } else {
            return $request->getContent();
        }

    }


    /**
     * receive Solucoesdigitais message
     *
     * @param  Request  $request
     *
     * @return bool|false
     * @throws NumberParseException
     * @throws Throwable
     */
    public function inboundSolucoesdigitais(Request $request): bool
    {
        $data        = $request->all();
        $id_campanha = $data['id_campanha'];
        $report      = Reports::where('status', 'LIKE', "%{$id_campanha}%")->first();

        $message       = $data['sms_resposta'];
        $to            = $data['nro_telefone'];
        $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
        $cost          = ceil($message_count);

        if ($report) {
            $from = $report->from;

            if ($message == '' || $to == '' || $from == '') {
                return 'From, To and Text value required';
            }

            return $this::inboundDLR($to, $message, 'Solucoesdigitais', $cost, $from, null, null, $report->user_id);
        }

        return $this::inboundDLR($to, $message, 'Solucoesdigitais', $cost);
    }


    /**
     * receive inboundGatewayApi message
     *
     * @param  Request  $request
     *
     * @return bool|false
     * @throws NumberParseException
     * @throws Throwable
     */
    public function inboundGatewayApi(Request $request): bool
    {

        $to      = $request->input('msisdn');
        $from    = $request->input('receiver');
        $message = $request->input('message');

        if ($message == '' || $to == '') {
            return 'To and Message value required';
        }

        $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
        $cost          = ceil($message_count);

        return $this::inboundDLR($to, $message, 'Gatewayapi', $cost, $from);
    }


    /**
     * @param  Request  $request
     *
     * @return JsonResponse|string
     * @throws NumberParseException
     * @throws Throwable
     */

    public function inboundInteliquent(Request $request)
    {

        $to      = $request->input('to')[0];
        $from    = $request->from;
        $message = $request->text;

        if ($message == '' || $to == '' || $from == '') {
            return 'From, To and Message value required';
        }

        $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
        $cost          = ceil($message_count);

        return $this::inboundDLR($to, $message, 'Inteliquent', $cost, $from);
    }


    /**
     * Version 3.5
     *
     * @param  Request  $request
     *
     * @return void
     */
    public function dlrD7networks(Request $request)
    {

        $message_id = $request->request_id;
        $status     = $request->status;

        if ($status == 'delivered' || $status == 'accepted') {
            $status = 'Delivered';
        }

        $this::updateDLR($message_id, $status);
    }


    /**
     * Inbound sms for Tele API
     *
     * @param  Request  $request
     *
     * @return JsonResponse|string
     * @throws NumberParseException
     * @throws Throwable
     */

    public function inboundTeleAPI(Request $request)
    {

        $to      = $request->destination;
        $from    = $request->source;
        $message = $request->message;

        if ($message == '' || $to == '' || $from == '') {
            return 'Source, Destination and Message value required';
        }

        $message_count = strlen(preg_replace('/\s+/', ' ', trim($message))) / 160;
        $cost          = ceil($message_count);

        return $this::inboundDLR($to, $message, SendingServer::TYPE_TELEAPI, $cost, $from);
    }


}
