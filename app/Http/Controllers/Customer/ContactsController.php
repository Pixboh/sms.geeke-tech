<?php

namespace App\Http\Controllers\Customer;

use App\Exceptions\GeneralException;
use App\Http\Requests\Contacts\ImportContact;
use App\Http\Requests\Contacts\NewContactGroup;
use App\Http\Requests\Contacts\StoreContact;
use App\Http\Requests\Contacts\UpdateContact;
use App\Http\Requests\Contacts\UpdateContactGroup;
use App\Http\Requests\Contacts\UpdateContactGroupMessage;
use App\Jobs\ImportContacts;
use App\Jobs\ReplicateContacts;
use App\Models\Blacklists;
use App\Models\SystemJob;
use App\Library\Tool;
use App\Models\ContactGroups;
use App\Models\ContactGroupsOptinKeywords;
use App\Models\ContactGroupsOptoutKeywords;
use App\Models\Contacts;
use App\Models\ContactsCustomField;
use App\Models\CsvData;
use App\Models\Keywords;
use App\Models\PhoneNumbers;
use App\Models\PlansCoverageCountries;
use App\Models\PlansSendingServer;
use App\Models\Senderid;
use App\Models\SendingServer;
use App\Models\TemplateTags;
use App\Models\User;
use App\Repositories\Contracts\ContactsRepository;
use App\Rules\Phone;
use Arcanedev\NoCaptcha\Rules\CaptchaRule;
use Box\Spout\Common\Exception\InvalidArgumentException;
use Box\Spout\Common\Exception\IOException;
use Box\Spout\Common\Exception\UnsupportedTypeException;
use Box\Spout\Writer\Exception\WriterNotOpenedException;
use Generator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use JetBrains\PhpStorm\NoReturn;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use Maatwebsite\Excel\Facades\Excel;
use Rap2hpoutre\FastExcel\FastExcel;
use stdClass;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ContactsController extends CustomerBaseController
{
    /**
     * @var ContactsRepository $contactGroups
     */
    protected ContactsRepository $contactGroups;


    public function __construct(ContactsRepository $contactGroups)
    {
        $this->contactGroups = $contactGroups;
    }


    /**
     * view all contact list
     *
     * @return Application|Factory|View
     * @throws AuthorizationException
     */
    public function index(): View|Factory|Application
    {

        $this->authorize('view_contact_group');

        $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.Contacts')],
                ['name' => __('locale.contacts.contact_groups')],
        ];


        return view('customer.contactGroups.index', compact('breadcrumbs'));
    }


    /**
     * search contact groups with given data
     *
     * @param  Request  $request
     *
     * @throws AuthorizationException
     */

    #[NoReturn] public function search(Request $request): void
    {

        $this->authorize('view_contact_group');

        $columns = [
                0 => 'responsive_id',
                1 => 'uid',
                2 => 'uid',
                3 => 'name',
                4 => 'contacts',
                5 => 'created_at',
                6 => 'status',
                7 => 'action',
        ];

        $totalData = ContactGroups::where('customer_id', auth()->user()->id)->count();

        $totalFiltered = $totalData;

        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir   = $request->input('order.0.dir');

        if (empty($request->input('search.value'))) {
            $contact_groups = ContactGroups::where('customer_id', auth()->user()->id)->offset($start)
                                           ->limit($limit)
                                           ->orderBy($order, $dir)
                                           ->get();
        } else {
            $search = $request->input('search.value');

            $contact_groups = ContactGroups::where('customer_id', auth()->user()->id)->whereLike(['uid', 'name', 'status', 'created_at'], $search)
                                           ->offset($start)
                                           ->limit($limit)
                                           ->orderBy($order, $dir)
                                           ->get();

            $totalFiltered = ContactGroups::where('customer_id', auth()->user()->id)->whereLike(['uid', 'name', 'status', 'created_at'], $search)->count();
        }

        $data = [];
        if ( ! empty($contact_groups)) {
            foreach ($contact_groups as $group) {

                if ($group->status === true) {
                    $status = 'checked';
                } else {
                    $status = '';
                }

                $nestedData['responsive_id'] = '';
                $nestedData['uid']           = $group->uid;
                $nestedData['name']          = $group->name;
                $nestedData['contacts']      = Tool::number_with_delimiter($group->subscribersCount($group->cache));
                $nestedData['created_at']    = Tool::formatHumanTime($group->created_at);
                $nestedData['status']        = "<div class='form-check form-switch form-check-primary'>
                <input type='checkbox' class='form-check-input get_status' id='status_$group->uid' data-id='$group->uid' name='status' $status>
                <label class='form-check-label' for='status_$group->uid'>
                  <span class='switch-icon-left'><i data-feather='check'></i> </span>
                  <span class='switch-icon-right'><i data-feather='x'></i> </span>
                </label>
              </div>";

                $nestedData['show']              = route('customer.contacts.show', $group->uid);
                $nestedData['show_label']        = __('locale.buttons.edit');
                $nestedData['new_contact']       = route('customer.contact.create', $group->uid);
                $nestedData['new_contact_label'] = __('locale.contacts.new_contact');
                $nestedData['copy']              = __('locale.buttons.copy');
                $nestedData['delete']            = __('locale.buttons.delete');
                $nestedData['can_create']        = Auth::user()->can('create_contact');
                $nestedData['can_update']        = Auth::user()->can('update_contact_group');
                $nestedData['can_delete']        = Auth::user()->can('delete_contact_group');

                $data[] = $nestedData;

            }
        }

        $json_data = [
                "draw"            => intval($request->input('draw')),
                "recordsTotal"    => $totalData,
                "recordsFiltered" => $totalFiltered,
                "data"            => $data,
        ];

        echo json_encode($json_data);
        exit();

    }


    /**
     * create new contact group
     *
     * @return Application|Factory|View|RedirectResponse
     * @throws AuthorizationException
     */

    public function create(): View|Factory|RedirectResponse|Application
    {
        if ( ! Auth::user()->customer->activeSubscription()) {
            return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
            ]);
        }

        $this->authorize('create_contact_group');
        $totalData = ContactGroups::where('customer_id', auth()->user()->id)->count();
        $list_max  = Auth::user()->customer->getOption('list_max');

        if ($list_max != '-1' && $list_max < $totalData) {
            return redirect()->route('customer.contacts.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.contacts.max_list_quota', ['max_list' => $list_max]),
            ]);
        }

        $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('contacts'), 'name' => __('locale.menu.Contacts')],
                ['name' => __('locale.contacts.new_contact_group')],
        ];

        return view('customer.contactGroups.create', compact('breadcrumbs'));
    }

    /**
     * store contact group
     *
     * @param  NewContactGroup  $request
     *
     * @return RedirectResponse
     */

    public function store(NewContactGroup $request): RedirectResponse
    {

        if (config('app.stage') == 'demo') {
            return redirect()->route('customer.contacts.index')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $group = $this->contactGroups->store($request->input());

        return redirect()->route('customer.contacts.show', $group->uid)->with([
                'status'  => 'success',
                'message' => __('locale.contacts.contact_group_successfully_added'),
        ]);
    }


    /**
     * View contact group for edit
     *
     * @param  ContactGroups  $contact
     *
     * @return Application|Factory|View
     *
     * @throws AuthorizationException
     */

    public function show(ContactGroups $contact): View|Factory|Application
    {
        $this->authorize('view_contact_group');

        $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('contacts'), 'name' => __('locale.menu.Contacts')],
                ['name' => $contact->name],
        ];

        if (Auth::user()->customer->getOption('sender_id_verification') == 'yes') {
            $sender_ids    = Senderid::where('user_id', auth()->user()->id)->cursor();
            $phone_numbers = PhoneNumbers::where('user_id', auth()->user()->id)->cursor();
        } else {
            $sender_ids    = null;
            $phone_numbers = null;
        }
        $template_tags          = TemplateTags::cursor();
        $contact_groups         = ContactGroups::where('status', 1)->where('uid', '!=', $contact->uid)->select('uid', 'name')->cursor();
        $opt_in_keywords        = ContactGroupsOptinKeywords::where('contact_group', $contact->id)->cursor();
        $existing_opt_in        = array_column($opt_in_keywords->toArray(), 'keyword');
        $remain_opt_in_keywords = Keywords::where('user_id', $contact->customer_id)->where('status', 'assigned')->whereNotIn('keyword_name', $existing_opt_in)->select('keyword_name')->cursor();

        $opt_out_keywords        = ContactGroupsOptoutKeywords::where('contact_group', $contact->id)->cursor();
        $existing_opt_out        = array_column($opt_out_keywords->toArray(), 'keyword');
        $remain_opt_out_keywords = Keywords::where('user_id', $contact->customer_id)->where('status', 'assigned')->whereNotIn('keyword_name', $existing_opt_out)->select('keyword_name')->cursor();

        $import_history = SystemJob::where('data', $contact->id)->where('name', ImportContacts::class)->cursor();

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

        return view('customer.contactGroups.show', compact('breadcrumbs', 'contact', 'sender_ids', 'phone_numbers', 'contact_groups', 'template_tags', 'opt_in_keywords', 'opt_out_keywords', 'remain_opt_in_keywords', 'remain_opt_out_keywords', 'import_history', 'sending_server'));
    }


    /**
     * change contact group status
     *
     * @param  ContactGroups  $contact
     *
     * @return JsonResponse
     *
     * @throws AuthorizationException
     * @throws GeneralException
     */
    public function activeToggle(ContactGroups $contact): JsonResponse
    {
        if (config('app.stage') == 'demo') {
            return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        try {
            $this->authorize('update_contact_group');

            if ($contact->update(['status' => ! $contact->status])) {
                return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.contacts.contact_group_successfully_change'),
                ]);
            }

            throw new GeneralException(__('locale.exceptions.something_went_wrong'));
        } catch (ModelNotFoundException $exception) {
            return response()->json([
                    'status'  => 'error',
                    'message' => $exception->getMessage(),
            ]);
        }

    }


    /**
     * copy contact groups
     *
     * @param  ContactGroups  $contact
     * @param  Request  $request
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function copy(ContactGroups $contact, Request $request): JsonResponse
    {

        if (config('app.stage') == 'demo') {
            return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $this->authorize('create_contact_group');

        $totalData = ContactGroups::where('customer_id', auth()->user()->id)->count();
        $list_max  = Auth::user()->customer->getOption('list_max');

        if ($list_max != '-1' && $list_max < $totalData) {
            return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.contacts.max_list_quota', ['max_list' => $list_max]),
            ]);
        }

        $subscriber_per_list_max = Contacts::where('group_id', $contact->id)->count();

        if (Auth::user()->customer->getOption('subscriber_per_list_max') != '-1' && $subscriber_per_list_max > Auth::user()->customer->getOption('subscriber_per_list_max')) {
            $subscriber_max = Contacts::where('customer_id', Auth::user()->id)->count();
            if (Auth::user()->customer->getOption('subscriber_max') != '-1' && $subscriber_max > Auth::user()->customer->getOption('subscriber_max')) {
                return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.contacts.subscriber_max_quota', ['subscriber_max' => Auth::user()->customer->getOption('subscriber_max')]),
                ]);
            }

            return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.contacts.subscriber_per_list_max_quota', ['subscriber_per_list_max' => Auth::user()->customer->getOption('subscriber_per_list_max')]),
            ]);
        }

        $new_group       = $contact->replicate();
        $new_group->name = $request->group_name;
        $new_group->save();
        $new_group_id = $new_group->id;

        $opt_in_keyword = ContactGroupsOptinKeywords::where('contact_group', $contact->id)->get();
        foreach ($opt_in_keyword as $keyword) {
            $new_keyword                = $keyword->replicate();
            $new_keyword->uid           = uniqid();
            $new_keyword->contact_group = $new_group_id;
            $new_keyword->save();
        }

        $opt_out_keyword = ContactGroupsOptoutKeywords::where('contact_group', $contact->id)->get();
        foreach ($opt_out_keyword as $keyword) {
            $new_keyword                = $keyword->replicate();
            $new_keyword->uid           = uniqid();
            $new_keyword->contact_group = $new_group_id;
            $new_keyword->save();
        }

        $count = Contacts::where('group_id', $contact->id)->count();

        if ($count > 2000) {

            Tool::resetMaxExecutionTime();
            Contacts::where('group_id', $contact->id)->cursor()
                    ->chunk(5000)
                    ->each(function ($lines) use ($new_group_id, &$batch_list) {
                        $job = new ReplicateContacts($new_group_id, $lines);
                        dispatch($job);
                    });

            return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.contacts.contact_successfully_imported_in_background'),
            ]);

        }

        Contacts::where('group_id', $contact->id)->cursor()->chunk('250')->each(function ($lines) use ($new_group_id) {

            foreach ($lines as $line) {
                $new_contact             = $line->replicate();
                $new_contact->uid        = uniqid();
                $new_contact->group_id   = $new_group_id;
                $new_contact->created_at = now()->toDateTimeString();
                $new_contact->updated_at = now()->toDateTimeString();

                $new_contact->save();
            }
        });

        return response()->json([
                'status'  => 'success',
                'message' => __('locale.contacts.contact_group_successfully_copied'),
        ]);
    }

    /**
     * update contact group settings
     *
     * @param  ContactGroups  $contact
     * @param  UpdateContactGroup  $request
     *
     * @return RedirectResponse
     */

    public function update(ContactGroups $contact, UpdateContactGroup $request): RedirectResponse
    {

        if (config('app.stage') == 'demo') {
            return redirect()->route('customer.contacts.index')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $group = $this->contactGroups->update($contact, $request->except('_method', '_token'));

        return redirect()->route('customer.contacts.show', $group->uid)->withInput(['tab' => 'settings'])->with([
                'status'  => 'success',
                'message' => __('locale.contacts.contact_group_successfully_updated'),
        ]);
    }

    /**
     * delete contact group
     *
     * @param  ContactGroups  $contact
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function destroy(ContactGroups $contact): JsonResponse
    {
        if (config('app.stage') == 'demo') {
            return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $this->authorize('delete_contact_group');

        $this->contactGroups->destroy($contact);

        return response()->json([
                'status'  => 'success',
                'message' => __('locale.contacts.contact_group_successfully_deleted'),
        ]);
    }

    /**
     * Bulk Action with Enable, Disable and Delete
     *
     * @param  Request  $request
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */

    public function batchAction(Request $request): JsonResponse
    {

        if (config('app.stage') == 'demo') {
            return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $action = $request->get('action');
        $ids    = $request->get('ids');

        switch ($action) {
            case 'destroy':
                $this->authorize('delete_contact_group');

                $this->contactGroups->batchDestroy($ids);

                return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.contacts.contact_groups_deleted'),
                ]);

            case 'enable':
                $this->authorize('update_contact_group');

                $this->contactGroups->batchActive($ids);

                return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.contacts.contact_groups_enabled'),
                ]);

            case 'disable':

                $this->authorize('update_contact_group');

                $this->contactGroups->batchDisable($ids);

                return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.contacts.contact_groups_disabled'),
                ]);
        }

        return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.invalid_action'),
        ]);

    }


    /**
     * search contact with given data
     *
     * @param  ContactGroups  $contact
     * @param  Request  $request
     *
     * @throws AuthorizationException
     */

    #[NoReturn] public function searchContact(ContactGroups $contact, Request $request): void
    {

        $this->authorize('view_contact');

        $columns = [
                0 => 'responsive_id',
                1 => 'uid',
                2 => 'uid',
                3 => 'phone',
                4 => 'name',
                5 => 'created_at',
                6 => 'status',
                7 => 'action',
        ];

        $totalData = Contacts::where('customer_id', auth()->user()->id)->where('group_id', $contact->id)->count();

        $totalFiltered = $totalData;

        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir   = $request->input('order.0.dir');

        if (empty($request->input('search.value'))) {
            $contacts = Contacts::where('customer_id', auth()->user()->id)->where('group_id', $contact->id)->offset($start)
                                ->limit($limit)
                                ->orderBy($order, $dir)
                                ->get();
        } else {
            $search = $request->input('search.value');

            $contacts = Contacts::where('customer_id', auth()->user()->id)->where('group_id', $contact->id)->whereLike(['uid', 'phone', 'first_name', 'last_name', 'status'], $search)
                                ->offset($start)
                                ->limit($limit)
                                ->orderBy($order, $dir)
                                ->get();

            $totalFiltered = Contacts::where('customer_id', auth()->user()->id)->where('group_id', $contact->id)->whereLike(['uid', 'phone', 'first_name', 'last_name', 'status'], $search)->count();
        }

        $data = [];
        if ( ! empty($contacts)) {
            foreach ($contacts as $singleContact) {

                if ($singleContact->status == 'subscribe') {
                    $status = 'checked';
                } else {
                    $status = '';
                }


                $display_name = $singleContact->display_name();
                if ($display_name == ' ') {
                    $display_name = __('locale.labels.no_name');
                }

                $nestedData['responsive_id'] = '';
                $nestedData['uid']           = $singleContact->uid;
                $nestedData['phone']         = $singleContact->phone;
                $nestedData['name']          = $display_name;
                $nestedData['created_at']    = Tool::formatHumanTime($singleContact->created_at);
                $nestedData['status']        = "<div class='form-check form-switch form-check-primary form-switch-xl'>
                <input type='checkbox' class='form-check-input get_status' id='status_$singleContact->uid' data-id='$singleContact->uid' name='status' $status>
                <label class='form-check-label' for='status_$singleContact->uid'>
                  <span class='switch-text-left'>".__('locale.labels.subscribe')."</span>
                  <span class='switch-text-right'>".__('locale.labels.unsubscribe')."</span>
                </label>
              </div>";


                $nestedData['show']             = route('customer.contact.edit', ['contact' => $contact->uid, 'contact_id' => $singleContact->uid]);
                $nestedData['show_label']       = __('locale.buttons.edit');
                $nestedData['conversion']       = route('customer.reports.all', ['recipient' => $singleContact->phone]);
                $nestedData['conversion_label'] = __('locale.contacts.view_conversion');
                $nestedData['send_sms']         = route('customer.sms.quick_send', ['recipient' => $singleContact->phone]);
                $nestedData['send_sms_label']   = __('locale.contacts.send_message');
                $nestedData['delete']           = __('locale.buttons.delete');
                $data[]                         = $nestedData;

            }
        }

        $json_data = [
                "draw"            => intval($request->input('draw')),
                "recordsTotal"    => $totalData,
                "recordsFiltered" => $totalFiltered,
                "data"            => $data,
        ];

        echo json_encode($json_data);
        exit();

    }

    /**
     * add new contact in a group
     *
     * @param  ContactGroups  $contact
     *
     * @return Application|Factory|View|RedirectResponse
     * @throws AuthorizationException
     */
    public function createContact(ContactGroups $contact): View|Factory|RedirectResponse|Application
    {
        $this->authorize('create_contact');

        $subscriber_per_list_max = Contacts::where('group_id', $contact->id)->count();

        if (Auth::user()->customer->getOption('subscriber_per_list_max') != '-1' && $subscriber_per_list_max > Auth::user()->customer->getOption('subscriber_per_list_max')) {
            $subscriber_max = Contacts::where('customer_id', Auth::user()->id)->count();
            if (Auth::user()->customer->getOption('subscriber_max') != '-1' && $subscriber_max > Auth::user()->customer->getOption('subscriber_max')) {
                return redirect()->route('customer.contacts.show', $contact->uid)->with([
                        'status'  => 'error',
                        'message' => __('locale.contacts.subscriber_max_quota', ['subscriber_max' => Auth::user()->customer->getOption('subscriber_max')]),
                ]);
            }

            return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'contact'])->with([
                    'status'  => 'error',
                    'message' => __('locale.contacts.subscriber_per_list_max_quota', ['subscriber_per_list_max' => Auth::user()->customer->getOption('subscriber_per_list_max')]),
            ]);
        }

        $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => route('customer.contacts.show', $contact->uid), 'name' => $contact->name],
                ['name' => __('locale.contacts.new_contact')],
        ];

        return view('customer.Contacts.create', compact('breadcrumbs', 'contact'));
    }

    /**
     * store new contact
     *
     * @param  ContactGroups  $contact
     * @param  StoreContact  $request
     *
     * @return RedirectResponse
     */
    public function storeContact(ContactGroups $contact, StoreContact $request): RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'contact'])->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $phone = str_replace(['(', ')', '+', '-', ' '], '', $request->phone);

        $exist = Contacts::where('group_id', $contact->id)->where('customer_id', Auth::user()->id)->where('phone', $phone)->first();
        if ($exist) {
            return redirect()->route('customer.contact.create', $contact->uid)->withInput(['tab' => 'contact', 'phone' => $request->phone])->with([
                    'status'  => 'error',
                    'message' => __('locale.contacts.you_have_already_subscribed', ['contact_group' => $contact->name]),
            ]);
        }

        $blacklist = Blacklists::where('user_id', Auth::user()->id)->where('number', $phone)->first();
        if ($blacklist) {
            return redirect()->route('customer.contact.create', $contact->uid)->withInput(['tab' => 'contact', 'phone' => $phone])->with([
                    'status'  => 'error',
                    'message' => __('locale.blacklist.phone_was_blacklisted'),
            ]);
        }

        $this->contactGroups->storeContact($contact, $request->only('phone', 'first_name', 'last_name'));

        return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'contact'])->with([
                'status'  => 'success',
                'message' => __('locale.contacts.contact_successfully_added'),
        ]);
    }

    /**
     * update contact status
     *
     * @param  ContactGroups  $contact
     * @param  Request  $request
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function updateContactStatus(ContactGroups $contact, Request $request): JsonResponse
    {

        if (config('app.stage') == 'demo') {
            return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }


        $this->authorize('update_contact');

        $check_contact = Contacts::where('group_id', $contact->id)->where('uid', $request->id)->first();
        $blacklist     = Blacklists::where('user_id', Auth::user()->id)->where('number', $check_contact->phone)->first();

        if ($blacklist && $check_contact->status == 'unsubscribe') {
            return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.blacklist.phone_was_blacklisted'),
            ]);
        }

        $status = $this->contactGroups->updateContactStatus($contact, $request->only('id'));
        if ($status) {
            return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.contacts.contact_successfully_change'),
            ]);
        }

        return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
        ]);
    }

    /**
     * delete single contact from group
     *
     * @param  ContactGroups  $contact
     * @param  Request  $request
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function deleteContact(ContactGroups $contact, Request $request): JsonResponse
    {

        if (config('app.stage') == 'demo') {
            return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $this->authorize('delete_contact');

        $status = $this->contactGroups->contactDestroy($contact, $request->id);

        if ($status) {
            return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.contacts.contact_successfully_deleted'),
            ]);
        }

        return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
        ]);

    }

    /**
     * view single contact for edit
     *
     * @param  ContactGroups  $contact
     * @param  Request  $request
     *
     * @return Application|Factory|View|RedirectResponse
     * @throws AuthorizationException
     */
    public function editContact(ContactGroups $contact, Request $request): View|Factory|RedirectResponse|Application
    {

        $this->authorize('update_contact');

        $data = Contacts::where('group_id', $contact->id)->where('customer_id', Auth::user()->id)->where('uid', $request->contact_id)->first();
        if ($data) {

            $breadcrumbs = [
                    ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                    ['link' => route('customer.contacts.show', $contact->uid), 'name' => $contact->name],
                    ['name' => __('locale.contacts.update_contact')],
            ];

            $template_tags = null;
            $custom_fields = ContactsCustomField::where('contact_id', $data->id)->cursor();
            if ($custom_fields->count() == 0) {
                $custom_fields = null;
                $template_tags = TemplateTags::cursor();
            }

            return view('customer.Contacts.show', compact('breadcrumbs', 'contact', 'data', 'custom_fields', 'template_tags'));
        }

        return redirect()->route('customer.contacts.show', $contact->uid)->with([
                'status'  => 'error',
                'message' => __('locale.contacts.contact_not_found'),
        ]);
    }

    /**
     * update single contact information
     *
     * @param  ContactGroups  $contact
     * @param  UpdateContact  $request
     *
     * @return RedirectResponse
     */
    public function updateContact(ContactGroups $contact, UpdateContact $request): RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('customer.contacts.show', $contact->uid)->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $status = $this->contactGroups->updateContact($contact, $request->except('_token'));
        if ($status) {
            return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'contact'])->with([
                    'status'  => 'success',
                    'message' => __('locale.contacts.contact_successfully_updated'),
            ]);
        }

        return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'contact'])->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
        ]);
    }

    /**
     * import contact using csv or excel
     *
     * @param  ContactGroups  $contact
     *
     * @return Application|Factory|View
     * @throws AuthorizationException
     */
    public function importContact(ContactGroups $contact): View|Factory|Application
    {

        $this->authorize('view_contact_group');

        $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('contacts'), 'name' => __('locale.menu.Contacts')],
                ['name' => $contact->name],
        ];

        return view('customer.Contacts.import', compact('breadcrumbs', 'contact'));
    }

    /**
     * working with import contacts
     *
     * @param  ContactGroups  $contact
     * @param  ImportContact  $request
     *
     * @return Application|Factory|View|RedirectResponse
     */
    public function storeImportContact(ContactGroups $contact, ImportContact $request): View|Factory|RedirectResponse|Application
    {
        if (isset($request->option_toggle) && $request->option_toggle == 'on' && isset($request->import_file) && $request->hasFile('import_file')) {
            if ($request->file('import_file')->isValid()) {

                $breadcrumbs = [
                        ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                        ['link' => url('contacts'), 'name' => __('locale.menu.Contacts')],
                        ['name' => $contact->name],
                ];

                $data = Excel::toArray(new stdClass(), $request->file('import_file'))[0];

                $csv_data_file = CsvData::create([
                        'user_id'      => Auth::user()->id,
                        'ref_id'       => $contact->uid,
                        'ref_type'     => CsvData::TYPE_CONTACT,
                        'csv_filename' => $request->file('import_file')->getClientOriginalName(),
                        'csv_header'   => $request->has('header'),
                        'csv_data'     => json_encode($data),
                ]);

                $csv_data = array_slice($data, 0, 2);

                return view('customer.Contacts.import_fields', compact('csv_data', 'contact', 'csv_data_file', 'breadcrumbs'));
            }
        } elseif (isset($request->recipients) && $request->recipients != null) {

            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'contact'])->with([
                        'status'  => 'error',
                        'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if ($request->delimiter == ';') {
                $recipients = explode(';', $request->recipients);
            } elseif ($request->delimiter == ',') {
                $recipients = explode(',', $request->recipients);
            } elseif ($request->delimiter == '|') {
                $recipients = explode('|', $request->recipients);
            } elseif ($request->delimiter == 'tab') {
                $recipients = explode(' ', $request->recipients);
            } elseif ($request->delimiter == 'new_line') {
                $recipients = explode("\n", $request->recipients);
            } else {
                return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'contact'])->with([
                        'status'  => 'error',
                        'message' => __('locale.labels.invalid_delimiter'),
                ]);
            }

            if (isset($recipients) && is_array($recipients) && count($recipients) > 1000) {
                return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'contact'])->with([
                        'status'  => 'error',
                        'message' => __('locale.contacts.upload_maximum_1000_rows'),
                ]);
            }

            collect($recipients)->unique()->chunk('250')->each(function ($lines) use ($contact) {
                $list = [];


                $phone_numbers = Contacts::where('group_id', $contact->id)->where('customer_id', Auth::user()->id)->pluck('phone')->toArray();
                $blacklists    = Blacklists::where('user_id', Auth::user()->id)->pluck('number')->toArray();

                foreach ($lines as $line) {

                    $phone = str_replace(['(', ')', '+', '-', ' '], '', $line);

                    if ( ! in_array($phone, $phone_numbers) && ! in_array($phone, $blacklists)) {
                        $list[] = [
                                'uid'         => uniqid(),
                                'customer_id' => Auth::user()->id,
                                'group_id'    => $contact->id,
                                'status'      => 'subscribe',
                                'phone'       => $phone,
                                'created_at'  => now()->toDateTimeString(),
                                'updated_at'  => now()->toDateTimeString(),
                        ];
                    }
                }

                Contacts::insert($list);
            });

            $contact->updateCache('SubscribersCount');

            return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'contact'])->with([
                    'status'  => 'success',
                    'message' => __('locale.contacts.contact_successfully_imported'),
            ]);

        } else {
            return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'contact'])->with([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.invalid_action'),
            ]);
        }

        return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'contact'])->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
        ]);
    }


    /**
     * import process data
     *
     * @param  ContactGroups  $contact
     * @param  Request  $request
     *
     * @return RedirectResponse
     */
    public function importProcessData(ContactGroups $contact, Request $request): RedirectResponse
    {

        if (config('app.stage') == 'demo') {
            return redirect()->route('customer.contacts.show', $contact->uid)->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $data      = CsvData::find($request->csv_data_file_id);
        $csv_data  = json_decode($data->csv_data, true);
        $db_fields = $request->fields;

        if (is_array($db_fields) && ! in_array('phone', $db_fields)) {
            return redirect()->route('customer.contacts.show', $contact->uid)->with([
                    'status'  => 'error',
                    'message' => __('locale.filezone.phone_number_column_require'),
            ]);
        }

        $collection = collect($csv_data)->skip($data->csv_header)->unique(array_keys($db_fields, 'phone'));

        Tool::resetMaxExecutionTime();
        $collection->chunk(5000)
                   ->each(function ($lines) use ($contact, &$batch_list, $db_fields) {
                       $job = new ImportContacts(Auth::user()->id, $contact->id, $lines, $db_fields);
                       dispatch($job);
                   });

        $data->delete();

        return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'import_history'])->with([
                'status'  => 'success',
                'message' => __('locale.contacts.contact_successfully_imported_in_background'),
        ]);
    }

    /**
     * Bulk Action with subscribe, unsubscribe and Delete contacts
     *
     * @param  ContactGroups  $contact
     * @param  Request  $request
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */

    public function batchActionContact(ContactGroups $contact, Request $request): JsonResponse
    {

        if (config('app.stage') == 'demo') {
            return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $action = $request->get('action');
        $ids    = $request->get('ids');

        switch ($action) {
            case 'destroy':

                $this->authorize('delete_contact');

                $this->contactGroups->batchContactDestroy($contact, $ids);

                return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.contacts.contacts_deleted'),
                ]);

            case 'subscribe':

                $this->authorize('update_contact');

                $this->contactGroups->batchContactSubscribe($contact, $ids);

                return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.contacts.contacts_subscribe'),
                ]);

            case 'unsubscribe':

                $this->authorize('update_contact');

                $this->contactGroups->batchContactUnsubscribe($contact, $ids);

                return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.contacts.contacts_unsubscribe'),
                ]);

            case 'copy':

                $this->authorize('update_contact');

                $target_group = $request->get('target_group');
                $group        = ContactGroups::where('uid', $target_group)->first();

                if ( ! $group) {
                    return response()->json([
                            'status'  => 'error',
                            'message' => __('locale.contacts.contact_group_not_found'),
                    ]);
                }

                $input = [
                        'ids'          => $ids,
                        'target_group' => $group->id,
                ];

                $this->contactGroups->batchContactCopy($contact, $input);

                return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.contacts.contacts_copy'),
                ]);

            case 'move':

                $this->authorize('update_contact');

                $target_group = $request->get('target_group');
                $group        = ContactGroups::where('uid', $target_group)->first();

                if ( ! $group) {
                    return response()->json([
                            'status'  => 'error',
                            'message' => __('locale.contacts.contact_group_not_found'),
                    ]);
                }

                $input = [
                        'ids'          => $ids,
                        'target_group' => $group->id,
                ];

                $this->contactGroups->batchContactMove($contact, $input);

                return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.contacts.contacts_moved'),
                ]);
        }

        return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.invalid_action'),
        ]);

    }


    /**
     * @param $group_id
     *
     * @return Generator
     */

    public function contactsGenerator($group_id): Generator
    {
        foreach (Contacts::where('group_id', $group_id)->cursor() as $contact) {
            yield $contact;
        }
    }


    /**
     * @param  ContactGroups  $contact
     *
     * @return RedirectResponse|BinaryFileResponse
     * @throws AuthorizationException
     * @throws IOException
     * @throws InvalidArgumentException
     * @throws UnsupportedTypeException
     * @throws WriterNotOpenedException
     */
    public function exportContact(ContactGroups $contact): BinaryFileResponse|RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('customer.contacts.show', $contact->uid)->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }


        $this->authorize('view_contact');

        $file_name = (new FastExcel($this->contactsGenerator($contact->id)))->export(storage_path('Contacts_'.time().'.xlsx'));

        return response()->download($file_name);
    }


    /**
     * @param  ContactGroups  $contact
     *
     * @return Application|Factory|View
     */
    public function subscribeURL(ContactGroups $contact): View|Factory|Application
    {
        $pageConfigs = [
                'bodyClass' => "bg-full-screen-image",
                'blankPage' => true,
        ];

        $user     = User::find($contact->customer_id);
        $coverage = null;
        if ($user) {
            $plan_id  = $user->customer->activeSubscription()->plan_id;
            $coverage = PlansCoverageCountries::where('plan_id', $plan_id)->where('status', true)->cursor();

            return view('customer.Contacts.subscribe_form', compact('contact', 'pageConfigs', 'coverage'));
        }


        return view('customer.Contacts.subscribe_form', compact('contact', 'pageConfigs', 'coverage'));
    }

    /**
     * insert contact by subscription form
     *
     * @param  ContactGroups  $contact
     * @param  Request  $request
     *
     * @return RedirectResponse
     */
    public function insertContactBySubscriptionForm(ContactGroups $contact, Request $request): RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('customer.contacts.show', $contact->uid)->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $rules = [
                'phone' => ['required', new Phone($request->phone)],
        ];

        if (config('no-captcha.registration')) {
            $rules['g-recaptcha-response'] = ['required', new CaptchaRule()];
        }

        $messages = [
                'g-recaptcha-response.required' => __('locale.auth.recaptcha_required'),
                'g-recaptcha-response.captcha'  => __('locale.auth.recaptcha_required'),
        ];

        $validation = Validator::make($request->all(), $rules, $messages);

        if ($validation->fails()) {
            return redirect()->route('contacts.subscribe_url', $contact->uid)->withInput($request->all())->withErrors($validation->errors());
        }

        if ($request->country_code) {
            $phone = $request->country_code.$request->phone;
        } else {
            $phone = $request->phone;
        }

        $phone = str_replace(['(', ')', '+', '-', ' '], '', $phone);

        try {
            $phoneUtil         = PhoneNumberUtil::getInstance();
            $phoneNumberObject = $phoneUtil->parse('+'.$phone);
            if ( ! $phoneUtil->isPossibleNumber($phoneNumberObject)) {
                return redirect()->route('contacts.subscribe_url', $contact->uid)->with([
                        'status'  => 'error',
                        'message' => __('locale.customer.invalid_phone_number'),
                ]);
            }
            if ($phoneNumberObject->isItalianLeadingZero()) {
                $phone = $phoneNumberObject->getCountryCode().'0'.$phoneNumberObject->getNationalNumber();
            } else {
                $phone = $phoneNumberObject->getCountryCode().$phoneNumberObject->getNationalNumber();
            }

        } catch (NumberParseException $e) {
            return redirect()->route('contacts.subscribe_url', $contact->uid)->with([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
            ]);
        }

        $exist = Contacts::where('group_id', $contact->id)->where('phone', $phone)->first();

        if ($exist) {
            return redirect()->route('contacts.subscribe_url', $contact->uid)->with([
                    'status'  => 'error',
                    'message' => __('locale.contacts.you_have_already_subscribed', ['contact_group' => $contact->name]),
            ]);
        }

        $blacklist = Blacklists::where('number', $phone)->first();
        if ($blacklist) {
            return redirect()->route('contacts.subscribe_url', $contact->uid)->with([
                    'status'  => 'error',
                    'message' => 'This phone was blacklisted',
            ]);
        }

        $this->contactGroups->storeContact($contact, ['phone' => $phone, 'first_name' => $request->first_name, 'last_name' => $request->last_name]);

        return redirect()->route('contacts.subscribe_url', $contact->uid)->with([
                'status'  => 'success',
                'message' => __('locale.contacts.you_have_successfully_subscribe', ['contact_group' => $contact->name]),
        ]);

    }

    /**
     * return sms form data
     *
     * @param  ContactGroups  $contact
     * @param  Request  $request
     *
     * @return JsonResponse
     */
    public function getMessageForm(ContactGroups $contact, Request $request): JsonResponse
    {
        return response()->json([
                'status'  => 'success',
                'message' => $contact->{$request->sms_form},
        ]);
    }

    /**
     * update contact groups message
     *
     * @param  ContactGroups  $contact
     * @param  UpdateContactGroupMessage  $request
     *
     * @return RedirectResponse
     */
    public function message(ContactGroups $contact, UpdateContactGroupMessage $request): RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('customer.contacts.show', $contact->uid)->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $contact->{$request->message_form} = $request->message;
        $contact->save();

        return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'message'])->with([
                'status'  => 'success',
                'message' => __('locale.contacts.contact_groups_message_information', ['message_from' => ucfirst(str_replace('_', ' ', $request->message_form))]),
        ]);
    }

    /**
     * add opt in keyword
     *
     * @param  ContactGroups  $contact
     * @param  Request  $request
     *
     * @return JsonResponse
     */
    public function optInKeyword(ContactGroups $contact, Request $request): JsonResponse
    {

        if (config('app.stage') == 'demo') {
            return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }
        $keyword_name = $request->keyword_name;
        if ($keyword_name) {
            $keyword = Keywords::where('user_id', Auth::user()->id)->where('keyword_name', $keyword_name)->first();
            if ($keyword) {
                $status = ContactGroupsOptinKeywords::create([
                        'contact_group' => $contact->id,
                        'keyword'       => $keyword_name,
                ]);

                if ($status) {
                    return response()->json([
                            'status'  => 'success',
                            'message' => __('locale.contacts.optin_keyword_successfully_added'),
                    ]);
                }

                return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.something_went_wrong'),
                ]);

            }

            return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.contacts.keyword_info_not_found'),
            ]);
        }

        return response()->json([
                'status'  => 'error',
                'message' => __('locale.labels.at_least_one_data'),
        ]);
    }

    /**
     * add opt in keyword
     *
     * @param  ContactGroups  $contact
     * @param  Request  $request
     *
     * @return JsonResponse
     */
    public function optOutKeyword(ContactGroups $contact, Request $request): JsonResponse
    {

        if (config('app.stage') == 'demo') {
            return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $keyword_name = $request->keyword_name;
        if ($keyword_name) {
            $keyword = Keywords::where('user_id', Auth::user()->id)->where('keyword_name', $keyword_name)->first();
            if ($keyword) {

                $status = ContactGroupsOptoutKeywords::create([
                        'contact_group' => $contact->id,
                        'keyword'       => $keyword_name,
                ]);

                if ($status) {
                    return response()->json([
                            'status'  => 'success',
                            'message' => __('locale.contacts.optout_keyword_successfully_added'),
                    ]);
                }

                return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.something_went_wrong'),
                ]);

            }

            return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.contacts.keyword_info_not_found'),
            ]);
        }

        return response()->json([
                'status'  => 'error',
                'message' => __('locale.labels.at_least_one_data'),
        ]);
    }


    /**
     * delete opt in keyword
     *
     * @param  ContactGroups  $contact
     * @param  Request  $request
     *
     * @return JsonResponse
     */
    public function deleteOptInKeyword(ContactGroups $contact, Request $request): JsonResponse
    {

        if (config('app.stage') == 'demo') {
            return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }
        $keyword_id    = $request->id;
        $optin_keyword = ContactGroupsOptinKeywords::where('contact_group', $contact->id)->where('uid', $keyword_id)->delete();
        if ($optin_keyword) {
            return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.contacts.optin_keyword_successfully_deleted'),
            ]);
        }

        return response()->json([
                'status'  => 'error',
                'message' => __('locale.contacts.keyword_info_not_found'),
        ]);
    }

    /**
     * delete opt out keyword
     *
     * @param  ContactGroups  $contact
     * @param  Request  $request
     *
     * @return JsonResponse
     */
    public function deleteOptOutKeyword(ContactGroups $contact, Request $request): JsonResponse
    {

        if (config('app.stage') == 'demo') {
            return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $keyword_id     = $request->id;
        $optout_keyword = ContactGroupsOptoutKeywords::where('contact_group', $contact->id)->where('uid', $keyword_id)->delete();
        if ($optout_keyword) {
            return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.contacts.optout_keyword_successfully_deleted'),
            ]);
        }

        return response()->json([
                'status'  => 'error',
                'message' => __('locale.contacts.keyword_info_not_found'),
        ]);
    }

    /**
     * @return Generator
     */

    public function contactGroupsGenerator(): Generator
    {
        foreach (ContactGroups::where('customer_id', Auth::user()->id)->cursor() as $contactGroup) {
            yield $contactGroup;
        }
    }

    /**
     * @return RedirectResponse|BinaryFileResponse
     * @throws AuthorizationException
     * @throws IOException
     * @throws InvalidArgumentException
     * @throws UnsupportedTypeException
     * @throws WriterNotOpenedException
     */
    public function export(): BinaryFileResponse|RedirectResponse
    {

        if (config('app.stage') == 'demo') {
            return redirect()->route('customer.contacts.index')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $this->authorize('view_contact');

        $file_name = (new FastExcel($this->contactGroupsGenerator()))->export(storage_path('ContactGroups_'.time().'.xlsx'));

        return response()->download($file_name);
    }

}
