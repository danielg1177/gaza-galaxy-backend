<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->enum('status', ['waiting_for_players', 'in_progress', 'finished'])->default('waiting_for_players');
            $table->text('map_config_json');
            $table->longText('state_json')->nullable();
            $table->unsignedBigInteger('current_user_id')->nullable();
            $table->unsignedInteger('turn_number')->default(1);
            $table->unsignedInteger('round_number')->default(1);
            $table->unsignedBigInteger('winner_user_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamps();

            $table->foreign('current_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('winner_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
