<?php

namespace App\Console\Commands;

use App\Models\AppConfig;
use App\Models\Senderid;
use App\Models\User;
use App\Notifications\SenderIDConfirmation;
use App\Notifications\SubscriptionPurchase;
use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class UpdateSenderIDFromOrange extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'senderid_orange:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check Sender id validation by Orange exp';

    protected CookieJar $cookies;

    protected Client $client;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct() {

        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int {
        $cookies = [];
        if (Cache::has('orangesmspro_cookies')) {
            $cookies = Cache::get('orangesmspro_cookies', []);
            $this->cookies = CookieJar::fromArray($cookies, 'www.orangesmspro.sn');
        } else {
            $this->cookies = new CookieJar;
        }
        $this->client = new Client(array(
            'cookies' => $this->cookies,
            'verify' => false,
            'allow_redirects' => [
                'max' => 1,
            ]
        ));

        if (empty($cookies)) {
            $this->getInitialCookies();
            $loginResponse = $this->login(env("ORANGESMSPRO_LOGIN"), env("ORANGESMSPRO_PASSWORD"));
            if ($loginResponse->getStatusCode() == 200) {
                $loginBody = json_decode($loginResponse->getBody()->getContents());
                Cache::put('orangesmspro_customer_id', $loginBody->infos->customer);
                Cache::put('orangesmspro_user_id', $loginBody->infos->id);

                $homeResponse = $this->homepage(env("ORANGESMSPRO_LOGIN"), env("ORANGESMSPRO_PASSWORD"));
                if ($homeResponse->getStatusCode() !== 200) {
                    throw new Exception("User ID not match");
                }

                $this->hibernateCookies();
            }
        }
        try {
            $customerID = Cache::get('orangesmspro_customer_id') . "";
            $userID = Cache::get('orangesmspro_user_id') . "";
            $getValidSignatures = $this->getValidSignatures($userID, $customerID);

            $valid_signatures = json_decode($getValidSignatures->getBody()->getContents());
            // get list signature
            $signaturesResponse = $this->getSignatures($customerID);
            $signatures = json_decode($signaturesResponse->getBody()->getContents());
            if ($signatures) {
                $pending_sender_ids = Senderid::where('status', 'pending')->latest()->cursor();
                foreach ($pending_sender_ids as $pending_sender_id) {
                    $remote_signature = array_filter($signatures, function ($signature) use ($pending_sender_id) {
                        return $signature->wording == $pending_sender_id->sender_id;
                    });
                    if ($remote_signature) {
                        $remote_signature = reset($remote_signature);
                        if ($remote_signature->activate) {
                            // activate local signature
                            $pending_sender_id->update([
                                'status' => 'active',
                            ]);
                            // notify user by sms and add notification for admin
                            $user = User::find($pending_sender_id->user_id);
                            $admin = User::find(1);
                            $admin->notify(new SenderIDConfirmation($pending_sender_id->status, route('customer.senderid.index')));
                            if ($user->customer->getNotifications()['sender_id'] == 'yes') {
                                $user->notify(new SenderIDConfirmation($pending_sender_id->status, route('customer.senderid.index')));
                                // notify by sms
                                $message = trans('locale.sms_notifications.sender_id_activation',
                                    ['sender_id' => $pending_sender_id->sender_id, 'url' => env('FRONT_URL'),
                                        'name' => $user->displayName()]);
                                try {
                                    $user->customer->sendSMS($message, $pending_sender_id->sender_id);
                                } catch (\Exception $e) {
                                    logger("SenderIDSMSNotification : " . $e->getMessage());
                                }
                            }
                        } elseif ($remote_signature->reasonrejeted) {
                            // deactivate local signature
                            $pending_sender_id->update([
                                'status' => 'block',
                            ]);
                            // notify by sms
                            $user = User::find($pending_sender_id->user_id);
                            $admin = User::find(1);
                            $admin->notify(new SenderIDConfirmation($pending_sender_id->status, route('customer.senderid.index')));
                            if ($user->customer->getNotifications()['sender_id'] == 'yes') {
                                $user->notify(new SenderIDConfirmation($pending_sender_id->status, route('customer.senderid.index')));
                                // notify by sms
                                $message = trans('locale.sms_notifications.sender_id_rejected',
                                    ['sender_id' => $pending_sender_id->sender_id, 'url' => env('FRONT_URL'),
                                        'name' => $user->displayName()]);
                                try {
                                    $user->customer->sendSMS($message, $pending_sender_id->sender_id);
                                } catch (\Exception $e) {
                                    logger("SenderIDSMSNotification : " . $e->getMessage());
                                }
                            }
                        } else {
                            // do nothing
                        }
                    } else {
                        // create a new remote signature
                        $getSession = $this->getSession();
                        $getSessionResponse = json_decode($getSession->getBody()->getContents());


                        // 1 signature
                        $createSignature = $this->createSignature($customerID, $userID, $pending_sender_id->sender_id);
                        $createSignatureResponse = json_decode($createSignature->getBody()->getContents());

                        $signatureID = $createSignatureResponse->oId;
                        // 2 get session
                        $getSession = $this->getSession();
                        $getSessionResponse = json_decode($getSession->getBody()->getContents());

                        // 3 view signature
                        $viewSignature = $this->viewSignature($signatureID);
                        $viewSignatureResponse = json_decode($viewSignature->getBody()->getContents());

                        // 4 loademail partner
                        $postPartner = $this->postPartner();
                        $postPartnerResponse = json_decode($postPartner->getBody()->getContents());
                        if (!$postPartnerResponse || $postPartner->getStatusCode() != 200) {
                            logger("ORANGESN_LOGS_ERROR : " . $postPartnerResponse);
                            throw new Exception("Partner creation failed");
                        } else {
                            $alertMails = $postPartnerResponse->alertMail;

                        }

                        // 5 view customer
                        $viewCustomer = $this->viewCustomer($customerID);
                        $viewCustomerResponse = json_decode($viewCustomer->getBody()->getContents());

                        // 6 sendmail

                        if (isset($alertMails)) {
                            $sendMail = $this->sendMail($alertMails);
                            $sendMailResponse = json_decode($sendMail->getBody()->getContents());
                        }
                    }

                }
            }
        } catch
        (Exception $e) {
            logger("ORANGESN_LOGS : " . $e->getMessage());
            // TODO send alert login failed by mail or sms
            Cache::delete('orangesmspro_cookies');
            unset($this->cookies);
            unset($this->client);
            return 0;
        }

        $this->hibernateCookies();
        return 0;
    }

    function getSignatures($customerID) {
        $headers = [
            'Accept' => '*/*',
            'Accept-Language' => 'fr-SN,fr;q=0.9,en-GB;q=0.8,en-US;q=0.7,en;q=0.6',
            'Connection' => 'keep-alive',
            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            'Origin' => 'https://www.orangesmspro.sn',
            'Referer' => 'https://www.orangesmspro.sn/main.php',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36',
            'X-Requested-With' => 'XMLHttpRequest',
            'sec-ch-ua' => '"Chromium";v="110", "Not A(Brand";v="24", "Google Chrome";v="110"',
            'sec-ch-ua-mobile' => '?0',
            'sec-ch-ua-platform' => '"macOS"'
        ];
        $body = 'customerId=' . $customerID . '&ACTION=LIST';
        $request = new Request('POST', 'https://www.orangesmspro.sn/src/bo/customer/SignatureController.php', $headers, $body);
        $res = $this->client->sendAsync($request)->wait();
        return $res;
    }

    function login($username, $password) {

        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];
        $options = [
            'form_params' => [
                'partner' => '1',
                'login' => $username,
                'password' => md5($password),
                'captcha' => '',
                'ACTION' => 'SIGNIN'
            ]];
        $request = new Request('POST', 'https://www.orangesmspro.sn/src/bo/customer/UserController.php', $headers);
        /** @var Response $res */
        $res = $this->client->sendAsync($request, $options)->wait();
        return $res;

    }

    function getSession() {

        $headers = [
            'Accept' => '*/*',
            'Accept-Language' => 'fr-SN,fr;q=0.9,en-GB;q=0.8,en-US;q=0.7,en;q=0.6',
            'Connection' => 'keep-alive',
            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            'Origin' => 'https://www.orangesmspro.sn',
            'Referer' => 'https://www.orangesmspro.sn/main.php',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36',
            'X-Requested-With' => 'XMLHttpRequest',
            'sec-ch-ua' => '"Chromium";v="110", "Not A(Brand";v="24", "Google Chrome";v="110"',
            'sec-ch-ua-mobile' => '?0',
            'sec-ch-ua-platform' => '"macOS"',
        ];
        $body = 'ACTION=GET_SESSION';
        $request = new Request('POST', 'https://www.orangesmspro.sn/src/bo/customer/CustomerController.php', $headers, $body);
        $res = $this->client->sendAsync($request)->wait();
        if (!$res || $res->getStatusCode() != 200) {
            logger("ORANGESN_LOGS_ERROR : " . $res);
            throw new Exception("Session creation failed");
        }
        return $res;
    }

    function getInitialCookies() {
        $headers = [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'Accept-Language' => 'fr-SN,fr;q=0.9,en-GB;q=0.8,en-US;q=0.7,en;q=0.6',
            'Cache-Control' => 'max-age=0',
            'Connection' => 'keep-alive',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Origin' => 'https://www.orangesmspro.sn',
            'Referer' => 'https://www.orangesmspro.sn/',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'same-origin',
            'Upgrade-Insecure-Requests' => '1',
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36',
            'sec-ch-ua' => '"Chromium";v="110", "Not A(Brand";v="24", "Google Chrome";v="110"',
            'sec-ch-ua-mobile' => '?0',
            'sec-ch-ua-platform' => '"macOS"'
        ];
        $options = [
            'form_params' => [
                'domain' => 'orangesmspro.sn',
                'subDomain' => 'www.orangesmspro.sn'
            ]];
        $request = new Request('POST', 'https://www.orangesmspro.sn/cookies.php', $headers);
        $res = $this->client->sendAsync($request, $options)->wait();
        if (!$res || $res->getStatusCode() != 200) {
            logger("ORANGESN_LOGS_ERROR : " . $res);
            throw new Exception("Cookies creation failed");
        }
        return $res;
    }

    function hibernateCookies() {
        $cookies = $this->cookies->toArray();
        $cookie_name_values = [];
        foreach ($cookies as $cookie) {
            $cookie_name_values[$cookie['Name']] = $cookie['Value'];
        }
        Cache::put('orangesmspro_cookies', $cookie_name_values);
    }


    function getValidSignatures($userID, $customer_id) {

        $headers = [
            'Accept' => '*/*',
            'Accept-Language' => 'fr-SN,fr;q=0.9,en-GB;q=0.8,en-US;q=0.7,en;q=0.6',
            'Connection' => 'keep-alive',
            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            'Origin' => 'https://www.orangesmspro.sn',
            'Referer' => 'https://www.orangesmspro.sn/main.php',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36',
            'X-Requested-With' => 'XMLHttpRequest',
            'sec-ch-ua' => '"Chromium";v="110", "Not A(Brand";v="24", "Google Chrome";v="110"',
            'sec-ch-ua-mobile' => '?0',
            'sec-ch-ua-platform' => '"macOS"',
        ];
        $body = 'userId=' . $userID . '&customerId=' . $customer_id . '&ACTION=LIST_VALID';
        $request = new Request('POST', 'https://www.orangesmspro.sn/src/bo/customer/SignatureController.php', $headers, $body);
        $res = $this->client->sendAsync($request)->wait();
        if (!$res || $res->getStatusCode() != 200) {
            logger("ORANGESN_LOGS_ERROR : " . $res);
            throw new Exception("Signatures list failed");
        }

        return $res;
    }

    function homepage($login, $password) {

        $headers = [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'Accept-Language' => 'fr-SN,fr;q=0.9,en-GB;q=0.8,en-US;q=0.7,en;q=0.6',
            'Cache-Control' => 'max-age=0',
            'Connection' => 'keep-alive',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Origin' => 'https://www.orangesmspro.sn',
            'Referer' => 'https://www.orangesmspro.sn/signin-lp.php',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'same-origin',
            'Sec-Fetch-User' => '?1',
            'Upgrade-Insecure-Requests' => '1',
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36',
            'sec-ch-ua' => '"Chromium";v="110", "Not A(Brand";v="24", "Google Chrome";v="110"',
            'sec-ch-ua-mobile' => '?0',
            'sec-ch-ua-platform' => '"macOS"'
        ];
        $options = [
            'form_params' => [
                'login' => $login,
                'password' => md5($password),
                'captcha' => '0',
                'subDomain' => 'www.orangesmspro.sn'
            ]];
        $request = new Request('POST', 'https://www.orangesmspro.sn/homepage.php', $headers);
        $res = $this->client->sendAsync($request, $options)->wait();

        if (!$res || $res->getStatusCode() != 200) {
            logger("ORANGESN_LOGS_ERROR : " . $res);
            throw new Exception("Homepage failed");
        }
        return $res;
    }

    function subscribtion($customer_ID) {
        $headers = [
            'Accept' => 'application/json, text/javascript, */*; q=0.01',
            'Accept-Language' => 'fr-SN,fr;q=0.9,en-GB;q=0.8,en-US;q=0.7,en;q=0.6',
            'Connection' => 'keep-alive',
            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            'Origin' => 'https://www.orangesmspro.sn',
            'Referer' => 'https://www.orangesmspro.sn/applications.php',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36',
            'X-Requested-With' => 'XMLHttpRequest',
            'sec-ch-ua' => '"Chromium";v="110", "Not A(Brand";v="24", "Google Chrome";v="110"',
            'sec-ch-ua-mobile' => '?0',
            'sec-ch-ua-platform' => '"macOS"',
        ];
        $body = 'customerId=' . $customer_ID . '&ACTION=LIST';
        $request = new Request('POST', 'https://www.orangesmspro.sn/src/bo/common/SubscriptionController.php', $headers, $body);
        $res = $this->client->sendAsync($request)->wait();

        if (!$res || $res->getStatusCode() != 200) {
            logger("ORANGESN_LOGS_ERROR : " . $res);
            throw new Exception("Subscribtion failed");
        }

        return $res;
    }

    function createSignature($customer_ID, $userID, $signature) {
        $headers = [
            'Accept' => 'application/json, text/javascript, */*; q=0.01',
            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            'Origin' => 'https://www.orangesmspro.sn',
            'Referer' => 'https://www.orangesmspro.sn/main.php',
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36'
        ];
        $options = [
            'form_params' => [
                'userId' => $userID,
                'customerId' => $customer_ID,
                'ACTION' => 'INSERT',
                'libellesignature' => $signature
            ]];
        $request = new Request('POST', 'https://www.orangesmspro.sn/src/bo/customer/SignatureController.php', $headers);
        $res = $this->client->sendAsync($request, $options)->wait();
        if (!$res || $res->getStatusCode() != 200) {
            logger("ORANGESN_LOGS_ERROR : " . $res);
            throw new Exception("Create signature failed");
        }

        return $res;
    }


    function viewSignature($signature_ID) {

        $headers = [
            'Accept' => '*/*',
            'Accept-Language' => 'fr-SN,fr;q=0.9,en-GB;q=0.8,en-US;q=0.7,en;q=0.6',
            'Connection' => 'keep-alive',
            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            'Origin' => 'https://www.orangesmspro.sn',
            'Referer' => 'https://www.orangesmspro.sn/alertsms.php',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36',
            'X-Requested-With' => 'XMLHttpRequest',
            'sec-ch-ua' => '"Chromium";v="110", "Not A(Brand";v="24", "Google Chrome";v="110"',
            'sec-ch-ua-mobile' => '?0',
            'sec-ch-ua-platform' => '"macOS"'
        ];
        $body = 'signatureId=' . $signature_ID . '&ACTION=VIEW';
        $request = new Request('POST', 'https://www.orangesmspro.sn/src/bo/customer/SignatureController.php', $headers, $body);
        $res = $this->client->sendAsync($request)->wait();
        if (!$res || $res->getStatusCode() != 200) {
            logger("ORANGESN_LOGS_ERROR : " . $res);
            throw new Exception("View signature failed");
        }
        return $res;

    }

    function postPartner() {

        $headers = [
            'Accept' => '*/*',
            'Accept-Language' => 'fr-SN,fr;q=0.9,en-GB;q=0.8,en-US;q=0.7,en;q=0.6',
            'Connection' => 'keep-alive',
            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            'Origin' => 'https://www.orangesmspro.sn',
            'Referer' => 'https://www.orangesmspro.sn/main.php',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36',
            'X-Requested-With' => 'XMLHttpRequest',
            'sec-ch-ua' => '"Chromium";v="110", "Not A(Brand";v="24", "Google Chrome";v="110"',
            'sec-ch-ua-mobile' => '?0',
            'sec-ch-ua-platform' => '"macOS"'
        ];
        $body = 'partnerId=1&ACTION=LOADEMAIL';
        $request = new Request('POST', 'https://www.orangesmspro.sn/src/bo/common/PartnerController.php', $headers, $body);
        $res = $this->client->sendAsync($request)->wait();
        if (!$res || $res->getStatusCode() != 200) {
            logger("ORANGESN_LOGS_ERROR : " . $res);
            throw new Exception("Post partner failed");
        }
        return $res;
    }

    function viewCustomer($customerID) {

        $headers = [
            'Accept' => '*/*',
            'Accept-Language' => 'fr-SN,fr;q=0.9,en-GB;q=0.8,en-US;q=0.7,en;q=0.6',
            'Connection' => 'keep-alive',
            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            'Origin' => 'https://www.orangesmspro.sn',
            'Referer' => 'https://www.orangesmspro.sn/main.php',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36',
            'X-Requested-With' => 'XMLHttpRequest',
            'sec-ch-ua' => '"Chromium";v="110", "Not A(Brand";v="24", "Google Chrome";v="110"',
            'sec-ch-ua-mobile' => '?0',
            'sec-ch-ua-platform' => '"macOS"'
        ];
        $body = 'partnerId=1&customerId=' . $customerID . '&ACTION=VIEW';
        $request = new Request('POST', 'https://www.orangesmspro.sn/src/bo/customer/CustomerController.php', $headers, $body);
        $res = $this->client->sendAsync($request)->wait();
        if (!$res || $res->getStatusCode() != 200) {
            logger("ORANGESN_LOGS_ERROR : " . $res);
            throw new Exception("View customer failed");
        }
        return $res;
    }

    function sendMail($emails) {

        $headers = [
            'Accept' => '*/*',
            'Accept-Language' => 'fr-SN,fr;q=0.9,en-GB;q=0.8,en-US;q=0.7,en;q=0.6',
            'Connection' => 'keep-alive',
            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            'Origin' => 'https://www.orangesmspro.sn',
            'Referer' => 'https://www.orangesmspro.sn/main.php',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36',
            'X-Requested-With' => 'XMLHttpRequest',
            'sec-ch-ua' => '"Chromium";v="110", "Not A(Brand";v="24", "Google Chrome";v="110"',
            'sec-ch-ua-mobile' => '?0',
            'sec-ch-ua-platform' => '"macOS"'
        ];
        $body = 'message=Vous+avez+une+nouvelle+demande+de+validation+du+client+STE+GEE+KE&subject=Demande+de+validation+de+signature&email=';
//        stephanejulessanka.nzale%40orange-sonatel.com%3Bvivien.biamou%40orange-sonatel.com%3Bsupport-2smobile%40kiwi.sn%3Bpathe.gueye%40kiwi.sn';
        $body .= urlencode($emails);
        $request = new Request('POST', 'https://www.orangesmspro.sn/app/mail/SendMail.php', $headers, $body);
        $res = $this->client->sendAsync($request)->wait();
        if (!$res || $res->getStatusCode() != 200) {
            logger("ORANGESN_LOGS_ERROR : " . $res);
            throw new Exception("Send mail failed");
        }
        return $res;

    }
}
