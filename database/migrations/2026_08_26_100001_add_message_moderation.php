<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_messages', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable()->after('content');
        });

        Schema::create('message_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('game_id');
            $table->unsignedBigInteger('message_id');
            $table->unsignedBigInteger('reporter_user_id')->nullable();
            $table->unsignedBigInteger('reported_user_id')->nullable();
            $table->text('content_snapshot');
            $table->string('sender_username_snapshot', 30)->nullable();
            $table->string('game_name_snapshot', 100);
            $table->string('reason', 200)->nullable();
            $table->enum('status', ['open', 'actioned', 'dismissed'])->default('open');
            $table->timestamps();

            $table->unique(['reporter_user_id', 'message_id']);
            $table->foreign('game_id')->references('id')->on('games')->cascadeOnDelete();
            $table->foreign('message_id')->references('id')->on('game_messages')->cascadeOnDelete();
            $table->foreign('reporter_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reported_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reports');

        Schema::table('game_messages', function (Blueprint $table) {
            $table->dropColumn('hidden_at');
        });
    }
};
