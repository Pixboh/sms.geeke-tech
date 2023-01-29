<?php

namespace App\Http\Controllers\Customer;

use App\Http\Requests\Campaigns\CampaignBuilderRequest;
use App\Http\Requests\Campaigns\ImportRequest;
use App\Http\Requests\Campaigns\ImportVoiceRequest;
use App\Http\Requests\Campaigns\MMSCampaignBuilderRequest;
use App\Http\Requests\Campaigns\MMSImportRequest;
use App\Http\Requests\Campaigns\MMSQuickSendRequest;
use App\Http\Requests\Campaigns\QuickSendRequest;
use App\Http\Requests\Campaigns\VoiceCampaignBuilderRequest;
use App\Http\Requests\Campaigns\VoiceQuickSendRequest;
use App\Http\Requests\Campaigns\WhatsAppCampaignBuilderRequest;
use App\Http\Requests\Campaigns\WhatsAppQuickSendRequest;
use App\Library\Tool;
use App\Models\Campaigns;
use App\Models\ContactGroups;
use App\Models\CsvData;
use App\Models\PhoneNumbers;
use App\Models\Plan;
use App\Models\PlansCoverageCountries;
use App\Models\PlansSendingServer;
use App\Models\Senderid;
use App\Models\SendingServer;
use App\Models\Templates;
use App\Models\TemplateTags;
use App\Repositories\Contracts\CampaignRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use Maatwebsite\Excel\Facades\Excel;
use stdClass;

class CampaignController extends CustomerBaseController
{
    protected CampaignRepository $campaigns;

    /**
     * CampaignController constructor.
     *
     * @param  CampaignRepository  $campaigns
     */
    public function __construct(CampaignRepository $campaigns)
    {
        $this->campaigns = $campaigns;
    }

    /**
     * quick send message
     *
     *
     * @param  Request  $request
     *
     * @return Application|Factory|View|RedirectResponse
     * @throws AuthorizationException
     */
    public function quickSend(Request $request): View|Factory|RedirectResponse|Application
    {
        $this->authorize('sms_quick_send');

        if (isset($request->recipient)) {

            $phone = str_replace(['(', ')', '+', '-', ' '], '', $request->recipient);

            try {
                $phoneUtil         = PhoneNumberUtil::getInstance();
                $phoneNumberObject = $phoneUtil->parse('+'.$phone);
                if ( ! $phoneUtil->isPossibleNumber($phoneNumberObject)) {
                    return redirect()->route('customer.subscriptions.index')->with([
                            'status'  => 'error',
                            'message' => __('locale.customer.invalid_phone_number'),
                    ]);
                }
                if ($phoneNumberObject->isItalianLeadingZero()) {
                    $recipient = '0'.$phoneNumberObject->getNationalNumber();
                } else {
                    $recipient = $phoneNumberObject->getNationalNumber();
                }

            } catch (NumberParseException $e) {
                return redirect()->route('customer.subscriptions.index')->with([
                        'status'  => 'error',
                        'message' => $e->getMessage(),
                ]);
            }

        } else {
            $recipient = null;
        }

        $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.SMS')],
                ['name' => __('locale.menu.Quick Send')],
        ];

        if (Auth::user()->customer->getOption('sender_id_verification') == 'yes') {
            $sender_ids = Senderid::where('user_id', auth()->user()->id)->where('status', 'active')->cursor();
        } else {
            $sender_ids = null;
        }

        $phone_numbers = PhoneNumbers::where('user_id', auth()->user()->id)->where('status', 'assigned')->cursor();

        if ( ! Auth::user()->customer->activeSubscription()) {
            return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
            ]);
        }

        $plan_id = Auth::user()->customer->activeSubscription()->plan_id;

        // Check the customer has permissions using sending servers and has his own sending servers
        if (Auth::user()->customer->getOption('create_sending_server') == 'yes') {
            if (PlansSendingServer::where('plan_id', $plan_id)->count()) {

                $sending_server = SendingServer::where('user_id', Auth::user()->id)->where('plain', 1)->where('status', true)->get();

                if ($sending_server->count() == 0) {
                    $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                    $sending_server     = SendingServer::where('plain', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
                }
            } else {
                $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                $sending_server     = SendingServer::where('plain', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
            }
        } else {
            // If customer don't have permission creating sending servers
            $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
            $sending_server     = SendingServer::where('plain', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
        }

        $coverage  = PlansCoverageCountries::where('plan_id', $plan_id)->where('status', true)->cursor();
        $templates = Templates::where('user_id', auth()->user()->id)->where('status', 1)->cursor();

        return view('customer.Campaigns.quickSend', compact('breadcrumbs', 'sender_ids', 'phone_numbers', 'recipient', 'sending_server', 'coverage', 'templates'));
    }

    /**
     * quick send message
     *
     * @param  Campaigns  $campaign
     * @param  QuickSendRequest  $request
     *
     * @return RedirectResponse
     */
    public function postQuickSend(Campaigns $campaign, QuickSendRequest $request): RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('customer.sms.quick_send')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        if (Auth::user()->customer->activeSubscription()) {
            $plan = Plan::where('status', true)->find(Auth::user()->customer->activeSubscription()->plan_id);
            if ( ! $plan) {
                return redirect()->route('customer.sms.quick_send')->with([
                        'status'  => 'error',
                        'message' => 'Purchased plan is not active. Please contact support team.',
                ]);
            }
        }

        $data = $this->campaigns->quickSend($campaign, $request->except('_token'));

        if ($data->getData()->status == 'error') {
            return redirect()->route('customer.sms.quick_send')->with([
                    'status'  => 'error',
                    'message' => $data->getData()->message,
            ]);
        }

        return redirect()->route('customer.reports.sent')->with([
                'status'  => $data->getData()->status,
                'message' => $data->getData()->message,
        ]);
    }


    /**
     * campaign builder
     *
     * @return Application|Factory|View|RedirectResponse
     * @throws AuthorizationException
     */
    public function campaignBuilder(): View|Factory|RedirectResponse|Application
    {

        $this->authorize('sms_campaign_builder');

        $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.SMS')],
                ['name' => __('locale.menu.Campaign Builder')],
        ];


        if (Auth::user()->customer->getOption('sender_id_verification') == 'yes') {
            $sender_ids = Senderid::where('user_id', auth()->user()->id)->where('status', 'active')->cursor();
        } else {
            $sender_ids = null;
        }

        $phone_numbers = PhoneNumbers::where('user_id', auth()->user()->id)->where('status', 'assigned')->cursor();

        $template_tags  = TemplateTags::cursor();
        $contact_groups = ContactGroups::where('status', 1)->where('customer_id', auth()->user()->id)->cursor();

        $templates = Templates::where('user_id', auth()->user()->id)->where('status', 1)->cursor();

        if ( ! Auth::user()->customer->activeSubscription()) {
            return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
            ]);
        }

        $plan_id = Auth::user()->customer->activeSubscription()->plan_id;

        // Check the customer has permissions using sending servers and has his own sending servers
        if (Auth::user()->customer->getOption('create_sending_server') == 'yes') {
            if (PlansSendingServer::where('plan_id', $plan_id)->count()) {

                $sending_server = SendingServer::where('user_id', Auth::user()->id)->where('plain', 1)->where('status', true)->get();

                if ($sending_server->count() == 0) {
                    $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                    $sending_server     = SendingServer::where('plain', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
                }
            } else {
                $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                $sending_server     = SendingServer::where('plain', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
            }
        } else {
            // If customer don't have permission creating sending servers
            $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
            $sending_server     = SendingServer::where('plain', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
        }

        //    $coverage = PlansCoverageCountries::where('plan_id', $plan_id)->where('status', true)->cursor();


        return view('customer.Campaigns.campaignBuilder', compact('breadcrumbs', 'sender_ids', 'phone_numbers', 'template_tags', 'contact_groups', 'templates', 'sending_server', 'plan_id'));
    }

    /**
     * template info not found
     *
     * @param  Templates  $template
     * @param $id
     *
     * @return JsonResponse
     */
    public function templateData(Templates $template, $id): JsonResponse
    {
        $data = $template->where('user_id', auth()->user()->id)->find($id);
        if ($data) {
            return response()->json([
                    'status'  => 'success',
                    'message' => $data->message,
            ]);
        }

        return response()->json([
                'status'  => 'error',
                'message' => __('locale.templates.template_info_not_found'),
        ]);
    }


    /**
     * store campaign
     *
     *
     * @param  Campaigns  $campaign
     * @param  CampaignBuilderRequest  $request
     *
     * @return RedirectResponse
     */
    public function storeCampaign(Campaigns $campaign, CampaignBuilderRequest $request): RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('customer.sms.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        if (Auth::user()->customer->activeSubscription()) {
            $plan = Plan::where('status', true)->find(Auth::user()->customer->activeSubscription()->plan_id);
            if ( ! $plan) {
                return redirect()->route('customer.sms.campaign_builder')->with([
                        'status'  => 'error',
                        'message' => 'Purchased plan is not active. Please contact support team.',
                ]);
            }
        }

        $data = $this->campaigns->campaignBuilder($campaign, $request->except('_token'));

        if (isset($data->getData()->status)) {

            if ($data->getData()->status == 'success') {
                return redirect()->route('customer.reports.campaigns')->with([
                        'status'  => 'success',
                        'message' => $data->getData()->message,
                ]);
            }

            return redirect()->route('customer.sms.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => $data->getData()->message,
            ]);
        }

        return redirect()->route('customer.sms.campaign_builder')->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
        ]);

    }

    /**
     * send message using file
     *
     * @return Application|Factory|View|RedirectResponse
     * @throws AuthorizationException
     */
    public function import(): View|Factory|RedirectResponse|Application
    {
        $this->authorize('sms_bulk_messages');

        $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.SMS')],
                ['name' => __('locale.menu.Send Using File')],
        ];


        if (Auth::user()->customer->getOption('sender_id_verification') == 'yes') {
            $sender_ids = Senderid::where('user_id', auth()->user()->id)->where('status', 'active')->cursor();
        } else {
            $sender_ids = null;
        }

        $phone_numbers = PhoneNumbers::where('user_id', auth()->user()->id)->where('status', 'assigned')->cursor();


        if ( ! Auth::user()->customer->activeSubscription()) {
            return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
            ]);
        }

        $plan_id = Auth::user()->customer->activeSubscription()->plan_id;

        // Check the customer has permissions using sending servers and has his own sending servers
        if (Auth::user()->customer->getOption('create_sending_server') == 'yes') {
            if (PlansSendingServer::where('plan_id', $plan_id)->count()) {

                $sending_server = SendingServer::where('user_id', Auth::user()->id)->where('plain', 1)->where('status', true)->get();

                if ($sending_server->count() == 0) {
                    $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                    $sending_server     = SendingServer::where('plain', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
                }
            } else {
                $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                $sending_server     = SendingServer::where('plain', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
            }
        } else {
            // If customer don't have permission creating sending servers
            $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
            $sending_server     = SendingServer::where('plain', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
        }

        return view('customer.Campaigns.import', compact('breadcrumbs', 'sender_ids', 'phone_numbers', 'sending_server', 'plan_id'));
    }


    /**
     * send message using file
     *
     * @param  ImportRequest  $request
     *
     * @return Application|Factory|View|RedirectResponse
     */
    public function importCampaign(ImportRequest $request): View|Factory|RedirectResponse|Application
    {
        if ($request->file('import_file')->isValid()) {

            $form_data = $request->except('_token', 'import_file');

            $breadcrumbs = [
                    ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                    ['link' => url('dashboard'), 'name' => __('locale.menu.SMS')],
                    ['name' => __('locale.menu.Send Using File')],
            ];

            $data = Excel::toArray(new stdClass(), $request->file('import_file'))[0];

            $csv_data_file = CsvData::create([
                    'user_id'      => Auth::user()->id,
                    'ref_id'       => uniqid(),
                    'ref_type'     => CsvData::TYPE_CAMPAIGN,
                    'csv_filename' => $request->file('import_file')->getClientOriginalName(),
                    'csv_header'   => $request->has('header'),
                    'csv_data'     => json_encode($data),
            ]);

            $csv_data = array_slice($data, 0, 2);

            return view('customer.Campaigns.import_fields', compact('csv_data', 'csv_data_file', 'breadcrumbs', 'form_data'));
        }

        return redirect()->route('customer.sms.import')->with([
                'status'  => 'error',
                'message' => __('locale.settings.invalid_file'),
        ]);
    }

    /**
     * import processed file
     *
     * @param  Campaigns  $campaign
     * @param  Request  $request
     *
     * @return RedirectResponse
     */
    public function importProcess(Campaigns $campaign, Request $request): RedirectResponse
    {

        if (config('app.stage') == 'demo') {
            return redirect()->route('customer.sms.import')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        if (Auth::user()->customer->activeSubscription()) {
            $plan = Plan::where('status', true)->find(Auth::user()->customer->activeSubscription()->plan_id);
            if ( ! $plan) {
                return redirect()->route('customer.sms.import')->with([
                        'status'  => 'error',
                        'message' => 'Purchased plan is not active. Please contact support team.',
                ]);
            }
        }

        $form_data = json_decode($request->form_data, true);

        $data = $this->campaigns->sendUsingFile($campaign, $request->except('_token'));

        if (isset($data->getData()->status)) {
            if ($form_data['sms_type'] == 'whatsapp') {
                return redirect()->route('customer.whatsapp.import')->with([
                        'status'  => $data->getData()->status,
                        'message' => $data->getData()->message,
                ]);
            }

            if ($form_data['sms_type'] == 'voice') {
                return redirect()->route('customer.voice.import')->with([
                        'status'  => $data->getData()->status,
                        'message' => $data->getData()->message,
                ]);
            }

            if ($form_data['sms_type'] == 'mms') {
                return redirect()->route('customer.mms.import')->with([
                        'status'  => $data->getData()->status,
                        'message' => $data->getData()->message,
                ]);
            }

            return redirect()->route('customer.sms.import')->with([
                    'status'  => $data->getData()->status,
                    'message' => $data->getData()->message,
            ]);

        }

        if ($form_data['sms_type'] == 'whatsapp') {
            return redirect()->route('customer.whatsapp.import')->with([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }
        if ($form_data['sms_type'] == 'mms') {
            return redirect()->route('customer.mms.import')->with([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }

        if ($form_data['sms_type'] == 'voice') {
            return redirect()->route('customer.voice.import')->with([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }

        return redirect()->route('customer.sms.import')->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | voice module
    |--------------------------------------------------------------------------
    |
    |
    |
    */

    /**
     * @param  Request  $request
     *
     * @return Application|Factory|View|RedirectResponse
     * @throws AuthorizationException
     */
    public function voiceQuickSend(Request $request): View|Factory|RedirectResponse|Application
    {
        $this->authorize('voice_quick_send');

        $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.Voice')],
                ['name' => __('locale.menu.Quick Send')],
        ];


        if (Auth::user()->customer->getOption('sender_id_verification') == 'yes') {
            $sender_ids = Senderid::where('user_id', auth()->user()->id)->where('status', 'active')->cursor();
        } else {
            $sender_ids = null;
        }

        $phone_numbers = PhoneNumbers::where('user_id', auth()->user()->id)->where('status', 'assigned')->cursor();


        if ( ! Auth::user()->customer->activeSubscription()) {
            return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
            ]);
        }

        $plan_id = Auth::user()->customer->activeSubscription()->plan_id;

        // Check the customer has permissions using sending servers and has his own sending servers
        if (Auth::user()->customer->getOption('create_sending_server') == 'yes') {
            if (PlansSendingServer::where('plan_id', $plan_id)->count()) {

                $sending_server = SendingServer::where('user_id', Auth::user()->id)->where('voice', 1)->where('status', true)->get();

                if ($sending_server->count() == 0) {
                    $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                    $sending_server     = SendingServer::where('voice', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
                }
            } else {
                $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                $sending_server     = SendingServer::where('voice', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
            }
        } else {
            // If customer don't have permission creating sending servers
            $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
            $sending_server     = SendingServer::where('voice', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
        }

        $coverage  = PlansCoverageCountries::where('plan_id', $plan_id)->where('status', true)->cursor();
        $templates = Templates::where('user_id', auth()->user()->id)->where('status', 1)->cursor();

        $recipient = $request->recipient;

        return view('customer.Campaigns.voiceQuickSend', compact('breadcrumbs', 'sender_ids', 'phone_numbers', 'recipient', 'sending_server', 'coverage', 'templates'));
    }

    /**
     * quick send message
     *
     * @param  Campaigns  $campaign
     * @param  VoiceQuickSendRequest  $request
     *
     * @return RedirectResponse
     */
    public function postVoiceQuickSend(Campaigns $campaign, VoiceQuickSendRequest $request): RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('customer.voice.quick_send')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        if (Auth::user()->customer->activeSubscription()) {
            $plan = Plan::where('status', true)->find(Auth::user()->customer->activeSubscription()->plan_id);
            if ( ! $plan) {
                return redirect()->route('customer.voice.quick_send')->with([
                        'status'  => 'error',
                        'message' => 'Purchased plan is not active. Please contact support team.',
                ]);
            }
        } else {
            return redirect()->route('customer.voice.quick_send')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
            ]);
        }

        $data = $this->campaigns->quickSend($campaign, $request->except('_token'));

        if ($data->getData()->status == 'success') {
            return redirect()->route('customer.reports.sent')->with([
                    'status'  => 'success',
                    'message' => $data->getData()->message,
            ]);
        }

        return redirect()->route('customer.reports.sent')->with([
                'status'  => $data->getData()->status,
                'message' => $data->getData()->message,
        ]);
    }


    /**
     * @return Application|Factory|View|RedirectResponse
     * @throws AuthorizationException
     */
    public function voiceCampaignBuilder(): View|Factory|RedirectResponse|Application
    {

        $this->authorize('sms_campaign_builder');

        $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.Voice')],
                ['name' => __('locale.menu.Campaign Builder')],
        ];


        if (Auth::user()->customer->getOption('sender_id_verification') == 'yes') {
            $sender_ids = Senderid::where('user_id', auth()->user()->id)->where('status', 'active')->cursor();
        } else {
            $sender_ids = null;
        }

        $phone_numbers  = PhoneNumbers::where('user_id', auth()->user()->id)->where('status', 'assigned')->cursor();
        $template_tags  = TemplateTags::cursor();
        $contact_groups = ContactGroups::where('status', 1)->where('customer_id', auth()->user()->id)->cursor();

        $templates = Templates::where('user_id', auth()->user()->id)->where('status', 1)->cursor();


        if ( ! Auth::user()->customer->activeSubscription()) {
            return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
            ]);
        }

        $plan_id = Auth::user()->customer->activeSubscription()->plan_id;

        // Check the customer has permissions using sending servers and has his own sending servers
        if (Auth::user()->customer->getOption('create_sending_server') == 'yes') {
            if (PlansSendingServer::where('plan_id', $plan_id)->count()) {

                $sending_server = SendingServer::where('user_id', Auth::user()->id)->where('voice', 1)->where('status', true)->get();

                if ($sending_server->count() == 0) {
                    $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                    $sending_server     = SendingServer::where('voice', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
                }
            } else {
                $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                $sending_server     = SendingServer::where('voice', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
            }
        } else {
            // If customer don't have permission creating sending servers
            $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
            $sending_server     = SendingServer::where('voice', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
        }

        $coverage = PlansCoverageCountries::where('plan_id', $plan_id)->where('status', true)->cursor();


        return view('customer.Campaigns.voiceCampaignBuilder', compact('breadcrumbs', 'sender_ids', 'phone_numbers', 'template_tags', 'contact_groups', 'templates', 'sending_server', 'coverage', 'plan_id'));
    }

    /**
     * store campaign
     *
     *
     * @param  Campaigns  $campaign
     * @param  VoiceCampaignBuilderRequest  $request
     *
     * @return RedirectResponse
     */
    public function storeVoiceCampaign(Campaigns $campaign, VoiceCampaignBuilderRequest $request): RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('customer.voice.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        if (Auth::user()->customer->activeSubscription()) {
            $plan = Plan::where('status', true)->find(Auth::user()->customer->activeSubscription()->plan_id);
            if ( ! $plan) {
                return redirect()->route('customer.voice.campaign_builder')->with([
                        'status'  => 'error',
                        'message' => 'Purchased plan is not active. Please contact support team.',
                ]);
            }
        }

        $data = $this->campaigns->campaignBuilder($campaign, $request->except('_token'));

        if (isset($data->getData()->status)) {

            if ($data->getData()->status == 'success') {
                return redirect()->route('customer.reports.campaigns')->with([
                        'status'  => 'success',
                        'message' => $data->getData()->message,
                ]);
            }

            return redirect()->route('customer.voice.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => $data->getData()->message,
            ]);
        }

        return redirect()->route('customer.voice.campaign_builder')->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
        ]);

    }


    /**
     * @return Application|Factory|View|RedirectResponse
     * @throws AuthorizationException
     */
    public function voiceImport(): View|Factory|RedirectResponse|Application
    {
        $this->authorize('sms_bulk_messages');

        $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.Voice')],
                ['name' => __('locale.menu.Send Using File')],
        ];


        if (Auth::user()->customer->getOption('sender_id_verification') == 'yes') {
            $sender_ids = Senderid::where('user_id', auth()->user()->id)->where('status', 'active')->cursor();
        } else {
            $sender_ids = null;
        }

        $phone_numbers = PhoneNumbers::where('user_id', auth()->user()->id)->where('status', 'assigned')->cursor();


        if ( ! Auth::user()->customer->activeSubscription()) {
            return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
            ]);
        }

        $plan_id = Auth::user()->customer->activeSubscription()->plan_id;

        // Check the customer has permissions using sending servers and has his own sending servers
        if (Auth::user()->customer->getOption('create_sending_server') == 'yes') {
            if (PlansSendingServer::where('plan_id', $plan_id)->count()) {

                $sending_server = SendingServer::where('user_id', Auth::user()->id)->where('voice', 1)->where('status', true)->get();

                if ($sending_server->count() == 0) {
                    $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                    $sending_server     = SendingServer::where('voice', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
                }
            } else {
                $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                $sending_server     = SendingServer::where('voice', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
            }
        } else {
            // If customer don't have permission creating sending servers
            $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
            $sending_server     = SendingServer::where('voice', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
        }

        return view('customer.Campaigns.voiceImport', compact('breadcrumbs', 'sender_ids', 'phone_numbers', 'sending_server', 'plan_id'));
    }


    /**
     * send message using file
     *
     * @param  ImportVoiceRequest  $request
     *
     * @return Application|Factory|View|RedirectResponse
     */
    public function importVoiceCampaign(ImportVoiceRequest $request): View|Factory|RedirectResponse|Application
    {
        if ($request->file('import_file')->isValid()) {

            $form_data = $request->except('_token', 'import_file');

            $breadcrumbs = [
                    ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                    ['link' => url('dashboard'), 'name' => __('locale.menu.Voice')],
                    ['name' => __('locale.menu.Send Using File')],
            ];

            $path = $request->file('import_file')->getRealPath();
            $data = array_map('str_getcsv', file($path));

            $csv_data_file = CsvData::create([
                    'user_id'      => Auth::user()->id,
                    'ref_id'       => uniqid(),
                    'ref_type'     => CsvData::TYPE_CAMPAIGN,
                    'csv_filename' => $request->file('import_file')->getClientOriginalName(),
                    'csv_header'   => $request->has('header'),
                    'csv_data'     => json_encode($data),
            ]);

            $csv_data = array_slice($data, 0, 2);

            return view('customer.Campaigns.import_fields', compact('csv_data', 'csv_data_file', 'breadcrumbs', 'form_data'));
        }

        return redirect()->route('customer.voice.import')->with([
                'status'  => 'error',
                'message' => __('locale.settings.invalid_file'),
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | MMS module
    |--------------------------------------------------------------------------
    |
    |
    |
    */


    /**
     * @param  Request  $request
     *
     * @return Application|Factory|View|RedirectResponse
     * @throws AuthorizationException
     */
    public function mmsQuickSend(Request $request): View|Factory|RedirectResponse|Application
    {
        $this->authorize('mms_quick_send');

        $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.MMS')],
                ['name' => __('locale.menu.Quick Send')],
        ];


        if (Auth::user()->customer->getOption('sender_id_verification') == 'yes') {
            $sender_ids = Senderid::where('user_id', auth()->user()->id)->where('status', 'active')->cursor();
        } else {
            $sender_ids = null;
        }

        $phone_numbers = PhoneNumbers::where('user_id', auth()->user()->id)->where('status', 'assigned')->cursor();


        if ( ! Auth::user()->customer->activeSubscription()) {
            return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
            ]);
        }

        $plan_id = Auth::user()->customer->activeSubscription()->plan_id;

        // Check the customer has permissions using sending servers and has his own sending servers
        if (Auth::user()->customer->getOption('create_sending_server') == 'yes') {
            if (PlansSendingServer::where('plan_id', $plan_id)->count()) {

                $sending_server = SendingServer::where('user_id', Auth::user()->id)->where('mms', 1)->where('status', true)->get();

                if ($sending_server->count() == 0) {
                    $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                    $sending_server     = SendingServer::where('mms', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
                }
            } else {
                $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                $sending_server     = SendingServer::where('mms', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
            }
        } else {
            // If customer don't have permission creating sending servers
            $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
            $sending_server     = SendingServer::where('mms', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
        }

        $coverage  = PlansCoverageCountries::where('plan_id', $plan_id)->where('status', true)->cursor();
        $templates = Templates::where('user_id', auth()->user()->id)->where('status', 1)->cursor();

        $recipient = $request->recipient;

        return view('customer.Campaigns.mmsQuickSend', compact('breadcrumbs', 'sender_ids', 'phone_numbers', 'recipient', 'coverage', 'sending_server', 'templates'));
    }

    /**
     * quick send message
     *
     * @param  Campaigns  $campaign
     * @param  MMSQuickSendRequest  $request
     *
     * @return RedirectResponse
     */
    public function postMMSQuickSend(Campaigns $campaign, MMSQuickSendRequest $request): RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('customer.mms.quick_send')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        if (Auth::user()->customer->activeSubscription()) {
            $plan = Plan::where('status', true)->find(Auth::user()->customer->activeSubscription()->plan_id);
            if ( ! $plan) {
                return redirect()->route('customer.mms.quick_send')->with([
                        'status'  => 'error',
                        'message' => 'Purchased plan is not active. Please contact support team.',
                ]);
            }
        }

        $data = $this->campaigns->quickSend($campaign, $request->except('_token'));

        if (isset($data->getData()->status)) {
            return redirect()->route('customer.reports.sent')->with([
                    'status'  => $data->getData()->status,
                    'message' => $data->getData()->message,
            ]);
        }

        return redirect()->route('customer.mms.quick_send')->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
        ]);
    }

    /**
     * @return Application|Factory|View|RedirectResponse
     * @throws AuthorizationException
     */
    public function mmsCampaignBuilder(): View|Factory|RedirectResponse|Application
    {

        $this->authorize('mms_campaign_builder');

        $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.MMS')],
                ['name' => __('locale.menu.Campaign Builder')],
        ];


        if (Auth::user()->customer->getOption('sender_id_verification') == 'yes') {
            $sender_ids = Senderid::where('user_id', auth()->user()->id)->where('status', 'active')->cursor();
        } else {
            $sender_ids = null;
        }

        $phone_numbers = PhoneNumbers::where('user_id', auth()->user()->id)->where('status', 'assigned')->cursor();

        $template_tags  = TemplateTags::cursor();
        $contact_groups = ContactGroups::where('status', 1)->where('customer_id', auth()->user()->id)->cursor();

        $templates = Templates::where('user_id', auth()->user()->id)->where('status', 1)->cursor();


        if ( ! Auth::user()->customer->activeSubscription()) {
            return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
            ]);
        }

        $plan_id = Auth::user()->customer->activeSubscription()->plan_id;

        // Check the customer has permissions using sending servers and has his own sending servers
        if (Auth::user()->customer->getOption('create_sending_server') == 'yes') {
            if (PlansSendingServer::where('plan_id', $plan_id)->count()) {

                $sending_server = SendingServer::where('user_id', Auth::user()->id)->where('mms', 1)->where('status', true)->get();

                if ($sending_server->count() == 0) {
                    $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                    $sending_server     = SendingServer::where('mms', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
                }
            } else {
                $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                $sending_server     = SendingServer::where('mms', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
            }
        } else {
            // If customer don't have permission creating sending servers
            $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
            $sending_server     = SendingServer::where('mms', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
        }

        //   $coverage = PlansCoverageCountries::where('plan_id', $plan_id)->where('status', true)->cursor();


        return view('customer.Campaigns.mmsCampaignBuilder', compact('breadcrumbs', 'sender_ids', 'phone_numbers', 'template_tags', 'contact_groups', 'templates', 'sending_server', 'plan_id'));
    }


    /**
     * store campaign
     *
     *
     * @param  Campaigns  $campaign
     * @param  MMSCampaignBuilderRequest  $request
     *
     * @return RedirectResponse
     */
    public function storeMMSCampaign(Campaigns $campaign, MMSCampaignBuilderRequest $request): RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('customer.mms.quick_send')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        if (Auth::user()->customer->activeSubscription()) {
            $plan = Plan::where('status', true)->find(Auth::user()->customer->activeSubscription()->plan_id);
            if ( ! $plan) {
                return redirect()->route('customer.mms.quick_send')->with([
                        'status'  => 'error',
                        'message' => 'Purchased plan is not active. Please contact support team.',
                ]);
            }
        }

        $data = $this->campaigns->campaignBuilder($campaign, $request->except('_token'));

        if (isset($data->getData()->status)) {

            if ($data->getData()->status == 'success') {
                return redirect()->route('customer.reports.campaigns')->with([
                        'status'  => 'success',
                        'message' => $data->getData()->message,
                ]);
            }

            return redirect()->route('customer.mms.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => $data->getData()->message,
            ]);
        }

        return redirect()->route('customer.mms.campaign_builder')->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
        ]);

    }

    /**
     *
     * @return Application|Factory|View|RedirectResponse
     * @throws AuthorizationException
     */
    public function mmsImport(): View|Factory|RedirectResponse|Application
    {
        $this->authorize('mms_bulk_messages');

        $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.MMS')],
                ['name' => __('locale.menu.Send Using File')],
        ];


        if (Auth::user()->customer->getOption('sender_id_verification') == 'yes') {
            $sender_ids = Senderid::where('user_id', auth()->user()->id)->where('status', 'active')->cursor();
        } else {
            $sender_ids = null;
        }

        $phone_numbers = PhoneNumbers::where('user_id', auth()->user()->id)->where('status', 'assigned')->cursor();

        if ( ! Auth::user()->customer->activeSubscription()) {
            return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
            ]);
        }

        $plan_id = Auth::user()->customer->activeSubscription()->plan_id;

        // Check the customer has permissions using sending servers and has his own sending servers
        if (Auth::user()->customer->getOption('create_sending_server') == 'yes') {
            if (PlansSendingServer::where('plan_id', $plan_id)->count()) {

                $sending_server = SendingServer::where('user_id', Auth::user()->id)->where('mms', 1)->where('status', true)->get();

                if ($sending_server->count() == 0) {
                    $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                    $sending_server     = SendingServer::where('mms', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
                }
            } else {
                $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                $sending_server     = SendingServer::where('mms', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
            }
        } else {
            // If customer don't have permission creating sending servers
            $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
            $sending_server     = SendingServer::where('mms', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
        }


        return view('customer.Campaigns.mmsImport', compact('breadcrumbs', 'sender_ids', 'phone_numbers', 'sending_server', 'plan_id'));
    }


    /**
     * send message using file
     *
     * @param  MMSImportRequest  $request
     *
     * @return Application|Factory|View|RedirectResponse
     */
    public function importMMSCampaign(MMSImportRequest $request): View|Factory|RedirectResponse|Application
    {

        if ($request->file('import_file')->isValid()) {

            $media_url = Tool::uploadImage($request->mms_file);

            $form_data              = $request->except('_token', 'import_file', 'mms_file');
            $form_data['media_url'] = $media_url;

            $breadcrumbs = [
                    ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                    ['link' => url('dashboard'), 'name' => __('locale.menu.MMS')],
                    ['name' => __('locale.menu.Send Using File')],
            ];

            $path = $request->file('import_file')->getRealPath();
            $data = array_map('str_getcsv', file($path));

            $csv_data_file = CsvData::create([
                    'user_id'      => Auth::user()->id,
                    'ref_id'       => uniqid(),
                    'ref_type'     => CsvData::TYPE_CAMPAIGN,
                    'csv_filename' => $request->file('import_file')->getClientOriginalName(),
                    'csv_header'   => $request->has('header'),
                    'csv_data'     => json_encode($data),
            ]);

            $csv_data = array_slice($data, 0, 2);

            return view('customer.Campaigns.import_fields', compact('csv_data', 'csv_data_file', 'breadcrumbs', 'form_data'));
        }

        return redirect()->route('customer.mms.import')->with([
                'status'  => 'error',
                'message' => __('locale.settings.invalid_file'),
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | whatsapp module
    |--------------------------------------------------------------------------
    |
    |
    |
    */


    /**
     * whatsapp quick send
     *
     * @param  Request  $request
     *
     * @return Application|Factory|View|RedirectResponse
     * @throws AuthorizationException
     */
    public function whatsAppQuickSend(Request $request): View|Factory|RedirectResponse|Application
    {
        $this->authorize('whatsapp_quick_send');

        $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.WhatsApp')],
                ['name' => __('locale.menu.Quick Send')],
        ];


        if (Auth::user()->customer->getOption('sender_id_verification') == 'yes') {
            $sender_ids = Senderid::where('user_id', auth()->user()->id)->where('status', 'active')->cursor();
        } else {
            $sender_ids = null;
        }

        $phone_numbers = PhoneNumbers::where('user_id', auth()->user()->id)->where('status', 'assigned')->cursor();

        if ( ! Auth::user()->customer->activeSubscription()) {
            return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
            ]);
        }

        $plan_id = Auth::user()->customer->activeSubscription()->plan_id;

        // Check the customer has permissions using sending servers and has his own sending servers
        if (Auth::user()->customer->getOption('create_sending_server') == 'yes') {
            if (PlansSendingServer::where('plan_id', $plan_id)->count()) {

                $sending_server = SendingServer::where('user_id', Auth::user()->id)->where('whatsapp', 1)->where('status', true)->get();

                if ($sending_server->count() == 0) {
                    $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                    $sending_server     = SendingServer::where('whatsapp', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
                }
            } else {
                $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                $sending_server     = SendingServer::where('whatsapp', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
            }
        } else {
            // If customer don't have permission creating sending servers
            $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
            $sending_server     = SendingServer::where('whatsapp', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
        }

        $coverage  = PlansCoverageCountries::where('plan_id', $plan_id)->where('status', true)->cursor();
        $templates = Templates::where('user_id', auth()->user()->id)->where('status', 1)->cursor();

        $recipient = $request->recipient;

        return view('customer.Campaigns.whatsAppQuickSend', compact('breadcrumbs', 'sender_ids', 'phone_numbers', 'recipient', 'sending_server', 'coverage','templates'));
    }

    /**
     * quick send message
     *
     * @param  Campaigns  $campaign
     * @param  WhatsAppQuickSendRequest  $request
     *
     * @return RedirectResponse
     */
    public function postWhatsAppQuickSend(Campaigns $campaign, WhatsAppQuickSendRequest $request): RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('customer.whatsapp.quick_send')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        if (Auth::user()->customer->activeSubscription()) {
            $plan = Plan::where('status', true)->find(Auth::user()->customer->activeSubscription()->plan_id);
            if ( ! $plan) {
                return redirect()->route('customer.sms.quick_send')->with([
                        'status'  => 'error',
                        'message' => 'Purchased plan is not active. Please contact support team.',
                ]);
            }
        }

        $data = $this->campaigns->quickSend($campaign, $request->except('_token'));

        if (isset($data->getData()->status)) {
            return redirect()->route('customer.reports.sent')->with([
                    'status'  => $data->getData()->status,
                    'message' => $data->getData()->message,
            ]);
        }

        return redirect()->route('customer.whatsapp.quick_send')->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
        ]);
    }

    /**
     * whatsapp campaign builder
     *
     * @return Application|Factory|View|RedirectResponse
     * @throws AuthorizationException
     */
    public function whatsappCampaignBuilder(): View|Factory|RedirectResponse|Application
    {

        $this->authorize('whatsapp_campaign_builder');

        $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.WhatsApp')],
                ['name' => __('locale.menu.Campaign Builder')],
        ];


        if (Auth::user()->customer->getOption('sender_id_verification') == 'yes') {
            $sender_ids = Senderid::where('user_id', auth()->user()->id)->where('status', 'active')->cursor();
        } else {
            $sender_ids = null;
        }

        $phone_numbers = PhoneNumbers::where('user_id', auth()->user()->id)->where('status', 'assigned')->cursor();

        $template_tags  = TemplateTags::cursor();
        $contact_groups = ContactGroups::where('status', 1)->where('customer_id', auth()->user()->id)->cursor();

        $templates = Templates::where('user_id', auth()->user()->id)->where('status', 1)->cursor();

        if ( ! Auth::user()->customer->activeSubscription()) {
            return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
            ]);
        }

        $plan_id = Auth::user()->customer->activeSubscription()->plan_id;

        // Check the customer has permissions using sending servers and has his own sending servers
        if (Auth::user()->customer->getOption('create_sending_server') == 'yes') {
            if (PlansSendingServer::where('plan_id', $plan_id)->count()) {

                $sending_server = SendingServer::where('user_id', Auth::user()->id)->where('whatsapp', 1)->where('status', true)->get();

                if ($sending_server->count() == 0) {
                    $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                    $sending_server     = SendingServer::where('whatsapp', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
                }
            } else {
                $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                $sending_server     = SendingServer::where('whatsapp', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
            }
        } else {
            // If customer don't have permission creating sending servers
            $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
            $sending_server     = SendingServer::where('whatsapp', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
        }

        //  $coverage = PlansCoverageCountries::where('plan_id', $plan_id)->where('status', true)->cursor();


        return view('customer.Campaigns.whatsAppCampaignBuilder', compact('breadcrumbs', 'sender_ids', 'phone_numbers', 'template_tags', 'contact_groups', 'templates', 'sending_server', 'plan_id'));
    }


    /**
     * store campaign
     *
     *
     * @param  Campaigns  $campaign
     * @param  WhatsAppCampaignBuilderRequest  $request
     *
     * @return RedirectResponse
     */
    public function storeWhatsAppCampaign(Campaigns $campaign, WhatsAppCampaignBuilderRequest $request): RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('customer.whatsapp.quick_send')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        if (Auth::user()->customer->activeSubscription()) {
            $plan = Plan::where('status', true)->find(Auth::user()->customer->activeSubscription()->plan_id);
            if ( ! $plan) {
                return redirect()->route('customer.sms.quick_send')->with([
                        'status'  => 'error',
                        'message' => 'Purchased plan is not active. Please contact support team.',
                ]);
            }
        }

        $data = $this->campaigns->campaignBuilder($campaign, $request->except('_token'));

        if (isset($data->getData()->status)) {

            if ($data->getData()->status == 'success') {
                return redirect()->route('customer.reports.campaigns')->with([
                        'status'  => 'success',
                        'message' => $data->getData()->message,
                ]);
            }

            return redirect()->route('customer.whatsapp.campaign_builder')->with([
                    'status'  => 'error',
                    'message' => $data->getData()->message,
            ]);
        }

        return redirect()->route('customer.whatsapp.campaign_builder')->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
        ]);

    }

    /**
     * whatsapp send message using file
     *
     * @return Application|Factory|View|RedirectResponse
     * @throws AuthorizationException
     */
    public function whatsappImport(): View|Factory|RedirectResponse|Application
    {
        $this->authorize('whatsapp_bulk_messages');

        $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.WhatsApp')],
                ['name' => __('locale.menu.Send Using File')],
        ];


        if (Auth::user()->customer->getOption('sender_id_verification') == 'yes') {
            $sender_ids = Senderid::where('user_id', auth()->user()->id)->where('status', 'active')->cursor();
        } else {
            $sender_ids = null;
        }

        $phone_numbers = PhoneNumbers::where('user_id', auth()->user()->id)->where('status', 'assigned')->cursor();

        if ( ! Auth::user()->customer->activeSubscription()) {
            return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
            ]);
        }

        $plan_id = Auth::user()->customer->activeSubscription()->plan_id;

        // Check the customer has permissions using sending servers and has his own sending servers
        if (Auth::user()->customer->getOption('create_sending_server') == 'yes') {
            if (PlansSendingServer::where('plan_id', $plan_id)->count()) {

                $sending_server = SendingServer::where('user_id', Auth::user()->id)->where('whatsapp', 1)->where('status', true)->get();

                if ($sending_server->count() == 0) {
                    $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                    $sending_server     = SendingServer::where('whatsapp', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
                }
            } else {
                $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
                $sending_server     = SendingServer::where('whatsapp', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
            }
        } else {
            // If customer don't have permission creating sending servers
            $sending_server_ids = PlansSendingServer::where('plan_id', $plan_id)->pluck('sending_server_id')->toArray();
            $sending_server     = SendingServer::where('whatsapp', 1)->where('status', true)->whereIn('id', $sending_server_ids)->get();
        }


        return view('customer.Campaigns.whatsAppImport', compact('breadcrumbs', 'sender_ids', 'phone_numbers', 'sending_server', 'plan_id'));
    }


    /**
     * send message using file
     *
     * @param  ImportRequest  $request
     *
     * @return Application|Factory|View|RedirectResponse
     */
    public function importWhatsAppCampaign(ImportRequest $request): View|Factory|RedirectResponse|Application
    {
        if ($request->file('import_file')->isValid()) {

            $form_data = $request->except('_token', 'import_file');

            $breadcrumbs = [
                    ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                    ['link' => url('dashboard'), 'name' => __('locale.menu.SMS')],
                    ['name' => __('locale.menu.Send Using File')],
            ];

            $path = $request->file('import_file')->getRealPath();
            $data = array_map('str_getcsv', file($path));

            $csv_data_file = CsvData::create([
                    'user_id'      => Auth::user()->id,
                    'ref_id'       => uniqid(),
                    'ref_type'     => CsvData::TYPE_CAMPAIGN,
                    'csv_filename' => $request->file('import_file')->getClientOriginalName(),
                    'csv_header'   => $request->has('header'),
                    'csv_data'     => json_encode($data),
            ]);

            $csv_data = array_slice($data, 0, 2);

            return view('customer.Campaigns.import_fields', compact('csv_data', 'csv_data_file', 'breadcrumbs', 'form_data'));
        }

        return redirect()->route('customer.whatsapp.import')->with([
                'status'  => 'error',
                'message' => __('locale.settings.invalid_file'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Version 3.5
    |--------------------------------------------------------------------------
    |
    | Campaign pause, restart, resend
    |
    */


    /**
     * Pause the Campaign
     *
     * @param  Campaigns  $campaign
     *
     * @return JsonResponse
     */
    public function campaignPause(Campaigns $campaign)
    {
        if (config('app.stage') == 'demo') {
            return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $data = $this->campaigns->pause($campaign);

        if (isset($data->getData()->status)) {

            if ($data->getData()->status == 'success') {
                return response()->json([
                        'status'  => 'success',
                        'message' => $data->getData()->message,
                ]);
            }

            return response()->json([
                    'status'  => 'error',
                    'message' => $data->getData()->message,
            ]);
        }

        return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
        ]);
    }


    /**
     * Restart the Campaign
     *
     * @param  Campaigns  $campaign
     *
     * @return JsonResponse
     */
    public function campaignRestart(Campaigns $campaign)
    {
        if (config('app.stage') == 'demo') {
            return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $data = $this->campaigns->restart($campaign);

        if (isset($data->getData()->status)) {

            if ($data->getData()->status == 'success') {
                return response()->json([
                        'status'  => 'success',
                        'message' => $data->getData()->message,
                ]);
            }

            return response()->json([
                    'status'  => 'error',
                    'message' => $data->getData()->message,
            ]);
        }

        return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
        ]);
    }

    /**
     * Resend the Campaign
     *
     * @param  Campaigns  $campaign
     *
     * @return JsonResponse
     */
    public function campaignResend(Campaigns $campaign)
    {
        if (config('app.stage') == 'demo') {
            return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $data = $this->campaigns->resend($campaign);

        if (isset($data->getData()->status)) {

            if ($data->getData()->status == 'success') {
                return response()->json([
                        'status'  => 'success',
                        'message' => $data->getData()->message,
                ]);
            }

            return response()->json([
                    'status'  => 'error',
                    'message' => $data->getData()->message,
            ]);
        }

        return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
        ]);
    }

}
