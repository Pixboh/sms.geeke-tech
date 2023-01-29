<?php

/** @var Factory $factory */

use App\Models\Reports;
use Illuminate\Database\Eloquent\Factory;
use Faker\Generator as Faker;

/*
|--------------------------------------------------------------------------
| Model Factories
|--------------------------------------------------------------------------
|
| This directory should contain each of the model factory definitions for
| your application. Factories provide a convenient way to generate new
| model instances for testing / seeding your application's database.
|
*/

$factory->define(Reports::class, function (Faker $faker) {
    return [
            'uid'               => uniqid(),
            'user_id'           => 3,
            'from'              => $faker->e164PhoneNumber(),
            'to'                => $faker->e164PhoneNumber(),
            'message'           => $faker->text,
            'sms_type'          => 'plain',
            'status'            => 'Delivered',
            'send_by'           => 'to',
            'cost'              => '1',
            'sending_server_id' => 3,
            'created_at'        => $faker->dateTime,
            'updated_at'        => $faker->dateTime,
    ];
});
