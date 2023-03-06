<?php

use App\Http\Controllers\LanguageController;
use App\Library\Tool;
use App\Models\AppConfig;
use App\Models\Campaigns;
use App\Models\EmailTemplates;
use App\Models\PaymentMethods;
use Database\Seeders\Countries;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {

    if (config('app.stage') == 'new') {
        return redirect('install');
    }

    if (config('app.stage') == 'Live' && config('app.version') == '3.3.0') {
        return redirect('update');
    }

    return redirect('login');
});

// locale Route
Route::get('lang/{locale}', [LanguageController::class, 'swap']);
Route::any('languages', [LanguageController::class, 'languages'])->name('languages');

if (config('app.stage') == 'local') {
    Route::get('run-campaign', function () {

        $campaign = Campaigns::find(4);
        $campaign?->run();
    });

    Route::get('get-contacts', function () {

        $campaign = Campaigns::find(1);
        if ($campaign) {
            $campaign->getContactList();
        }
    });

    Route::get('update-file', function (BufferedOutput $outputLog) {
        $app_path = base_path().'/bootstrap/cache/';
        if (File::isDirectory($app_path)) {
            File::cleanDirectory($app_path);
        }

        Artisan::call('optimize:clear');
        Artisan::call('migrate', ['--force' => true], $outputLog);
        Tool::versionSeeder(config('app.version'));

        AppConfig::setEnv('APP_VERSION', '3.4.0');

        return redirect()->route('login')->with([
                'status'  => 'success',
                'message' => 'You have successfully updated your application.',
        ]);
    });

    Route::get('update-country', function () {
        $countries = new Countries();
        $countries->run();
    });

    Route::get('debug', function () {

        return 'success';

    });


    Route::get('update-demo', function () {
        Artisan::call('demo:update');

        return 'Demo Updated';
    });

}


Route::get('/version-seeder', function () {
    Tool::versionSeeder('3.3.0');
});

Route::get('/clear', function () {

    Artisan::call('optimize:clear');

    return "Cleared!";

});
Route::get('/s/{shortURLKey}', '\AshAllenDesign\ShortURL\Controllers\ShortURLController');
