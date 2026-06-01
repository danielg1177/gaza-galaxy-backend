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
        Schema::create('game_players', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('game_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedTinyInteger('turn_order');
            $table->boolean('is_ai')->default(false);
            $table->string('home_planet_id', 50)->nullable();
            $table->boolean('is_eliminated')->default(false);
            $table->timestamps();

            $table->unique(['game_id', 'turn_order']);
            $table->foreign('game_id')->references('id')->on('games')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_players');
    }
};
