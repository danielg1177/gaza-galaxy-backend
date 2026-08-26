<?php

namespace App\Console\Commands;

use App\Models\GameMessage;
use App\Models\MessageReport;
use Illuminate\Console\Command;

class HideReportedMessage extends Command
{
    protected $signature = 'moderation:hide-message {id : The game_messages id}';

    protected $description = 'Hide a reported game chat message so it no longer appears in conversations';

    public function handle(): int
    {
        $id = (int) $this->argument('id');
        $message = GameMessage::find($id);
        if ($message === null) {
            $this->error("Message {$id} not found.");

            return self::FAILURE;
        }

        $message->update(['hidden_at' => now()]);
        MessageReport::where('message_id', $id)
            ->where('status', 'open')
            ->update(['status' => 'actioned']);

        $this->info("Message {$id} is hidden.");

        return self::SUCCESS;
    }
}
