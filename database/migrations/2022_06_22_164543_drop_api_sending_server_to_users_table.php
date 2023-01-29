<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(config('database.connections.mysql.prefix').'users_api_sending_server_foreign');
            $table->dropIndex(config('database.connections.mysql.prefix').'users_api_sending_server_foreign');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreign(config('database.connections.mysql.prefix').'users_api_sending_server_foreign')->references('id')->on('sending_servers')->onDelete('cascade');
        });
    }
};
