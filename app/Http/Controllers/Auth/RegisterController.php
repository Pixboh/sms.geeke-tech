<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\Invoices;
use App\Models\Language;
use App\Models\PaymentMethods;
use App\Models\Plan;
use App\Models\Senderid;
use App\Models\Subscription;
use App\Models\SubscriptionLog;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Notifications\WelcomeEmailNotification;
use App\Repositories\Contracts\AccountRepository;
use App\Repositories\Contracts\SubscriptionRepository;
use App\Rules\Phone;
use Arcanedev\NoCaptcha\Rules\CaptchaRule;
use Carbon\Carbon;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Foundation\Auth\RegistersUsers;
use Psr\SimpleCache\InvalidArgumentException;

class RegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default, this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
    */

    use RegistersUsers;

    /**
     * Where to redirect users after registration.
     *
     * @var string
     */
    protected string $redirectTo = '/login';

    /**
     * @var AccountRepository
     */
    protected AccountRepository $account;

    protected SubscriptionRepository $subscriptions;

    /**
     * RegisterController constructor.
     *
     * @param  AccountRepository  $account
     * @param  SubscriptionRepository  $subscriptions
     */
    public function __construct(AccountRepository $account, SubscriptionRepository $subscriptions)
    {
        $this->middleware('guest');
        $this->account       = $account;
        $this->subscriptions = $subscriptions;
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @param  array  $data
     *
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data): \Illuminate\Contracts\Validation\Validator
    {
        $rules = [
                'first_name' => ['required', 'string', 'max:255'],
                'email'      => ['required', 'string', 'email', 'max:255', 'unique:users'],
                'password'   => ['required', 'string', 'min:8', 'confirmed'],
                'phone'      => ['required', new Phone($data['phone'])],
                'timezone'   => ['required', 'timezone'],
                'address'    => ['required', 'string'],
                'city'       => ['required', 'string'],
                'country'    => ['required', 'string'],
                'plans'      => ['required'],
                'locale'     => ['required', 'string', 'min:2', 'max:2'],
        ];

        if (config('no-captcha.registration')) {
            $rules['g-recaptcha-response'] = ['required', new CaptchaRule()];
        }

        return Validator::make($data, $rules);
    }

    /**
     * @param  Request  $request
     *
     * @return View|Factory|Application|RedirectResponse
     * @throws InvalidArgumentException
     */
    public function register(Request $request): View|Factory|Application|RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('login')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $data = $request->except('_token');

        $rules = [
                'first_name' => ['required', 'string', 'max:255'],
                'email'      => ['required', 'string', 'email', 'max:255', 'unique:users'],
                'password'   => ['required', 'string', 'min:8', 'confirmed'],
                'phone'      => ['required', new Phone($request->phone)],
                'timezone'   => ['required', 'timezone'],
                'address'    => ['required', 'string'],
                'city'       => ['required', 'string'],
                'country'    => ['required', 'string'],
                'plans'      => ['required'],
                'locale'     => ['required', 'string', 'min:2', 'max:2'],
        ];

        if (config('no-captcha.registration')) {
            $rules['g-recaptcha-response'] = ['required', new CaptchaRule()];
        }

        $v = Validator::make($data, $rules);

        if ($v->fails()) {
            return redirect()->route('register')->withInput()->withErrors($v->errors());
        }

        $plan = Plan::find($data['plans']);
        $user = $this->account->register($data);

        if ($plan->price == 0.00) {
            $subscription                         = new Subscription();
            $subscription->user_id                = $user->id;
            $subscription->start_at               = Carbon::now();
            $subscription->status                 = Subscription::STATUS_ACTIVE;
            $subscription->plan_id                = $plan->getBillableId();
            $subscription->end_period_last_days   = '10';
            $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
            $subscription->end_at                 = null;
            $subscription->end_by                 = null;
            $subscription->payment_method_id      = null;
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

            $user->sms_unit = $plan->getOption('sms_max');
            $user->save();

            if (config('account.verify_account')) {
                $user->sendEmailVerificationNotification();
            } else {
                if (Helper::app_config('user_registration_notification_email')) {
                    $user->notify(new WelcomeEmailNotification($user->first_name, $user->last_name, $user->email, route('login'), $data['password']));
                }
            }

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

            return redirect()->route('user.home')->with([
                    'status'  => 'success',
                    'message' => __('locale.payment_gateways.payment_successfully_made'),
            ]);

        }

        $user->email_verified_at = Carbon::now();
        $user->save();
        $callback_data = $this->subscriptions->payRegisterPayment($plan, $data, $user);

        if (isset($callback_data->getData()->status)) {

            if ($callback_data->getData()->status == 'success') {

                if ($data['payment_methods'] == PaymentMethods::TYPE_BRAINTREE) {
                    return view('auth.payment.braintree', [
                            'token'    => $callback_data->getData()->token,
                            'post_url' => route('user.registers.braintree', $plan->uid),
                    ]);
                }

                if ($data['payment_methods'] == PaymentMethods::TYPE_STRIPE) {
                    return view('auth.payment.stripe', [
                            'session_id'      => $callback_data->getData()->session_id,
                            'publishable_key' => $callback_data->getData()->publishable_key,
                    ]);
                }

                if ($data['payment_methods'] == PaymentMethods::TYPE_AUTHORIZE_NET) {

                    $months = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'];

                    return view('auth.payment.authorize_net', [
                            'months'   => $months,
                            'post_url' => route('user.registers.authorize_net', ['user' => $user->uid, 'plan' => $plan->uid]),
                    ]);
                }

                if ($data['payment_methods'] == PaymentMethods::TYPE_CASH) {
                    return view('auth.payment.offline', [
                            'data' => $callback_data->getData()->data,
                            'user' => $user->uid,
                            'plan' => $plan->uid,
                    ]);
                }

                if ($data['payment_methods'] == PaymentMethods::TYPE_VODACOMMPESA) {
                    return view('auth.payment.vodacom_mpesa', [
                            'post_url' => route('user.registers.vodacommpesa', ['user' => $user->uid, 'plan' => $plan->uid]),
                    ]);
                }


                return redirect()->to($callback_data->getData()->redirect_url);
            }

            $user->delete();

            return redirect()->route('register')->with([
                    'status'  => 'error',
                    'message' => $callback_data->getData()->message,
            ]);
        }

        $user->delete();

        return redirect()->route('register')->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
        ]);

    }

    // Register

    /**
     * @return Application|Factory|View
     */
    public function showRegistrationForm(): View|Factory|Application
    {
        $pageConfigs     = [
                'blankPage' => true,
        ];
        $languages       = Language::where('status', 1)->get();
        $plans           = Plan::where('status', true)->where('show_in_customer', true)->cursor();
        $payment_methods = PaymentMethods::where('status', 1)->get();

        return view('/auth/register', [
                'pageConfigs'     => $pageConfigs,
                'languages'       => $languages,
                'plans'           => $plans,
                'payment_methods' => $payment_methods,
        ]);
    }

    public function PayOffline(Request $request)
    {
        $paymentMethod = PaymentMethods::where('status', true)->where('type', 'offline_payment')->first();

        if ( ! $paymentMethod) {
            return redirect()->route('register')->with([
                    'status'  => 'error',
                    'message' => __('locale.payment_gateways.not_found'),
            ]);
        }

        $plan = Plan::findByUid($request->plan);
        $user = User::findByUid($request->user);

        $subscription                         = new Subscription();
        $subscription->user_id                = $user->id;
        $subscription->start_at               = Carbon::now();
        $subscription->status                 = Subscription::STATUS_NEW;
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
                'status'                 => SubscriptionTransaction::STATUS_PENDING,
                'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                'amount'                 => $subscription->plan->getBillableFormattedPrice(),
        ]);

        // add log
        $subscription->addLog(SubscriptionLog::TYPE_CLAIMED, [
                'plan'  => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
        ]);

        Invoices::create([
                'user_id'        => $user->id,
                'currency_id'    => $plan->currency_id,
                'payment_method' => $paymentMethod->id,
                'amount'         => $plan->price,
                'type'           => Invoices::TYPE_SUBSCRIPTION,
                'description'    => __('locale.subscription.payment_for_plan').' '.$plan->name,
                'transaction_id' => 'subscription|'.$subscription->uid,
                'status'         => Invoices::STATUS_PENDING,
        ]);

        return redirect()->route('user.home')->with([
                'status'  => 'success',
                'message' => __('locale.subscription.payment_is_being_verified'),
        ]);

    }

}
