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
        Schema::create('game_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('game_id');
            $table->unsignedBigInteger('sender_user_id');
            $table->text('content');
            $table->timestamps();
            $table->foreign('game_id')->references('id')->on('games')->cascadeOnDelete();
            $table->foreign('sender_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('game_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_messages');
    }
};
