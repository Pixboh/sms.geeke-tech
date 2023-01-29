<?php

namespace App\Http\Controllers\User;

use App\Exceptions\GeneralException;
use App\Helpers\Helper;
use App\Http\Requests\Account\PayPayment;
use App\Http\Requests\Accounts\ChangePasswordRequest;
use App\Http\Requests\Accounts\UpdateUserInformationRequest;
use App\Http\Requests\Accounts\UpdateUserRequest;
use App\Http\Requests\Customer\AddUnitRequest;
use App\Models\Invoices;
use App\Models\Language;
use App\Models\Notifications;
use App\Models\PaymentMethods;
use App\Models\Plan;
use App\Models\Senderid;
use App\Models\Subscription;
use App\Models\SubscriptionLog;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Notifications\TwoFactorCode;
use App\Notifications\WelcomeEmailNotification;
use Auth;
use Braintree\Gateway;
use Carbon\Carbon;
use Exception;
use Hash;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Repositories\Contracts\AccountRepository;
use Illuminate\Routing\Redirector;
use Intervention\Image\Exception\NotReadableException;
use Intervention\Image\Facades\Image;
use JetBrains\PhpStorm\NoReturn;
use net\authorize\api\constants\ANetEnvironment;
use Paymentsds\MPesa\Client;
use Paymentsds\MPesa\Environment;
use Paynow\Payments\Paynow;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;
use RuntimeException;
use SimpleXMLElement;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Session;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

class AccountController extends Controller
{
    /**
     * @var AccountRepository
     */
    protected AccountRepository $account;


    /**
     * RegisterController constructor.
     *
     * @param  AccountRepository  $account
     */
    public function __construct(AccountRepository $account)
    {
        $this->account = $account;
    }


    /**
     * show profile page
     *
     * @return Application|Factory|View
     */
    public function index(): View|Factory|Application
    {
        $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['name' => Auth::user()->displayName()],
        ];

        $languages = Language::where('status', 1)->get();

        $user = Auth::user();

        return view('auth.profile.index', compact('breadcrumbs', 'languages', 'user'));
    }

    /**
     * get avatar
     *
     * @return mixed
     */
    public function avatar(): mixed
    {
        if ( ! empty(Auth::user()->imagePath())) {

            try {
                $image = Image::make(Auth::user()->imagePath());
            } catch (NotReadableException) {
                Auth::user()->image = null;
                Auth::user()->save();

                $image = Image::make(public_path('images/profile/profile.jpg'));
            }
        } else {
            $image = Image::make(public_path('images/profile/profile.jpg'));
        }

        return $image->response();
    }

    /**
     * update avatar
     *
     * @param  Request  $request
     *
     * @return RedirectResponse
     */
    public function updateAvatar(Request $request): RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('user.account')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $user = Auth::user();

        try {
            // Upload and save image
            if ($request->hasFile('image') && $request->file('image')->isValid()) {
                // Remove old images
                $user->removeImage();
                $user->image = $user->uploadImage($request->file('image'));
                $user->save();

                return redirect()->route('user.account')->with([
                        'status'  => 'success',
                        'message' => __('locale.customer.avatar_update_successful'),
                ]);
            }

            return redirect()->route('user.account')->with([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.invalid_image'),
            ]);

        } catch (Exception $exception) {
            return redirect()->route('user.account')->with([
                    'status'  => 'error',
                    'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * remove avatar
     *
     * @return JsonResponse
     */
    public function removeAvatar(): JsonResponse
    {

        if (config('app.stage') == 'demo') {
            return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }


        $user = Auth::user();
        // Remove old images
        $user->removeImage();
        $user->image = null;
        $user->save();

        return response()->json([
                'status'  => 'success',
                'message' => __('locale.customer.avatar_remove_successful'),
        ]);
    }

    /**
     * switch view
     *
     * @param  Request  $request
     *
     * @return RedirectResponse
     */
    public function switchView(Request $request): RedirectResponse
    {
        if (config('app.stage') == 'demo') {

            return redirect()->route(Helper::home_route())->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);

        }

        $user = Auth::user();

        switch ($request->portal) {
            case 'customer':
                if ($user->is_customer == 0) {
                    return redirect()->route(Helper::home_route())->with([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.invalid_action'),
                    ]);
                }

                $user->last_access_at = Carbon::now();

                $user->active_portal = 'customer';
                $user->save();

                $permissions = collect(json_decode($user->customer->permissions, true));
                session(['permissions' => $permissions]);

                return redirect()->route('user.home')->with([
                        'status'  => 'success',
                        'message' => __('locale.auth.welcome_come_back', ['name' => $user->displayName()]),
                ]);

            case 'admin':
                if ($user->is_admin == 0) {
                    return redirect()->route(Helper::home_route())->with([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.invalid_action'),
                    ]);
                }

                $user->last_access_at = Carbon::now();

                $user->active_portal = 'admin';

                $user->save();

                session(['permissions' => $user->getPermissions()]);

                return redirect()->route('admin.home')->with([
                        'status'  => 'success',
                        'message' => __('locale.auth.welcome_come_back', ['name' => $user->displayName()]),
                ]);

            default:
                return redirect()->route(Helper::home_route())->with([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.invalid_action'),
                ]);
        }
    }

    /**
     * profile update
     *
     * @param  UpdateUserRequest  $request
     *
     * @return RedirectResponse
     */
    public function update(UpdateUserRequest $request): RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('user.account')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);

        }

        $input = $request->all();

        $data = $this->account->update($input);

        if (isset($data->getData()->status)) {
            return redirect()->route('user.account')->withInput(['tab' => 'account'])->with([
                    'status'  => $data->getData()->status,
                    'message' => $data->getData()->message,
            ]);
        }

        return redirect()->route('user.account')->withInput(['tab' => 'account'])->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
        ]);

    }


    /**
     * changed password
     *
     * @param  ChangePasswordRequest  $request
     *
     * @return Redirector|Application|RedirectResponse
     */
    public function changePassword(ChangePasswordRequest $request): Redirector|Application|RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('user.account')->withInput(['tab' => 'security'])->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);

        }

        Auth::user()->update([
                'password' => Hash::make($request->password),
        ]);

        Auth::logout();

        $request->session()->invalidate();

        return redirect('/login')->with([
                'status'  => 'success',
                'message' => 'Password was successfully changed',
        ]);

    }

    /**
     *
     *
     * @param $status
     *
     * @return Factory|View|Application|RedirectResponse
     */
    public function twoFactorAuthentication($status): Factory|View|Application|RedirectResponse
    {

        if (config('app.stage') == 'demo') {
            return redirect()->route('user.account')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);

        }

        $user = Auth::user();

        if ($status == 'disabled') {
            $user->update([
                    'two_factor' => false,
            ]);
        }

        if ($user->two_factor_code == null && $user->two_factor_expires_at == null) {
            $user->generateTwoFactorCode();
            $user->notify(new TwoFactorCode(route('user.account.twofactor.auth', ['status' => $status])));
        }

        return view('auth.profile._update_two_factor_auth', compact('status'));

    }

    /**
     * update two-factor auth
     *
     * @param $status
     * @param  Request  $request
     *
     * @return RedirectResponse
     */
    public function updateTwoFactorAuthentication($status, Request $request): RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('user.account')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);

        }

        $request->validate([
                'two_factor_code' => 'integer|required|min:6',
        ]);

        $user = Auth::user();

        if ($request->input('two_factor_code') == $user->two_factor_code) {
            $user->resetTwoFactorCode();
            if ($status == 'enable') {
                $backup_codes = $user->generateTwoFactorBackUpCode();
                $user->update([
                        'two_factor'             => true,
                        'two_factor_backup_code' => $backup_codes,
                ]);

                return redirect()->route('user.account')->withInput(['tab' => 'two_factor'])->with([
                        'status'      => 'success',
                        'backup_code' => $backup_codes,
                        'message'     => 'Two-Factor Authentication was successfully enabled',
                ]);
            }

            $user->update([
                    'two_factor' => false,
            ]);

            return redirect()->route('user.account')->withInput(['tab' => 'two_factor'])->with([
                    'status'  => 'success',
                    'message' => 'Two-Factor Authentication was successfully disabled',
            ]);
        }

        return redirect()->back()->with([
                'status'  => 'error',
                'message' => __('locale.auth.two_factor_code_not_matched'),
        ]);
    }

    /**
     * @return RedirectResponse
     */
    public function generateTwoFactorAuthenticationCode(): RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('user.account')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);

        }

        $user = Auth::user();

        $backup_codes = $user->generateTwoFactorBackUpCode();
        $user->update([
                'two_factor_backup_code' => $backup_codes,
        ]);

        return redirect()->back()->with([
                'status'      => 'success',
                'backup_code' => $backup_codes,
                'message'     => 'Backup codes successfully generated',
        ]);
    }

    /**
     * update information
     *
     * @param  UpdateUserInformationRequest  $request
     *
     * @return RedirectResponse
     */
    public function updateInformation(UpdateUserInformationRequest $request): RedirectResponse
    {

        if (config('app.stage') == 'demo') {
            return redirect()->route('user.account')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);

        }

        $input = $request->except('_token');

        $customer = Auth::user()->customer;

        $defaultNotifications = [
                'login'        => 'no',
                'sender_id'    => 'no',
                'keyword'      => 'no',
                'subscription' => 'no',
                'promotion'    => 'no',
                'profile'      => 'no',
        ];

        if (isset($input['notifications']) && count($input['notifications']) > 0) {
            $notifications          = array_merge($defaultNotifications, $input['notifications']);
            $input['notifications'] = json_encode($notifications);
        } else {
            $input['notifications'] = json_encode($defaultNotifications);
        }

        $data = $customer->update($input);

        if ($data) {
            return redirect()->route('user.account')->withInput(['tab' => 'information'])->with([
                    'status'  => 'success',
                    'message' => __('locale.customer.profile_was_successfully_updated'),
            ]);
        }

        return redirect()->route('user.account')->withInput(['tab' => 'information'])->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
        ]);
    }

    /**
     * @param  Request  $request
     *
     * @return mixed
     * @throws RuntimeException
     *
     */
    public function delete(Request $request): mixed
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('user.account')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);

        }

        if (config('app.stage') == 'demo') {
            return redirect()->route('user.account')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);

        }

        $this->account->delete();


        auth()->logout();
        $request->session()->flush();
        $request->session()->regenerate();

        return redirect()->route('user.home');
    }

    /**
     * @param  Request  $request
     *
     * @return void
     */
    #[NoReturn] public function notifications(Request $request): void
    {

        $columns = [
                0 => 'responsive_id',
                1 => 'uid',
                2 => 'uid',
                3 => 'notification_type',
                4 => 'message',
                5 => 'mark_read',
                6 => 'action',
        ];

        $totalData = Notifications::where('user_id', Auth::user()->id)->count();

        $totalFiltered = $totalData;

        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir   = $request->input('order.0.dir');

        if (empty($request->input('search.value'))) {
            $notifications = Notifications::where('user_id', Auth::user()->id)->offset($start)
                                          ->limit($limit)
                                          ->orderBy($order, $dir)
                                          ->get();
        } else {
            $search = $request->input('search.value');

            $notifications = Notifications::where('user_id', Auth::user()->id)->whereLike(['uid', 'notification_type', 'message'], $search)
                                          ->offset($start)
                                          ->limit($limit)
                                          ->orderBy($order, $dir)
                                          ->get();

            $totalFiltered = Notifications::where('user_id', Auth::user()->id)->whereLike(['uid', 'notification_type', 'message'], $search)->count();
        }

        $data = [];
        if ( ! empty($notifications)) {
            foreach ($notifications as $notification) {

                if ($notification->mark_read == 1) {
                    $status = 'checked';
                } else {
                    $status = '';
                }

                $nestedData['responsive_id']     = '';
                $nestedData['uid']               = $notification->uid;
                $nestedData['notification_type'] = ucfirst($notification->notification_type);
                $nestedData['message']           = $notification->message;
                $nestedData['mark_read']         = "<div class='form-check form-switch form-check-primary'>
                <input type='checkbox' class='form-check-input get_status' id='status_$notification->uid' data-id='$notification->uid' name='status' $status>
                <label class='form-check-label' for='status_$notification->uid'>
                  <span class='switch-icon-left'><i data-feather='check'></i> </span>
                  <span class='switch-icon-right'><i data-feather='x'></i> </span>
                </label>
              </div>";
                $data[]                          = $nestedData;

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
     * mark notification status
     *
     * @param  Notifications  $notification
     *
     * @return JsonResponse
     * @throws GeneralException
     */
    public function notificationToggle(Notifications $notification): JsonResponse
    {
        if (config('app.stage') == 'demo') {
            return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        try {

            if ($notification->update(['mark_read' => ! $notification->mark_read])) {
                return response()->json([
                        'status'  => 'success',
                        'message' => 'Notification read status was successfully changed',
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
     * @param  Request  $request
     *
     * @return JsonResponse
     */

    public function notificationBatchAction(Request $request): JsonResponse
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

                Notifications::where('user_id', Auth::user()->id)->whereIn('uid', $ids)->delete();

                return response()->json([
                        'status'  => 'success',
                        'message' => 'Notifications was successfully deleted',
                ]);

            case 'read':

                Notifications::where('user_id', Auth::user()->id)->whereIn('uid', $ids)->update([
                        'mark_read' => true,
                ]);

                return response()->json([
                        'status'  => 'success',
                        'message' => 'Mark notifications as read',
                ]);

        }

        return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.invalid_action'),
        ]);

    }

    /**
     * @param  Notifications  $notification
     *
     * @return JsonResponse
     */
    public function deleteNotification(Notifications $notification): JsonResponse
    {
        Notifications::where('uid', $notification->uid)->where('user_id', Auth::user()->id)->delete();

        return response()->json([
                'status'  => 'success',
                'message' => 'Notification was successfully deleted',
        ]);
    }

    /**
     * top up
     *
     * @return Factory|View|Application
     */
    public function topUp(): Factory|View|Application
    {
        $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => Auth::user()->displayName()],
                ['name' => __('locale.labels.top_up')],
        ];

        return \view('customer.Accounts.top_up', compact('breadcrumbs'));

    }


    /**
     * @param  AddUnitRequest  $request
     *
     * @return Factory|View|Application
     */
    public function checkoutTopUp(AddUnitRequest $request): Factory|View|Application
    {
        $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => Auth::user()->displayName()],
                ['name' => __('locale.labels.top_up')],
        ];


        $pageConfigs = [
                'bodyClass' => 'ecommerce-application',
        ];

        $payment_methods = PaymentMethods::where('status', true)->cursor();

        return \view('customer.Accounts.checkout_top_up', compact('breadcrumbs', 'request', 'pageConfigs', 'payment_methods'));
    }


    /**
     * @param  PayPayment  $request
     *
     * @return Application|Factory|View|RedirectResponse
     */
    public function payTopUp(PayPayment $request): View|Factory|RedirectResponse|Application
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('user.home')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $data = $this->account->payPayment($request->except('_token'));

        if (isset($data->getData()->status)) {

            if ($data->getData()->status == 'success') {

                if ($request->payment_methods == PaymentMethods::TYPE_BRAINTREE) {
                    return view('customer.Payments.braintree', [
                            'token'    => $data->getData()->token,
                            'post_url' => route('customer.top_up.braintree', ['user_id' => Auth::user()->id, 'sms_unit' => $request->sms_unit]),
                    ]);
                }

                if ($request->payment_methods == PaymentMethods::TYPE_STRIPE) {
                    return view('customer.Payments.stripe', [
                            'session_id'      => $data->getData()->session_id,
                            'publishable_key' => $data->getData()->publishable_key,
                    ]);
                }

                if ($request->payment_methods == PaymentMethods::TYPE_AUTHORIZE_NET) {

                    $months = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'];

                    return view('customer.Payments.authorize_net', [
                            'months'   => $months,
                            'post_url' => route('customer.top_up.authorize_net', ['user_id' => Auth::user()->id, 'sms_unit' => $request->sms_unit]),
                    ]);
                }

                if ($request->payment_methods == PaymentMethods::TYPE_CASH) {
                    return view('customer.Payments.offline', [
                            'data'      => $data->getData()->data,
                            'type'      => 'top_up',
                            'post_data' => json_encode($data->getData()->post_data),
                    ]);
                }

                /*Version 3.5*/


                if ($request->payment_methods == PaymentMethods::TYPE_VODACOMMPESA) {
                    return view('customer.Payments.vodacom_mpesa', [
                            'post_url' => route('customer.top_up.vodacommpesa', ['user_id' => Auth::user()->id, 'sms_unit' => $request->sms_unit]),
                    ]);
                }


                return redirect()->to($data->getData()->redirect_url);
            }

            return redirect()->route('user.home')->with([
                    'status'  => 'error',
                    'message' => $data->getData()->message,
            ]);
        }

        return redirect()->route('user.home')->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
        ]);

    }



    /*Version 3.1*/
    /*
    |--------------------------------------------------------------------------
    | Registration Payment
    |--------------------------------------------------------------------------
    |
    |
    |
    */

    /**
     *
     * @param  User  $user
     * @param  Plan  $plan
     * @param  PaymentMethods  $payment_method
     * @param  Request  $request
     *
     * @return RedirectResponse
     * @throws Exception
     */
    public function successfulRegisterPayment(User $user, Plan $plan, PaymentMethods $payment_method, Request $request): RedirectResponse
    {

        switch ($payment_method->type) {

            case PaymentMethods::TYPE_PAYPAL:

                $token = Session::get('paypal_payment_id');
                if ($request->token == $token) {
                    $paymentMethod = PaymentMethods::where('status', true)->where('type', 'paypal')->first();

                    if ($paymentMethod) {
                        $credentials = json_decode($paymentMethod->options);

                        if ($credentials->environment == 'sandbox') {
                            $environment = new SandboxEnvironment($credentials->client_id, $credentials->secret);
                        } else {
                            $environment = new ProductionEnvironment($credentials->client_id, $credentials->secret);
                        }

                        $client = new PayPalHttpClient($environment);

                        $request = new OrdersCaptureRequest($token);
                        $request->prefer('return=representation');

                        try {
                            // Call API with your client and get a response for your call
                            $response = $client->execute($request);

                            if ($response->statusCode == '201' && $response->result->status == 'COMPLETED' && isset($response->id)) {
                                $invoice = Invoices::create([
                                        'user_id'        => $user->id,
                                        'currency_id'    => $plan->currency_id,
                                        'payment_method' => $paymentMethod->id,
                                        'amount'         => $plan->price,
                                        'type'           => Invoices::TYPE_SUBSCRIPTION,
                                        'description'    => __('locale.subscription.payment_for_plan').' '.$plan->name,
                                        'transaction_id' => $response->id,
                                        'status'         => Invoices::STATUS_PAID,
                                ]);

                                if ($invoice) {

                                    $subscription                         = new Subscription();
                                    $subscription->user_id                = $user->id;
                                    $subscription->start_at               = Carbon::now();
                                    $subscription->status                 = Subscription::STATUS_ACTIVE;
                                    $subscription->plan_id                = $plan->getBillableId();
                                    $subscription->end_period_last_days   = '10';
                                    $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                    $subscription->end_at                 = null;
                                    $subscription->end_by                 = null;
                                    $subscription->payment_method_id      = $paymentMethod->id;
                                    $subscription->save();

                                    // add transaction
                                    $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                            'end_at'                 => $subscription->end_at,
                                            'current_period_ends_at' => $subscription->current_period_ends_at,
                                            'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                            'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                            'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                    ]);

                                    // add log
                                    $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                            'plan'  => $subscription->plan->getBillableName(),
                                            'price' => $subscription->plan->getBillableFormattedPrice(),
                                    ]);

                                    $user->sms_unit          = $plan->getOption('sms_max');
                                    $user->email_verified_at = Carbon::now();
                                    $user->save();

                                    $this->sendWelcomeEmail($user);

                                    //Add default Sender id
                                    $this->planSenderID($plan, $user);

                                    return redirect()->route('user.home')->with([
                                            'status'  => 'success',
                                            'message' => __('locale.payment_gateways.payment_successfully_made'),
                                    ]);
                                }

                                return redirect()->route('register')->with([
                                        'status'  => 'error',
                                        'message' => __('locale.exceptions.something_went_wrong'),
                                ]);

                            }

                        } catch (Exception $ex) {
                            return redirect()->route('register')->with([
                                    'status'  => 'error',
                                    'message' => $ex->getMessage(),
                            ]);
                        }


                        return redirect()->route('register')->with([
                                'status'  => 'info',
                                'message' => __('locale.sender_id.payment_cancelled'),
                        ]);
                    }

                    return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => __('locale.payment_gateways.not_found'),
                    ]);
                }

                return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.invalid_action'),
                ]);

            case PaymentMethods::TYPE_STRIPE:
                $paymentMethod = PaymentMethods::where('status', true)->where('type', 'stripe')->first();

                if ($paymentMethod) {
                    $credentials = json_decode($paymentMethod->options);
                    $secret_key  = $credentials->secret_key;
                    $session_id  = Session::get('session_id');

                    $stripe = new StripeClient($secret_key);

                    try {
                        $response = $stripe->checkout->sessions->retrieve($session_id);

                        if ($response->payment_status == 'paid') {
                            $invoice = Invoices::create([
                                    'user_id'        => $user->id,
                                    'currency_id'    => $plan->currency_id,
                                    'payment_method' => $paymentMethod->id,
                                    'amount'         => $plan->price,
                                    'type'           => Invoices::TYPE_SUBSCRIPTION,
                                    'description'    => __('locale.subscription.payment_for_plan').' '.$plan->name,
                                    'transaction_id' => $response->payment_intent,
                                    'status'         => Invoices::STATUS_PAID,
                            ]);

                            if ($invoice) {

                                $subscription                         = new Subscription();
                                $subscription->user_id                = $user->id;
                                $subscription->start_at               = Carbon::now();
                                $subscription->status                 = Subscription::STATUS_ACTIVE;
                                $subscription->plan_id                = $plan->getBillableId();
                                $subscription->end_period_last_days   = '10';
                                $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                $subscription->end_at                 = null;
                                $subscription->end_by                 = null;
                                $subscription->payment_method_id      = $paymentMethod->id;
                                $subscription->save();

                                // add transaction
                                $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                        'end_at'                 => $subscription->end_at,
                                        'current_period_ends_at' => $subscription->current_period_ends_at,
                                        'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                        'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                        'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                ]);

                                // add log
                                $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                        'plan'  => $subscription->plan->getBillableName(),
                                        'price' => $subscription->plan->getBillableFormattedPrice(),
                                ]);

                                $user->sms_unit          = $plan->getOption('sms_max');
                                $user->email_verified_at = Carbon::now();
                                $user->save();

                                $this->sendWelcomeEmail($user);

                                //Add default Sender id
                                $this->planSenderID($plan, $user);

                                return redirect()->route('user.home')->with([
                                        'status'  => 'success',
                                        'message' => __('locale.payment_gateways.payment_successfully_made'),
                                ]);
                            }

                            return redirect()->route('register')->with([
                                    'status'  => 'error',
                                    'message' => __('locale.exceptions.something_went_wrong'),
                            ]);

                        }

                    } catch (ApiErrorException $e) {
                        return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => $e->getMessage(),
                        ]);
                    }

                }

                return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.payment_gateways.not_found'),
                ]);

            case PaymentMethods::TYPE_2CHECKOUT:
            case PaymentMethods::TYPE_PAYU:
            case PaymentMethods::TYPE_COINPAYMENTS:
                $paymentMethod = PaymentMethods::where('status', true)->where('type', $payment_method->type)->first();

                if ($paymentMethod) {
                    $invoice = Invoices::create([
                            'user_id'        => $user->id,
                            'currency_id'    => $plan->currency_id,
                            'payment_method' => $paymentMethod->id,
                            'amount'         => $plan->price,
                            'type'           => Invoices::TYPE_SUBSCRIPTION,
                            'description'    => __('locale.subscription.payment_for_plan').' '.$plan->name,
                            'transaction_id' => $plan->uid,
                            'status'         => Invoices::STATUS_PAID,
                    ]);

                    if ($invoice) {

                        $subscription                         = new Subscription();
                        $subscription->user_id                = $user->id;
                        $subscription->start_at               = Carbon::now();
                        $subscription->status                 = Subscription::STATUS_ACTIVE;
                        $subscription->plan_id                = $plan->getBillableId();
                        $subscription->end_period_last_days   = '10';
                        $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                        $subscription->end_at                 = null;
                        $subscription->end_by                 = null;
                        $subscription->payment_method_id      = $paymentMethod->id;
                        $subscription->save();

                        // add transaction
                        $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                'end_at'                 => $subscription->end_at,
                                'current_period_ends_at' => $subscription->current_period_ends_at,
                                'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                        ]);

                        // add log
                        $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                'plan'  => $subscription->plan->getBillableName(),
                                'price' => $subscription->plan->getBillableFormattedPrice(),
                        ]);

                        $user->sms_unit          = $plan->getOption('sms_max');
                        $user->email_verified_at = Carbon::now();
                        $user->save();

                        $this->sendWelcomeEmail($user);

                        //Add default Sender id
                        $this->planSenderID($plan, $user);

                        return redirect()->route('user.home')->with([
                                'status'  => 'success',
                                'message' => __('locale.payment_gateways.payment_successfully_made'),
                        ]);
                    }

                    return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.something_went_wrong'),
                    ]);

                }

                return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.something_went_wrong'),
                ]);

            case PaymentMethods::TYPE_PAYNOW:
                $pollurl = Session::get('paynow_poll_url');
                if (isset($pollurl)) {
                    $paymentMethod = PaymentMethods::where('status', true)->where('type', 'paynow')->first();

                    if ($paymentMethod) {
                        $credentials = json_decode($paymentMethod->options);

                        $paynow = new Paynow(
                                $credentials->integration_id,
                                $credentials->integration_key,
                                route('customer.callback.paynow'),
                                route('user.registers.payment_success', ['user' => $user->uid, 'plan' => $plan->uid, 'payment_method' => $paymentMethod->uid])
                        );

                        try {
                            $response = $paynow->pollTransaction($pollurl);

                            if ($response->paid()) {

                                $invoice = Invoices::create([
                                        'user_id'        => $user->id,
                                        'currency_id'    => $plan->currency_id,
                                        'payment_method' => $paymentMethod->id,
                                        'amount'         => $plan->price,
                                        'type'           => Invoices::TYPE_SUBSCRIPTION,
                                        'description'    => __('locale.subscription.payment_for_plan').' '.$plan->name,
                                        'transaction_id' => $response->reference(),
                                        'status'         => Invoices::STATUS_PAID,
                                ]);

                                if ($invoice) {

                                    $subscription                         = new Subscription();
                                    $subscription->user_id                = $user->id;
                                    $subscription->start_at               = Carbon::now();
                                    $subscription->status                 = Subscription::STATUS_ACTIVE;
                                    $subscription->plan_id                = $plan->getBillableId();
                                    $subscription->end_period_last_days   = '10';
                                    $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                    $subscription->end_at                 = null;
                                    $subscription->end_by                 = null;
                                    $subscription->payment_method_id      = $paymentMethod->id;
                                    $subscription->save();

                                    // add transaction
                                    $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                            'end_at'                 => $subscription->end_at,
                                            'current_period_ends_at' => $subscription->current_period_ends_at,
                                            'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                            'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                            'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                    ]);

                                    // add log
                                    $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                            'plan'  => $subscription->plan->getBillableName(),
                                            'price' => $subscription->plan->getBillableFormattedPrice(),
                                    ]);

                                    $user->sms_unit          = $plan->getOption('sms_max');
                                    $user->email_verified_at = Carbon::now();
                                    $user->save();

                                    $this->sendWelcomeEmail($user);

                                    //Add default Sender id
                                    $this->planSenderID($plan, $user);

                                    return redirect()->route('user.home')->with([
                                            'status'  => 'success',
                                            'message' => __('locale.payment_gateways.payment_successfully_made'),
                                    ]);
                                }

                                return redirect()->route('register')->with([
                                        'status'  => 'error',
                                        'message' => __('locale.exceptions.something_went_wrong'),
                                ]);
                            }

                        } catch (Exception $ex) {
                            return redirect()->route('register')->with([
                                    'status'  => 'error',
                                    'message' => $ex->getMessage(),
                            ]);
                        }


                        return redirect()->route('register')->with([
                                'status'  => 'info',
                                'message' => __('locale.sender_id.payment_cancelled'),
                        ]);
                    }

                    return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => __('locale.payment_gateways.not_found'),
                    ]);
                }

                return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.invalid_action'),
                ]);

            case PaymentMethods::TYPE_INSTAMOJO:
                $payment_request_id = Session::get('payment_request_id');

                if ($request->payment_request_id == $payment_request_id) {
                    if ($request->payment_status == 'Completed') {

                        $paymentMethod = PaymentMethods::where('status', true)->where('type', 'instamojo')->first();

                        $invoice = Invoices::create([
                                'user_id'        => $user->id,
                                'currency_id'    => $plan->currency_id,
                                'payment_method' => $paymentMethod->id,
                                'amount'         => $plan->price,
                                'type'           => Invoices::TYPE_SUBSCRIPTION,
                                'description'    => __('locale.subscription.payment_for_plan').' '.$plan->name,
                                'transaction_id' => $request->payment_id,
                                'status'         => Invoices::STATUS_PAID,
                        ]);

                        if ($invoice) {

                            $subscription                         = new Subscription();
                            $subscription->user_id                = $user->id;
                            $subscription->start_at               = Carbon::now();
                            $subscription->status                 = Subscription::STATUS_ACTIVE;
                            $subscription->plan_id                = $plan->getBillableId();
                            $subscription->end_period_last_days   = '10';
                            $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                            $subscription->end_at                 = null;
                            $subscription->end_by                 = null;
                            $subscription->payment_method_id      = $paymentMethod->id;
                            $subscription->save();

                            // add transaction
                            $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                    'end_at'                 => $subscription->end_at,
                                    'current_period_ends_at' => $subscription->current_period_ends_at,
                                    'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                    'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                    'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                            ]);

                            // add log
                            $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                    'plan'  => $subscription->plan->getBillableName(),
                                    'price' => $subscription->plan->getBillableFormattedPrice(),
                            ]);

                            $user->sms_unit          = $plan->getOption('sms_max');
                            $user->email_verified_at = Carbon::now();
                            $user->save();

                            $this->sendWelcomeEmail($user);

                            //Add default Sender id
                            $this->planSenderID($plan, $user);

                            return redirect()->route('user.home')->with([
                                    'status'  => 'success',
                                    'message' => __('locale.payment_gateways.payment_successfully_made'),
                            ]);
                        }

                        return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => __('locale.exceptions.something_went_wrong'),
                        ]);

                    }

                    return redirect()->route('register')->with([
                            'status'  => 'info',
                            'message' => $request->payment_status,
                    ]);
                }

                return redirect()->route('register')->with([
                        'status'  => 'info',
                        'message' => __('locale.payment_gateways.payment_info_not_found'),
                ]);

            case PaymentMethods::TYPE_PAYUMONEY:

                $status      = $request->status;
                $firstname   = $request->firstname;
                $amount      = $request->amount;
                $txnid       = $request->txnid;
                $posted_hash = $request->hash;
                $key         = $request->key;
                $productinfo = $request->productinfo;
                $email       = $request->email;
                $salt        = "";

                // Salt should be same Post Request
                if (isset($request->additionalCharges)) {
                    $additionalCharges = $request->additionalCharges;
                    $retHashSeq        = $additionalCharges.'|'.$salt.'|'.$status.'|||||||||||'.$email.'|'.$firstname.'|'.$productinfo.'|'.$amount.'|'.$txnid.'|'.$key;
                } else {
                    $retHashSeq = $salt.'|'.$status.'|||||||||||'.$email.'|'.$firstname.'|'.$productinfo.'|'.$amount.'|'.$txnid.'|'.$key;
                }
                $hash = hash("sha512", $retHashSeq);
                if ($hash != $posted_hash) {
                    return redirect()->route('register')->with([
                            'status'  => 'info',
                            'message' => __('locale.exceptions.invalid_action'),
                    ]);
                }

                if ($status == 'Completed') {

                    $paymentMethod = PaymentMethods::where('status', true)->where('type', 'payumoney')->first();


                    $invoice = Invoices::create([
                            'user_id'        => $user->id,
                            'currency_id'    => $plan->currency_id,
                            'payment_method' => $paymentMethod->id,
                            'amount'         => $plan->price,
                            'type'           => Invoices::TYPE_SUBSCRIPTION,
                            'description'    => __('locale.subscription.payment_for_plan').' '.$plan->name,
                            'transaction_id' => $txnid,
                            'status'         => Invoices::STATUS_PAID,
                    ]);

                    if ($invoice) {

                        $subscription                         = new Subscription();
                        $subscription->user_id                = $user->id;
                        $subscription->start_at               = Carbon::now();
                        $subscription->status                 = Subscription::STATUS_ACTIVE;
                        $subscription->plan_id                = $plan->getBillableId();
                        $subscription->end_period_last_days   = '10';
                        $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                        $subscription->end_at                 = null;
                        $subscription->end_by                 = null;
                        $subscription->payment_method_id      = $paymentMethod->id;
                        $subscription->save();

                        // add transaction
                        $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                'end_at'                 => $subscription->end_at,
                                'current_period_ends_at' => $subscription->current_period_ends_at,
                                'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                        ]);

                        // add log
                        $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                'plan'  => $subscription->plan->getBillableName(),
                                'price' => $subscription->plan->getBillableFormattedPrice(),
                        ]);

                        $user->sms_unit          = $plan->getOption('sms_max');
                        $user->email_verified_at = Carbon::now();
                        $user->save();

                        $this->sendWelcomeEmail($user);

                        //Add default Sender id
                        $this->planSenderID($plan, $user);

                        return redirect()->route('user.home')->with([
                                'status'  => 'success',
                                'message' => __('locale.payment_gateways.payment_successfully_made'),
                        ]);
                    }

                    return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.something_went_wrong'),
                    ]);
                }

                return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => $status,
                ]);

            case PaymentMethods::TYPE_ORANGEMONEY:
                $paymentMethod = PaymentMethods::where('status', true)->where('type', PaymentMethods::TYPE_ORANGEMONEY)->first();

                if (isset($request->status)) {
                    if ($request->status == 'SUCCESS') {

                        $invoice = Invoices::create([
                                'user_id'        => $user->id,
                                'currency_id'    => $plan->currency_id,
                                'payment_method' => $paymentMethod->id,
                                'amount'         => $plan->price,
                                'type'           => Invoices::TYPE_SUBSCRIPTION,
                                'description'    => __('locale.subscription.payment_for_plan').' '.$plan->name,
                                'transaction_id' => $request->txnid,
                                'status'         => Invoices::STATUS_PAID,
                        ]);

                        if ($invoice) {

                            $subscription                         = new Subscription();
                            $subscription->user_id                = $user->id;
                            $subscription->start_at               = Carbon::now();
                            $subscription->status                 = Subscription::STATUS_ACTIVE;
                            $subscription->plan_id                = $plan->getBillableId();
                            $subscription->end_period_last_days   = '10';
                            $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                            $subscription->end_at                 = null;
                            $subscription->end_by                 = null;
                            $subscription->payment_method_id      = $paymentMethod->id;
                            $subscription->save();

                            // add transaction
                            $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                    'end_at'                 => $subscription->end_at,
                                    'current_period_ends_at' => $subscription->current_period_ends_at,
                                    'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                    'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                    'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                            ]);

                            // add log
                            $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                    'plan'  => $subscription->plan->getBillableName(),
                                    'price' => $subscription->plan->getBillableFormattedPrice(),
                            ]);

                            $user->sms_unit          = $plan->getOption('sms_max');
                            $user->email_verified_at = Carbon::now();
                            $user->save();

                            $this->sendWelcomeEmail($user);

                            //Add default Sender id
                            $this->planSenderID($plan, $user);

                            return redirect()->route('user.home')->with([
                                    'status'  => 'success',
                                    'message' => __('locale.payment_gateways.payment_successfully_made'),
                            ]);
                        }

                        return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => __('locale.exceptions.something_went_wrong'),
                        ]);

                    }

                    return redirect()->route('register')->with([
                            'status'  => 'info',
                            'message' => $request->status,
                    ]);
                }

                return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.something_went_wrong'),
                ]);


            case PaymentMethods::TYPE_DIRECTPAYONLINE:
                $paymentMethod = PaymentMethods::where('status', true)->where('type', PaymentMethods::TYPE_DIRECTPAYONLINE)->first();

                if ($paymentMethod) {
                    $credentials = json_decode($paymentMethod->options);

                    if ($credentials->environment == 'production') {
                        $payment_url = 'https://secure.3gdirectpay.com';
                    } else {
                        $payment_url = 'https://secure1.sandbox.directpay.online';
                    }

                    $companyToken     = $credentials->company_token;
                    $TransactionToken = $request->TransactionToken;

                    $postXml = <<<POSTXML
<?xml version="1.0" encoding="utf-8"?>
        <API3G>
          <CompanyToken>$companyToken</CompanyToken>
          <Request>verifyToken</Request>
          <TransactionToken>$TransactionToken</TransactionToken>
        </API3G>
POSTXML;

                    $curl = curl_init();
                    curl_setopt_array($curl, [
                            CURLOPT_URL            => $payment_url."/API/v6/",
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_ENCODING       => "",
                            CURLOPT_MAXREDIRS      => 10,
                            CURLOPT_TIMEOUT        => 30,
                            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CUSTOMREQUEST  => "POST",
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_SSL_VERIFYHOST => false,
                            CURLOPT_POSTFIELDS     => $postXml,
                            CURLOPT_HTTPHEADER     => [
                                    "cache-control: no-cache",
                            ],
                    ]);

                    $response = curl_exec($curl);
                    curl_close($curl);

                    if ($response != '') {
                        $xml = new SimpleXMLElement($response);

                        // Check if token was created successfully
                        if ($xml->xpath('Result')[0] != '000') {

                            return redirect()->route('register')->with([
                                    'status'  => 'error',
                                    'message' => __('locale.exceptions.invalid_action'),
                            ]);

                        }

                        if (isset($request->TransID) && isset($request->CCDapproval)) {
                            $invoice_exist = Invoices::where('transaction_id', $request->TransID)->first();
                            if ( ! $invoice_exist) {

                                $invoice = Invoices::create([
                                        'user_id'        => $user->id,
                                        'currency_id'    => $plan->currency_id,
                                        'payment_method' => $paymentMethod->id,
                                        'amount'         => $plan->price,
                                        'type'           => Invoices::TYPE_SUBSCRIPTION,
                                        'description'    => __('locale.subscription.payment_for_plan').' '.$plan->name,
                                        'transaction_id' => $request->TransID,
                                        'status'         => Invoices::STATUS_PAID,
                                ]);

                                if ($invoice) {

                                    $subscription                         = new Subscription();
                                    $subscription->user_id                = $user->id;
                                    $subscription->start_at               = Carbon::now();
                                    $subscription->status                 = Subscription::STATUS_ACTIVE;
                                    $subscription->plan_id                = $plan->getBillableId();
                                    $subscription->end_period_last_days   = '10';
                                    $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                    $subscription->end_at                 = null;
                                    $subscription->end_by                 = null;
                                    $subscription->payment_method_id      = $paymentMethod->id;
                                    $subscription->save();

                                    // add transaction
                                    $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                            'end_at'                 => $subscription->end_at,
                                            'current_period_ends_at' => $subscription->current_period_ends_at,
                                            'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                            'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                            'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                    ]);

                                    // add log
                                    $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                            'plan'  => $subscription->plan->getBillableName(),
                                            'price' => $subscription->plan->getBillableFormattedPrice(),
                                    ]);

                                    $user->sms_unit          = $plan->getOption('sms_max');
                                    $user->email_verified_at = Carbon::now();
                                    $user->save();

                                    $this->sendWelcomeEmail($user);

                                    //Add default Sender id
                                    $this->planSenderID($plan, $user);

                                    return redirect()->route('user.home')->with([
                                            'status'  => 'success',
                                            'message' => __('locale.payment_gateways.payment_successfully_made'),
                                    ]);
                                }

                                return redirect()->route('register')->with([
                                        'status'  => 'error',
                                        'message' => __('locale.exceptions.something_went_wrong'),
                                ]);

                            }

                            return redirect()->route('register')->with([
                                    'status'  => 'error',
                                    'message' => __('locale.exceptions.something_went_wrong'),
                            ]);

                        }

                    }
                }

                return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.payment_gateways.not_found'),
                ]);

            case PaymentMethods::TYPE_PAYGATEGLOBAL:
                $paymentMethod = PaymentMethods::where('status', true)->where('type', PaymentMethods::TYPE_PAYGATEGLOBAL)->first();

                if ($payment_method) {

                    $parameters = [
                            'auth_token' => $payment_method->api_key,
                            'identify'   => $request->identify,
                    ];

                    try {

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, 'https://paygateglobal.com/api/v2/status');
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $response = curl_exec($ch);
                        curl_close($ch);

                        $get_response = json_decode($response, true);

                        if (isset($get_response) && is_array($get_response) && array_key_exists('status', $get_response)) {
                            if ($get_response['success'] == 0) {
                                $invoice_exist = Invoices::where('transaction_id', $request->tx_reference)->first();
                                if ( ! $invoice_exist) {

                                    $invoice = Invoices::create([
                                            'user_id'        => $user->id,
                                            'currency_id'    => $plan->currency_id,
                                            'payment_method' => $paymentMethod->id,
                                            'amount'         => $plan->price,
                                            'type'           => Invoices::TYPE_SUBSCRIPTION,
                                            'description'    => __('locale.subscription.payment_for_plan').' '.$plan->name,
                                            'transaction_id' => $request->tx_reference,
                                            'status'         => Invoices::STATUS_PAID,
                                    ]);

                                    if ($invoice) {

                                        $subscription                         = new Subscription();
                                        $subscription->user_id                = $user->id;
                                        $subscription->start_at               = Carbon::now();
                                        $subscription->status                 = Subscription::STATUS_ACTIVE;
                                        $subscription->plan_id                = $plan->getBillableId();
                                        $subscription->end_period_last_days   = '10';
                                        $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                        $subscription->end_at                 = null;
                                        $subscription->end_by                 = null;
                                        $subscription->payment_method_id      = $paymentMethod->id;
                                        $subscription->save();

                                        // add transaction
                                        $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                                'end_at'                 => $subscription->end_at,
                                                'current_period_ends_at' => $subscription->current_period_ends_at,
                                                'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                                'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                                'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                        ]);

                                        // add log
                                        $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                                'plan'  => $subscription->plan->getBillableName(),
                                                'price' => $subscription->plan->getBillableFormattedPrice(),
                                        ]);

                                        $user->sms_unit          = $plan->getOption('sms_max');
                                        $user->email_verified_at = Carbon::now();
                                        $user->save();

                                        $this->sendWelcomeEmail($user);

                                        //Add default Sender id
                                        $this->planSenderID($plan, $user);

                                        return redirect()->route('user.home')->with([
                                                'status'  => 'success',
                                                'message' => __('locale.payment_gateways.payment_successfully_made'),
                                        ]);
                                    }

                                    return redirect()->route('register')->with([
                                            'status'  => 'error',
                                            'message' => __('locale.exceptions.something_went_wrong'),
                                    ]);


                                }

                                return redirect()->route('register')->with([
                                        'status'  => 'error',
                                        'message' => __('locale.exceptions.something_went_wrong'),
                                ]);

                            }

                            return redirect()->route('register')->with([
                                    'status'  => 'info',
                                    'message' => 'Waiting for administrator approval',
                            ]);
                        }

                        return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => __('locale.exceptions.something_went_wrong'),
                        ]);

                    } catch (Exception $e) {
                        return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => $e->getMessage(),
                        ]);
                    }
                }

                return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.something_went_wrong'),
                ]);


            case PaymentMethods::TYPE_CINETPAY:

                $paymentMethod  = PaymentMethods::where('status', true)->where('type', PaymentMethods::TYPE_CINETPAY)->first();
                $transaction_id = $request->transaction_id;
                $credentials    = json_decode($paymentMethod->options);

                $payment_data = [
                        'apikey'         => $credentials->api_key,
                        'site_id'        => $credentials->site_id,
                        'transaction_id' => $transaction_id,
                ];


                try {

                    $curl = curl_init();

                    curl_setopt_array($curl, [
                            CURLOPT_URL            => $credentials->payment_url.'/check',
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_CUSTOMREQUEST  => "POST",
                            CURLOPT_POSTFIELDS     => json_encode($payment_data),
                            CURLOPT_HTTPHEADER     => [
                                    "content-type: application/json",
                                    "cache-control: no-cache",
                            ],
                    ]);

                    $response = curl_exec($curl);
                    $err      = curl_error($curl);

                    curl_close($curl);

                    if ($response === false) {
                        return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => 'Php curl show false value. Please contact with your provider',
                        ]);

                    }

                    if ($err) {
                        return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => $err,
                        ]);
                    }

                    $result = json_decode($response, true);


                    if (is_array($result) && array_key_exists('code', $result) && array_key_exists('message', $result)) {
                        if ($result['code'] == '00') {

                            $invoice = Invoices::create([
                                    'user_id'        => $user->id,
                                    'currency_id'    => $plan->currency_id,
                                    'payment_method' => $paymentMethod->id,
                                    'amount'         => $plan->price,
                                    'type'           => Invoices::TYPE_SUBSCRIPTION,
                                    'description'    => __('locale.subscription.payment_for_plan').' '.$plan->name,
                                    'transaction_id' => $transaction_id,
                                    'status'         => Invoices::STATUS_PAID,
                            ]);

                            if ($invoice) {

                                $subscription                         = new Subscription();
                                $subscription->user_id                = $user->id;
                                $subscription->start_at               = Carbon::now();
                                $subscription->status                 = Subscription::STATUS_ACTIVE;
                                $subscription->plan_id                = $plan->getBillableId();
                                $subscription->end_period_last_days   = '10';
                                $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                $subscription->end_at                 = null;
                                $subscription->end_by                 = null;
                                $subscription->payment_method_id      = $paymentMethod->id;
                                $subscription->save();

                                // add transaction
                                $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                        'end_at'                 => $subscription->end_at,
                                        'current_period_ends_at' => $subscription->current_period_ends_at,
                                        'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                        'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                        'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                ]);

                                // add log
                                $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                        'plan'  => $subscription->plan->getBillableName(),
                                        'price' => $subscription->plan->getBillableFormattedPrice(),
                                ]);

                                $user->sms_unit          = $plan->getOption('sms_max');
                                $user->email_verified_at = Carbon::now();
                                $user->save();

                                $this->sendWelcomeEmail($user);

                                //Add default Sender id
                                $this->planSenderID($plan, $user);

                                return redirect()->route('user.home')->with([
                                        'status'  => 'success',
                                        'message' => __('locale.payment_gateways.payment_successfully_made'),
                                ]);
                            }

                            return redirect()->route('register')->with([
                                    'status'  => 'error',
                                    'message' => __('locale.exceptions.something_went_wrong'),
                            ]);

                        }

                        return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => $result['message'],
                        ]);
                    }

                    return redirect()->route('register')->with([
                            'status'       => 'error',
                            'redirect_url' => __('locale.exceptions.something_went_wrong'),
                    ]);
                } catch (Exception $ex) {

                    return redirect()->route('register')->with([
                            'status'       => 'error',
                            'redirect_url' => $ex->getMessage(),
                    ]);
                }

        }


        return redirect()->route('user.home')->with([
                'status'  => 'error',
                'message' => __('locale.payment_gateways.not_found'),
        ]);
    }

    /**
     * @param  User  $user
     *
     * @return RedirectResponse
     */
    public function cancelledRegisterPayment(User $user): RedirectResponse
    {
        $user->delete();

        return redirect()->route('register')->with([
                'status'  => 'info',
                'message' => __('locale.sender_id.payment_cancelled'),
        ]);
    }

    /**
     * @param  Request  $request
     *
     * @return RedirectResponse
     */
    public function braintreeRegister(Request $request): RedirectResponse
    {

        $plan = Plan::where('uid', $request->plan)->first();
        $user = User::where('uid', $request->user)->first();

        if ( ! $plan) {
            return redirect()->route('user.home')->with([
                    'status'  => 'error',
                    'message' => __('locale.payment_gateways.payment_info_not_found'),
            ]);
        }
        $paymentMethod = PaymentMethods::where('status', true)->where('type', 'braintree')->first();

        if ($paymentMethod) {
            $credentials = json_decode($paymentMethod->options);

            try {
                $gateway = new Gateway([
                        'environment' => $credentials->environment,
                        'merchantId'  => $credentials->merchant_id,
                        'publicKey'   => $credentials->public_key,
                        'privateKey'  => $credentials->private_key,
                ]);

                $result = $gateway->transaction()->sale([
                        'amount'             => $plan->price,
                        'paymentMethodNonce' => $request->payment_method_nonce,
                        'deviceData'         => $request->device_data,
                        'options'            => [
                                'submitForSettlement' => true,
                        ],
                ]);

                if ($result->success && isset($result->transaction->id)) {
                    $invoice = Invoices::create([
                            'user_id'        => $user->id,
                            'currency_id'    => $plan->currency_id,
                            'payment_method' => $paymentMethod->id,
                            'amount'         => $plan->price,
                            'type'           => Invoices::TYPE_SUBSCRIPTION,
                            'description'    => __('locale.subscription.payment_for_plan').' '.$plan->name,
                            'transaction_id' => $result->transaction->id,
                            'status'         => Invoices::STATUS_PAID,
                    ]);

                    if ($invoice) {

                        $subscription                         = new Subscription();
                        $subscription->user_id                = $user->id;
                        $subscription->start_at               = Carbon::now();
                        $subscription->status                 = Subscription::STATUS_ACTIVE;
                        $subscription->plan_id                = $plan->getBillableId();
                        $subscription->end_period_last_days   = '10';
                        $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                        $subscription->end_at                 = null;
                        $subscription->end_by                 = null;
                        $subscription->payment_method_id      = $paymentMethod->id;
                        $subscription->save();

                        // add transaction
                        $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                'end_at'                 => $subscription->end_at,
                                'current_period_ends_at' => $subscription->current_period_ends_at,
                                'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                        ]);

                        // add log
                        $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                'plan'  => $subscription->plan->getBillableName(),
                                'price' => $subscription->plan->getBillableFormattedPrice(),
                        ]);

                        $user->sms_unit          = $plan->getOption('sms_max');
                        $user->email_verified_at = Carbon::now();
                        $user->save();

                        $this->sendWelcomeEmail($user);

                        //Add default Sender id
                        $this->planSenderID($plan, $user);

                        return redirect()->route('user.home')->with([
                                'status'  => 'success',
                                'message' => __('locale.payment_gateways.payment_successfully_made'),
                        ]);
                    }

                    return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.something_went_wrong'),
                    ]);

                }

                return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => $result->message,
                ]);

            } catch (Exception $exception) {
                return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => $exception->getMessage(),
                ]);
            }
        }

        return redirect()->route('register')->with([
                'status'  => 'error',
                'message' => __('locale.payment_gateways.not_found'),
        ]);
    }

    /**
     * @param  User  $user
     * @param  Request  $request
     *
     * @return RedirectResponse
     */
    public function authorizeNetRegister(User $user, Request $request): RedirectResponse
    {

        $plan = Plan::where('uid', $request->plan)->first();

        if ( ! $plan) {
            return redirect()->route('user.home')->with([
                    'status'  => 'error',
                    'message' => __('locale.payment_gateways.payment_info_not_found'),
            ]);
        }

        $paymentMethod = PaymentMethods::where('status', true)->where('type', 'authorize_net')->first();

        if ($paymentMethod) {
            $credentials = json_decode($paymentMethod->options);

            try {

                $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
                $merchantAuthentication->setName($credentials->login_id);
                $merchantAuthentication->setTransactionKey($credentials->transaction_key);

                // Set the transaction's refId
                $refId      = 'ref'.time();
                $cardNumber = preg_replace('/\s+/', '', $request->cardNumber);

                // Create the payment data for a credit card
                $creditCard = new AnetAPI\CreditCardType();
                $creditCard->setCardNumber($cardNumber);
                $creditCard->setExpirationDate($request->expiration_year."-".$request->expiration_month);
                $creditCard->setCardCode($request->cvv);


                // Add the payment data to a paymentType object
                $paymentOne = new AnetAPI\PaymentType();
                $paymentOne->setCreditCard($creditCard);

                // Create order information
                $order = new AnetAPI\OrderType();
                $order->setInvoiceNumber($plan->uid);
                $order->setDescription(__('locale.subscription.payment_for_plan').' '.$plan->name);


                // Set the customer's Bill To address
                $customerAddress = new AnetAPI\CustomerAddressType();
                $customerAddress->setFirstName(auth()->user()->first_name);
                $customerAddress->setLastName(auth()->user()->last_name);

                // Set the customer's identifying information
                $customerData = new AnetAPI\CustomerDataType();
                $customerData->setType("individual");
                $customerData->setId(auth()->user()->id);
                $customerData->setEmail(auth()->user()->email);


                // Create a TransactionRequestType object and add the previous objects to it
                $transactionRequestType = new AnetAPI\TransactionRequestType();
                $transactionRequestType->setTransactionType("authCaptureTransaction");
                $transactionRequestType->setAmount($plan->price);
                $transactionRequestType->setOrder($order);
                $transactionRequestType->setPayment($paymentOne);
                $transactionRequestType->setBillTo($customerAddress);
                $transactionRequestType->setCustomer($customerData);


                // Assemble the complete transaction request
                $requests = new AnetAPI\CreateTransactionRequest();
                $requests->setMerchantAuthentication($merchantAuthentication);
                $requests->setRefId($refId);
                $requests->setTransactionRequest($transactionRequestType);

                // Create the controller and get the response
                $controller = new AnetController\CreateTransactionController($requests);
                if ($credentials->environment == 'sandbox') {
                    $result = $controller->executeWithApiResponse(ANetEnvironment::SANDBOX);
                } else {
                    $result = $controller->executeWithApiResponse(ANetEnvironment::PRODUCTION);
                }

                if (isset($result) && $result->getMessages()->getResultCode() == 'Ok' && $result->getTransactionResponse()) {
                    $invoice = Invoices::create([
                            'user_id'        => $user->id,
                            'currency_id'    => $plan->currency_id,
                            'payment_method' => $paymentMethod->id,
                            'amount'         => $plan->price,
                            'type'           => Invoices::TYPE_SUBSCRIPTION,
                            'description'    => __('locale.subscription.payment_for_plan').' '.$plan->name,
                            'transaction_id' => $result->getRefId(),
                            'status'         => Invoices::STATUS_PAID,
                    ]);

                    if ($invoice) {

                        $subscription                         = new Subscription();
                        $subscription->user_id                = $user->id;
                        $subscription->start_at               = Carbon::now();
                        $subscription->status                 = Subscription::STATUS_ACTIVE;
                        $subscription->plan_id                = $plan->getBillableId();
                        $subscription->end_period_last_days   = '10';
                        $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                        $subscription->end_at                 = null;
                        $subscription->end_by                 = null;
                        $subscription->payment_method_id      = $paymentMethod->id;
                        $subscription->save();

                        // add transaction
                        $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                'end_at'                 => $subscription->end_at,
                                'current_period_ends_at' => $subscription->current_period_ends_at,
                                'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                        ]);

                        // add log
                        $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                'plan'  => $subscription->plan->getBillableName(),
                                'price' => $subscription->plan->getBillableFormattedPrice(),
                        ]);

                        $user->sms_unit          = $plan->getOption('sms_max');
                        $user->email_verified_at = Carbon::now();
                        $user->save();

                        $this->sendWelcomeEmail($user);

                        //Add default Sender id
                        $this->planSenderID($plan, $user);

                        return redirect()->route('user.home')->with([
                                'status'  => 'success',
                                'message' => __('locale.payment_gateways.payment_successfully_made'),
                        ]);
                    }

                    return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.something_went_wrong'),
                    ]);

                }

                return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.invalid_action'),
                ]);

            } catch (Exception $exception) {
                return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => $exception->getMessage(),
                ]);
            }
        }

        return redirect()->route('register')->with([
                'status'  => 'error',
                'message' => __('locale.payment_gateways.not_found'),
        ]);
    }

    /**
     * sslcommerz subscription payment
     *
     * @param  Request  $request
     *
     * @return RedirectResponse
     */
    public function sslcommerzRegister(Request $request): RedirectResponse
    {

        if (isset($request->status)) {
            if ($request->status == 'VALID') {
                $paymentMethod = PaymentMethods::where('status', true)->where('type', 'sslcommerz')->first();
                if ($paymentMethod) {

                    $plan = Plan::where('uid', $request->tran_id)->first();
                    $user = User::where('uid', $request->user)->first();

                    if ($plan && $user) {
                        $invoice = Invoices::create([
                                'user_id'        => $user->id,
                                'currency_id'    => $plan->currency_id,
                                'payment_method' => $paymentMethod->id,
                                'amount'         => $plan->price,
                                'type'           => Invoices::TYPE_SUBSCRIPTION,
                                'description'    => __('locale.subscription.payment_for_plan').' '.$plan->name,
                                'transaction_id' => $request->bank_tran_id,
                                'status'         => Invoices::STATUS_PAID,
                        ]);


                        if ($invoice) {

                            $subscription                         = new Subscription();
                            $subscription->user_id                = $user->id;
                            $subscription->start_at               = Carbon::now();
                            $subscription->status                 = Subscription::STATUS_ACTIVE;
                            $subscription->plan_id                = $plan->getBillableId();
                            $subscription->end_period_last_days   = '10';
                            $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                            $subscription->end_at                 = null;
                            $subscription->end_by                 = null;
                            $subscription->payment_method_id      = $paymentMethod->id;
                            $subscription->save();

                            // add transaction
                            $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                    'end_at'                 => $subscription->end_at,
                                    'current_period_ends_at' => $subscription->current_period_ends_at,
                                    'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                    'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                    'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                            ]);

                            // add log
                            $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                    'plan'  => $subscription->plan->getBillableName(),
                                    'price' => $subscription->plan->getBillableFormattedPrice(),
                            ]);

                            $user->sms_unit          = $plan->getOption('sms_max');
                            $user->email_verified_at = Carbon::now();
                            $user->save();

                            $this->sendWelcomeEmail($user);

                            //Add default Sender id
                            $this->planSenderID($plan, $user);

                            return redirect()->route('user.home')->with([
                                    'status'  => 'success',
                                    'message' => __('locale.payment_gateways.payment_successfully_made'),
                            ]);
                        }

                        return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => __('locale.exceptions.something_went_wrong'),
                        ]);
                    }

                    return redirect()->route('user.home')->with([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.something_went_wrong'),
                    ]);
                }
            }

            return redirect()->route('user.home')->with([
                    'status'  => 'error',
                    'message' => $request->status,
            ]);

        }


        return redirect()->route('user.home')->with([
                'status'  => 'error',
                'message' => __('locale.payment_gateways.not_found'),
        ]);
    }


    /**
     * aamarpay subscription payment
     *
     * @param  Request  $request
     *
     * @return RedirectResponse
     */
    public function aamarpayRegister(Request $request): RedirectResponse
    {

        if (isset($request->pay_status) && isset($request->mer_txnid)) {

            $plan = Plan::where('uid', $request->mer_txnid)->first();
            $user = User::where('uid', $request->user)->first();

            if ($request->pay_status == 'Successful') {
                $paymentMethod = PaymentMethods::where('status', true)->where('type', 'aamarpay')->first();
                if ($paymentMethod) {

                    if ($plan) {
                        $invoice = Invoices::create([
                                'user_id'        => $user->id,
                                'currency_id'    => $plan->currency_id,
                                'payment_method' => $paymentMethod->id,
                                'amount'         => $plan->price,
                                'type'           => Invoices::TYPE_SUBSCRIPTION,
                                'description'    => __('locale.subscription.payment_for_plan').' '.$plan->name,
                                'transaction_id' => $request->pg_txnid,
                                'status'         => Invoices::STATUS_PAID,
                        ]);


                        if ($invoice) {

                            $subscription                         = new Subscription();
                            $subscription->user_id                = $user->id;
                            $subscription->start_at               = Carbon::now();
                            $subscription->status                 = Subscription::STATUS_ACTIVE;
                            $subscription->plan_id                = $plan->getBillableId();
                            $subscription->end_period_last_days   = '10';
                            $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                            $subscription->end_at                 = null;
                            $subscription->end_by                 = null;
                            $subscription->payment_method_id      = $paymentMethod->id;
                            $subscription->save();

                            // add transaction
                            $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                    'end_at'                 => $subscription->end_at,
                                    'current_period_ends_at' => $subscription->current_period_ends_at,
                                    'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                    'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                    'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                            ]);

                            // add log
                            $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                    'plan'  => $subscription->plan->getBillableName(),
                                    'price' => $subscription->plan->getBillableFormattedPrice(),
                            ]);

                            $user->sms_unit          = $plan->getOption('sms_max');
                            $user->email_verified_at = Carbon::now();
                            $user->save();

                            $this->sendWelcomeEmail($user);

                            //Add default Sender id
                            $this->planSenderID($plan, $user);

                            return redirect()->route('user.home')->with([
                                    'status'  => 'success',
                                    'message' => __('locale.payment_gateways.payment_successfully_made'),
                            ]);
                        }

                        return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => __('locale.exceptions.something_went_wrong'),
                        ]);
                    }

                    return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.something_went_wrong'),
                    ]);
                }
            }

            return redirect()->route('register')->with([
                    'status'  => 'error',
                    'message' => $request->pay_status,
            ]);

        }


        return redirect()->route('user.home')->with([
                'status'  => 'error',
                'message' => __('locale.payment_gateways.not_found'),
        ]);
    }

    /*Version 3.3*/

    public function sendWelcomeEmail($user)
    {
        if (Helper::app_config('user_registration_notification_email')) {
            $user->notify(new WelcomeEmailNotification($user->first_name, $user->last_name, $user->email, route('login'), ''));
        }
    }


    /*Version 3.5*/

    /**
     * Plan sender id
     *
     * @param $plan
     * @param $user
     *
     * @return void
     */
    public function planSenderID($plan, $user)
    {
        if (isset($plan->getOptions()['sender_id']) && $plan->getOption('sender_id') !== null) {
            $sender_id = Senderid::where('sender_id', $plan->getOption('sender_id'))->where('user_id', $user->id)->first();
            if ( ! $sender_id) {
                $current = Carbon::now();
                Senderid::create([
                        'sender_id'        => $plan->getOption('sender_id'),
                        'status'           => 'active',
                        'price'            => $plan->getOption('sender_id_price'),
                        'billing_cycle'    => $plan->getOption('sender_id_billing_cycle'),
                        'frequency_amount' => $plan->getOption('sender_id_frequency_amount'),
                        'frequency_unit'   => $plan->getOption('sender_id_frequency_unit'),
                        'currency_id'      => $plan->currency->id,
                        'validity_date'    => $current->add($plan->getOption('sender_id_frequency_unit'), $plan->getOption('sender_id_frequency_amount')),
                        'payment_claimed'  => true,
                        'user_id'          => $user->id,
                ]);
            }
        }
    }


    /**
     * @param  User  $user
     * @param  Request  $request
     *
     * @return RedirectResponse
     */
    public function vodacommpesaRegister(User $user, Request $request): RedirectResponse
    {

        $plan = Plan::where('uid', $request->plan)->first();

        if ( ! $plan) {
            return redirect()->route('user.home')->with([
                    'status'  => 'error',
                    'message' => __('locale.payment_gateways.payment_info_not_found'),
            ]);
        }

        $paymentMethod = PaymentMethods::where('status', true)->where('type', PaymentMethods::TYPE_VODACOMMPESA)->first();

        if ($paymentMethod) {
            $credentials = json_decode($paymentMethod->options);

            try {

                $credentialsParams = [
                        'apiKey'              => $credentials->apiKey,             // API Key
                        'publicKey'           => $credentials->publicKey,          // Public Key
                        'serviceProviderCode' => $credentials->serviceProviderCode // Service Provider Code
                ];

                if ($credentials->environment == 'sandbox') {
                    $credentialsParams['environment'] = Environment::SANDBOX;
                } else {
                    $credentialsParams['environment'] = Environment::PRODUCTION;
                }

                $client = new Client($credentialsParams);

                $transaction_id = str_random(10);

                $paymentData = [
                        'from'        => $request->phone,  // Customer MSISDN
                        'reference'   => $plan->uid,  // Third Party Reference
                        'transaction' => $transaction_id,  // Transaction Reference
                        'amount'      => $plan->price,
                ];

                $result = $client->receive($paymentData);


                if (isset($result) && is_array($result->data)) {
                    if ($result->success) {
                        $invoice = Invoices::create([
                                'user_id'        => $user->id,
                                'currency_id'    => $plan->currency_id,
                                'payment_method' => $paymentMethod->id,
                                'amount'         => $plan->price,
                                'type'           => Invoices::TYPE_SUBSCRIPTION,
                                'description'    => __('locale.subscription.payment_for_plan').' '.$plan->name,
                                'transaction_id' => $result->data['transaction'],
                                'status'         => Invoices::STATUS_PAID,
                        ]);

                        if ($invoice) {

                            $subscription                         = new Subscription();
                            $subscription->user_id                = $user->id;
                            $subscription->start_at               = Carbon::now();
                            $subscription->status                 = Subscription::STATUS_ACTIVE;
                            $subscription->plan_id                = $plan->getBillableId();
                            $subscription->end_period_last_days   = '10';
                            $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                            $subscription->end_at                 = null;
                            $subscription->end_by                 = null;
                            $subscription->payment_method_id      = $paymentMethod->id;
                            $subscription->save();

                            // add transaction
                            $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                    'end_at'                 => $subscription->end_at,
                                    'current_period_ends_at' => $subscription->current_period_ends_at,
                                    'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                    'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                    'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                            ]);

                            // add log
                            $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                    'plan'  => $subscription->plan->getBillableName(),
                                    'price' => $subscription->plan->getBillableFormattedPrice(),
                            ]);

                            $user->sms_unit          = $plan->getOption('sms_max');
                            $user->email_verified_at = Carbon::now();
                            $user->save();

                            $this->sendWelcomeEmail($user);

                            //Add default Sender id
                            $this->planSenderID($plan, $user);

                            return redirect()->route('user.home')->with([
                                    'status'  => 'success',
                                    'message' => __('locale.payment_gateways.payment_successfully_made'),
                            ]);
                        }
                    }

                    return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => $result->data['description'],
                    ]);

                }

                return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.invalid_action'),
                ]);

            } catch (Exception $exception) {
                return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => $exception->getMessage(),
                ]);
            }
        }

        return redirect()->route('register')->with([
                'status'  => 'error',
                'message' => __('locale.payment_gateways.not_found'),
        ]);
    }


}
