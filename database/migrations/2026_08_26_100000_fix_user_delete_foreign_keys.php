<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
        });
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by_user_id')->nullable()->change();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('turns', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('turns', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('game_messages', function (Blueprint $table) {
            $table->dropForeign(['sender_user_id']);
        });
        Schema::table('game_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('sender_user_id')->nullable()->change();
            $table->foreign('sender_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
        });
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by_user_id')->nullable(false)->change();
            $table->foreign('created_by_user_id')->references('id')->on('users');
        });

        Schema::table('turns', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('turns', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::table('game_messages', function (Blueprint $table) {
            $table->dropForeign(['sender_user_id']);
        });
        Schema::table('game_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('sender_user_id')->nullable(false)->change();
            $table->foreign('sender_user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
