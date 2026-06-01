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
        Schema::create('turns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('game_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('turn_number');
            $table->unsignedInteger('round_number');
            $table->text('in_progress_actions_json')->nullable();
            $table->text('submitted_actions_json')->nullable();
            $table->longText('resulting_state_json')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'user_id', 'turn_number', 'round_number']);
            $table->foreign('game_id')->references('id')->on('games')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('turns');
    }
};
