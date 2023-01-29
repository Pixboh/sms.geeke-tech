<?php

    namespace App\Http\Controllers\Customer;

    use App\Http\Controllers\Controller;
    use App\Http\Requests\ChatBox\SentRequest;
    use App\Models\Blacklists;
    use App\Models\Campaigns;
    use App\Models\ChatBox;
    use App\Models\ChatBoxMessage;
    use App\Models\Contacts;
    use App\Models\PhoneNumbers;
    use App\Models\PlansCoverageCountries;
    use App\Models\PlansSendingServer;
    use App\Models\Senderid;
    use App\Models\SendingServer;
    use App\Repositories\Contracts\CampaignRepository;
    use Illuminate\Auth\Access\AuthorizationException;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Contracts\View\Factory;
    use Illuminate\Contracts\View\View;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Auth;
    use libphonenumber\NumberParseException;
    use libphonenumber\PhoneNumberUtil;

    class ChatBoxController extends Controller
    {

        protected CampaignRepository $campaigns;

        /**
         * ChatBoxController constructor.
         *
         * @param CampaignRepository $campaigns
         */
        public function __construct(CampaignRepository $campaigns)
        {
            $this->campaigns = $campaigns;
        }

        /**
         * get all chat box
         *
         * @return Application|Factory|View
         * @throws AuthorizationException
         */
        public function index(): View|Factory|Application
        {
            $this->authorize('chat_box');

            $pageConfigs = [
                'pageHeader'    => false,
                'contentLayout' => "content-left-sidebar",
                'pageClass'     => 'chat-application',
            ];

            $chat_box = ChatBox::where('user_id', Auth::user()->id)->take(1000)->orderBy('updated_at', 'desc')->cursor();

            return view('customer.ChatBox.index', [
                'pageConfigs' => $pageConfigs,
                'chat_box'    => $chat_box,
            ]);
        }


        /**
         * start new conversation
         *
         * @return Application|Factory|View|RedirectResponse
         * @throws AuthorizationException
         *
         */
        public function new(): View|Factory|RedirectResponse|Application
        {
            $this->authorize('chat_box');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('chat-box'), 'name' => __('locale.menu.Chat Box')],
                ['name' => __('locale.labels.new_conversion')],
            ];

            $phone_numbers = PhoneNumbers::where('user_id', Auth::user()->id)->where('status', 'assigned')->cursor();

            if ( ! Auth::user()->customer->activeSubscription()) {
                return redirect()->route('customer.chatbox.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }

            $plan_id = Auth::user()->customer->activeSubscription()->plan_id;

            // Check the customer has permissions using sending servers and has his own sending servers
            if (Auth::user()->customer->getOption('create_sending_server') == 'yes') {
                if (PlansSendingServer::where('plan_id', $plan_id)->count()) {

                    $sending_server = SendingServer::where('user_id', Auth::user()->id)->where('plain', 1)->where('two_way', 1)->where('status', true)->get();

                    if ($sending_server->count() == 0) {
                        $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                        $sending_server     = SendingServer::where('plain', 1)->where('two_way', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
                    }
                } else {
                    $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                    $sending_server     = SendingServer::where('plain', 1)->where('two_way', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
                }
            } else {
                // If customer don't have permission creating sending servers
                $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                $sending_server     = SendingServer::where('plain', 1)->where('two_way', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
            }

            $coverage = PlansCoverageCountries::where('plan_id', $plan_id)->where('status', true)->cursor();


            return view('customer.ChatBox.new', compact('breadcrumbs', 'phone_numbers', 'coverage', 'sending_server'));
        }


        /**
         * start new conversion
         *
         * @param Campaigns   $campaign
         * @param SentRequest $request
         *
         * @return RedirectResponse
         * @throws AuthorizationException
         */
        public function sent(Campaigns $campaign, SentRequest $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.chatbox.index')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            $this->authorize('chat_box');


            $input               = $request->except('_token');
            $sender_id           = $request->sender_id;
            $input['originator'] = 'sender_id';

            $user = Auth::user();

            $sms_type = $request->sms_type;

            if ($sms_type == 'unicode') {
                $db_sms_type = 'plain';
            } else {
                $db_sms_type = $sms_type;
            }

            if ($sms_type == 'plain' || $sms_type == 'unicode') {
                $capabilities_type = 'sms';
            } else {
                $capabilities_type = $sms_type;
            }

            if ($user->customer->getOption('sender_id_verification') == 'yes') {

                $check_sender_id = Senderid::where('user_id', $user->id)->where('sender_id', $sender_id)->where('status', 'active')->first();

                $input['originator'] = 'sender_id';


                if ( ! $check_sender_id) {
                    $number = PhoneNumbers::where('user_id', $user->id)->where('number', $sender_id)->where('status', 'assigned')->first();

                    if ( ! $number) {
                        return redirect()->route('customer.chatbox.index')->with([
                            'status'  => 'error',
                            'message' => __('locale.sender_id.sender_id_invalid', ['sender_id' => $sender_id]),
                        ]);
                    }

                    $capabilities = str_contains($number->capabilities, $capabilities_type);

                    if ( ! $capabilities) {
                        return redirect()->route('customer.chatbox.index')->with([
                            'status'  => 'error',
                            'message' => __('locale.sender_id.sender_id_sms_capabilities', ['sender_id' => $sender_id, 'type' => $db_sms_type]),
                        ]);
                    }

                    $input['originator']   = 'phone_number';
                    $input['phone_number'] = $sender_id;

                }
            }

            $data = $this->campaigns->quickSend($campaign, $input);

            if (isset($data->getData()->status)) {
                if ($data->getData()->status == 'success') {

                    $chatbox = ChatBox::where('user_id', Auth::user()->id)->where('from', $request->sender_id)->where('to', $request->recipient)->first();

                    if ( ! $chatbox) {

                        $chatbox = ChatBox::create([
                            'user_id'           => Auth::user()->id,
                            'from'              => $request->sender_id,
                            'to'                => $data->getData()->data->to,
                            'sending_server_id' => $request->sending_server,
                            'notification'      => 0,
                        ]);
                    }


                    if ($chatbox) {
                        ChatBoxMessage::create([
                            'box_id'            => $chatbox->id,
                            'message'           => $request->message,
                            'send_by'           => 'from',
                            'sms_type'          => 'plain',
                            'sending_server_id' => $request->sending_server,
                        ]);

                        $chatbox->touch();

                        return redirect()->route('customer.chatbox.index')->with([
                            'status'  => $data->getData()->status,
                            'message' => $data->getData()->message,
                        ]);
                    }

                    return redirect()->route('customer.chatbox.index')->with([
                        'status'  => $data->getData()->status,
                        'message' => $data->getData()->message,
                    ]);
                }

                return redirect()->route('customer.chatbox.index')->with([
                    'status'  => $data->getData()->status,
                    'message' => $data->getData()->message,
                ]);
            }

            return redirect()->route('customer.chatbox.index')->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);

        }

        /**
         * get chat messages
         *
         * @param ChatBox $box
         *
         * @return JsonResponse
         */
        public function messages(ChatBox $box): JsonResponse
        {
            $box->update([
                'notification' => 0,
            ]);

            $data = ChatBoxMessage::where('box_id', $box->id)->orderBy('created_at')->select('message', 'send_by', 'media_url')->cursor()->toJson();

            return response()->json([
                'status' => 'success',
                'data'   => $data,
            ]);

        }

        /**
         * get chat messages
         *
         * @param ChatBox $box
         *
         * @return JsonResponse
         */
        public function messagesWithNotification(ChatBox $box): JsonResponse
        {
            $data = ChatBoxMessage::where('box_id', $box->id)->orderBy('created_at')->select('message', 'send_by', 'media_url')->cursor()->toJson();

            return response()->json([
                'status' => 'success',
                'data'   => $data,
            ]);

        }


        /**
         * reply message
         *
         * @param ChatBox   $box
         * @param Campaigns $campaign
         * @param Request   $request
         *
         * @return JsonResponse
         * @throws AuthorizationException
         * @throws NumberParseException
         */
        public function reply(ChatBox $box, Campaigns $campaign, Request $request): JsonResponse
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            $this->authorize('chat_box');

            if (empty($request->message)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.campaigns.insert_your_message'),
                ]);
            }

            $user = Auth::user();

            $sending_server = SendingServer::find($box->sending_server_id);

            if ( ! $sending_server) {

                // Check the customer has permissions using sending servers and has his own sending servers
                if ($user->customer->getOption('create_sending_server') == 'yes' && in_array('create_sending_servers', json_decode($user->customer->customerPermissions(), true))) {
                    $plan_id = $user->customer->activeSubscription()->plan_id;

                    if (PlansSendingServer::where('plan_id', $plan_id)->count()) {
                        $sending_server = SendingServer::where('user_id', $user->id)->where('status', true)->first();
                    } else {
                        $sending_server = SendingServer::where('status', true)->first();
                    }
                } else {
                    // If customer don't have permission creating sending servers
                    $sending_server = SendingServer::where('status', true)->first();
                }
            }

            if ( ! $sending_server) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.campaigns.sending_server_not_available'),
                ]);
            }

            $sender_id = $box->from;

            $input = [
                'sender_id'      => $sender_id,
                'sending_server' => $sending_server->id,
                'sms_type'       => 'plain',
                'message'        => $request->message,
                'exist_c_code'   => 'yes',
                'originator'     => 'sender_id',
            ];


            if ($user->customer->getOption('sender_id_verification') == 'yes') {

                $check_sender_id = Senderid::where('user_id', $user->id)->where('sender_id', $sender_id)->where('status', 'active')->first();

                if ( ! $check_sender_id) {
                    $number = PhoneNumbers::where('user_id', $user->id)->where('number', $sender_id)->where('status', 'assigned')->first();

                    if ( ! $number) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => __('locale.sender_id.sender_id_invalid', ['sender_id' => $sender_id]),
                        ]);
                    }

                    $capabilities = str_contains($number->capabilities, 'sms');

                    if ( ! $capabilities) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => __('locale.sender_id.sender_id_sms_capabilities', ['sender_id' => $sender_id, 'type' => 'sms']),
                        ]);
                    }

                    $input['originator']   = 'phone_number';
                    $input['phone_number'] = $sender_id;

                }
            }


            try {

                $phoneUtil         = PhoneNumberUtil::getInstance();
                $phoneNumberObject = $phoneUtil->parse('+' . $box->to);

                if ($phoneUtil->isPossibleNumber($phoneNumberObject)) {
                    $input['country_code'] = $phoneNumberObject->getCountryCode();
                    $input['recipient']    = $phoneNumberObject->getNationalNumber();

                    $data = $this->campaigns->quickSend($campaign, $input);

                    if (isset($data->getData()->status)) {
                        if ($data->getData()->status == 'success') {

                            ChatBoxMessage::create([
                                'box_id'            => $box->id,
                                'message'           => $request->message,
                                'sms_type'          => 'plain',
                                'send_by'           => 'from',
                                'sending_server_id' => $sending_server->id,
                            ]);

                            $box->touch();

                            return response()->json([
                                'status'  => 'success',
                                'message' => __('locale.campaigns.message_successfully_delivered'),
                            ]);
                        }

                        return response()->json([
                            'status'  => $data->getData()->status,
                            'message' => $data->getData()->message,
                        ]);

                    }

                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.something_went_wrong'),
                    ]);
                }

                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.customer.invalid_phone_number'),
                ]);

            } catch (NumberParseException $exception) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $exception->getMessage(),
                ]);
            }
        }


        /**
         * delete chatbox messages
         *
         * @param ChatBox $box
         *
         * @return JsonResponse
         */
        public function delete(ChatBox $box): JsonResponse
        {
            $messages = ChatBoxMessage::where('box_id', $box->id)->delete();
            if ($messages) {
                $box->delete();

                return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.campaigns.sms_was_successfully_deleted'),
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }

        /**
         * add to blacklist
         *
         * @param ChatBox $box
         *
         * @return JsonResponse
         */
        public function block(ChatBox $box): JsonResponse
        {
            $status = Blacklists::create([
                'user_id' => auth()->user()->id,
                'number'  => $box->to,
                'reason'  => 'Blacklisted by ' . auth()->user()->displayName(),
            ]);

            if ($status) {

                $contact = Contacts::where('phone', $box->to)->first();
                $contact?->update([
                        'status' => 'unsubscribe',
                ]);

                return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.blacklist.blacklist_successfully_added'),
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }

    }
