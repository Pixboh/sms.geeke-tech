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
    public function up(): void
    {
        Schema::create('system_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status');
            $table->text('data')->nullable();
            $table->integer('job_id')->nullable();
            $table->unsignedBigInteger('object_id')->nullable();
            $table->string('object_name')->nullable();
            $table->dateTime('start_at')->nullable();
            $table->dateTime('end_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('system_jobs');
    }
};
