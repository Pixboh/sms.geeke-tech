<?php

namespace Database\Seeders;


use Illuminate\Database\Seeder;
use App\Models\Language;

class LanguageSeeder extends Seeder
{
    /**
     * Run the database seeders.
     *
     * @return void
     */
    public function run() {

        Language::truncate();

        $get_language = [
            [
                'name' => 'Français',
                'code' => 'fr',
                'iso_code' => 'fr',
                'status' => true,
            ],
            [
                'name' => 'English',
                'code' => 'en',
                'iso_code' => 'us',
                'status' => true,
            ],

            [
                'name' => 'Chinese',
                'code' => 'zh',
                'iso_code' => 'cn',
                'status' => false,
            ],
            [
                'name' => 'Spanish',
                'code' => 'es',
                'iso_code' => 'es',
                'status' => false,
            ],

            [
                'name' => 'Portuguese',
                'code' => 'pt',
                'iso_code' => 'br',
                'status' => false,
            ],
            [
                'name' => 'Arabic',
                'code' => 'ar',
                'iso_code' => 'sa',
                'status' => false,
            ],
            [
                'name' => 'Italian',
                'code' => 'it',
                'iso_code' => 'it',
                'status' => false,
            ],
            [
                'name' => 'Korean',
                'code' => 'ko',
                'iso_code' => 'kr',
                'status' => false,
            ],
            [
                'name' => 'Slovenian',
                'code' => 'sl',
                'iso_code' => 'sk',
                'status' => false,
            ],
        ];

        foreach ($get_language as $lan) {
            Language::create($lan);
        }
    }

}
