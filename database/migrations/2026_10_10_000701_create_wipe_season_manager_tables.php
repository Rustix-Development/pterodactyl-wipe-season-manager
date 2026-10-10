<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ext_wipe_season_manager_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->string('name', 60);
            $table->string('game', 32)->default('custom');
            $table->boolean('enabled')->default(true);

            // When: the first wipe in local time, how it repeats, and the time zone it is read in.
            $table->dateTime('anchor_local');
            $table->string('timezone', 64)->default('UTC');
            $table->string('repeat', 16)->default('weekly');
            $table->unsignedSmallInteger('skip_within_hours')->nullable();
            $table->timestamp('skip_until')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();

            // What: warnings, backup, files, rotations and the Discord post.
            $table->json('warn_minutes');
            $table->string('warn_command', 255)->nullable();
            $table->boolean('backup')->default(true);
            $table->json('paths');
            $table->string('seed_variable', 191)->nullable();
            $table->string('seed_mode', 8)->default('keep');
            $table->json('seed_list');
            $table->string('map_variable', 191)->nullable();
            $table->json('map_list');
            $table->unsignedInteger('rotation_index')->default(0);
            $table->text('discord_webhook')->nullable();
            $table->string('discord_message', 1000)->nullable();

            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });

        Schema::create('ext_wipe_season_manager_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->string('plan_name', 60);
            $table->string('trigger', 16)->default('schedule');
            $table->string('status', 16)->index();
            $table->timestamp('scheduled_for');
            $table->json('warnings_sent');
            $table->boolean('was_running')->default(false);
            $table->timestamp('step_started_at')->nullable();
            $table->boolean('kill_sent')->default(false);
            $table->unsignedBigInteger('backup_id')->nullable();
            $table->unsignedInteger('files_deleted')->default(0);
            $table->string('seed', 191)->nullable();
            $table->string('map', 2048)->nullable();
            $table->json('log');
            $table->string('error', 1000)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('plan_id')->references('id')->on('ext_wipe_season_manager_plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_wipe_season_manager_runs');
        Schema::dropIfExists('ext_wipe_season_manager_plans');
    }
};
