<?php

namespace App\Repositories\Eloquent;

use App\Helpers\Helper;
use App\Models\Customer;

use App\Models\User;
use App\Notifications\WelcomeEmailNotification;
use App\Repositories\Contracts\CustomerRepository;
use Exception;
use Illuminate\Config\Repository;
use Illuminate\Support\Arr;
use App\Exceptions\GeneralException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Throwable;


/**
 * Class EloquentCustomerRepository.
 */
class EloquentCustomerRepository extends EloquentBaseRepository implements CustomerRepository
{


    /**
     * @var Repository
     */
    protected Repository $config;

    /**
     * EloquentCustomerRepository constructor.
     *
     * @param  User  $user
     * @param  Repository  $config
     */
    public function __construct(User $user, Repository $config)
    {
        parent::__construct($user);
        $this->config = $config;
    }

    /**
     * @param  array  $input
     * @param  bool  $confirmed
     *
     * @return User
     * @throws GeneralException
     *
     */
    public function store(array $input, bool $confirmed = false): User
    {



        /** @var User $user */
        $user = $this->make(Arr::only($input, ['first_name', 'last_name', 'email', 'status', 'timezone', 'locale']));

        if (empty($user->locale)) {
            $user->locale = $this->config->get('app.locale');
        }

        if (empty($user->timezone)) {
            $user->timezone = $this->config->get('app.timezone');
        }

        $user->email_verified_at = now();
        $user->is_admin          = false;
        $user->is_customer       = true;

        if ( ! $this->save($user, $input)) {
            throw new GeneralException(__('locale.exceptions.something_went_wrong'));
        }

        Customer::create([
                'user_id'       => $user->id,
                'phone'         => $input['phone'],
                'permissions'   => Customer::customerPermissions(),
                'notifications' => json_encode([
                        'login'        => 'no',
                        'sender_id'    => 'yes',
                        'keyword'      => 'yes',
                        'subscription' => 'yes',
                        'promotion'    => 'yes',
                        'profile'      => 'yes',
                ]),
        ]);

        if (isset($input['welcome_message'])) {
            $user->notify(new WelcomeEmailNotification($user->first_name, $user->last_name, $user->email, route('login'), $input['password']));
        }

        return $user;
    }


    /**
     * @param  User  $customer
     * @param  array  $input
     *
     * @return User
     * @throws GeneralException
     */
    public function update(User $customer, array $input): User
    {

        $customer->fill(Arr::except($input, 'password'));

        if ( ! $this->save($customer, $input)) {
            throw new GeneralException(__('locale.exceptions.something_went_wrong'));
        }

        return $customer;
    }

    /**
     * @param  User  $user
     * @param  array  $input
     *
     * @return bool
     */
    private function save(User $user, array $input): bool
    {
        if (isset($input['password']) && ! empty($input['password'])) {
            $user->password = Hash::make($input['password']);
        }

        if ( ! $user->save()) {
            return false;
        }

        return true;
    }

    /**
     * update user information
     *
     * @param  User  $customer
     * @param  array  $input
     *
     * @return User
     * @throws GeneralException
     */
    public function updateInformation(User $customer, array $input): User
    {
        $get_customer = Customer::where('user_id', $customer->id)->first();

        if ( ! $get_customer) {
            throw new GeneralException(__('locale.exceptions.something_went_wrong'));
        }

        if (isset($input['notifications']) && count($input['notifications']) > 0) {

            $defaultNotifications = [
                    'login'        => 'no',
                    'sender_id'    => 'no',
                    'keyword'      => 'no',
                    'subscription' => 'no',
                    'promotion'    => 'no',
                    'profile'      => 'no',
            ];

            $notifications          = array_merge($defaultNotifications, $input['notifications']);
            $input['notifications'] = json_encode($notifications);
        }

        $data = $get_customer->update($input);

        if ( ! $data) {
            throw new GeneralException(__('locale.exceptions.something_went_wrong'));
        }

        return $customer;
    }


    /**
     * update permissions
     *
     * @param  User  $customer
     * @param  array  $input
     *
     * @return User
     * @throws GeneralException
     */
    public function permissions(User $customer, array $input): User
    {
        $data = array_values($input['permissions']);

        $status = $customer->customer()->update([
                'permissions' => json_encode($data),
        ]);

        if ( ! $status) {
            throw new GeneralException(__('locale.exceptions.something_went_wrong'));
        }

        return $customer;
    }


    /**
     * @param  User  $customer
     *
     * @return bool
     * @throws GeneralException
     */
    public function destroy(User $customer): bool
    {
        if ( ! $customer->can_delete) {
            throw new GeneralException(__('exceptions.backend.users.first_user_cannot_be_destroyed'));
        }

        if ( ! $customer->delete()) {
            throw new GeneralException(__('exceptions.backend.users.delete'));
        }

        return true;
    }

    /**
     * @param  array  $ids
     *
     * @return mixed
     * @throws Exception|Throwable
     *
     */
    public function batchEnable(array $ids): bool
    {
        DB::transaction(function () use ($ids) {
            if ($this->query()->whereIn('uid', $ids)
                     ->update(['status' => true])
            ) {
                return true;
            }

            throw new GeneralException(__('exceptions.backend.users.update'));
        });

        return true;
    }

    /**
     * @param  array  $ids
     *
     * @return mixed
     * @throws Exception|Throwable
     *
     */
    public function batchDisable(array $ids): bool
    {
        DB::transaction(function () use ($ids) {
            if ($this->query()->whereIn('uid', $ids)
                     ->update(['status' => false])
            ) {
                return true;
            }

            throw new GeneralException(__('exceptions.backend.users.update'));
        });

        return true;
    }


    /*
    |--------------------------------------------------------------------------
    | Version 3.3
    |--------------------------------------------------------------------------
    |
    | Logged in as customer
    |
    */


    /**
     * @throws GeneralException
     */
    public function impersonate(User $customer)
    {
        if ($customer->is_admin) {
            throw new GeneralException(__('locale.customer.admin_cannot_be_impersonated'));
        }

        $authenticatedUser = auth()->user();

        if ($authenticatedUser->id === $customer->id || Session::get('admin_user_id') === $customer->id) {
            return redirect()->route('admin.home');
        }

        if ( ! Session::get('admin_user_id')) {
            session(['admin_user_id' => $authenticatedUser->id]);
            session(['admin_user_name' => $authenticatedUser->displayName()]);
            session(['temp_user_id' => $customer->id]);

            $permissions = collect(json_decode($customer->customer->permissions, true));
            session(['permissions' => $permissions]);
        }

        //Login user
        auth()->loginUsingId($customer->id);

        return redirect(Helper::home_route());
    }
}
