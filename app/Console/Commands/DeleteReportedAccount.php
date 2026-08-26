<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Console\Command;

class DeleteReportedAccount extends Command
{
    protected $signature = 'moderation:delete-account {id : The users id} {--force : Skip confirmation}';

    protected $description = 'Permanently delete a user account (same live-game rules as self-service deletion)';

    public function handle(AccountDeletionService $accountDeletion): int
    {
        $id = (int) $this->argument('id');
        $user = User::find($id);
        if ($user === null) {
            $this->error("User {$id} not found.");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Delete account {$user->username} (id {$id})? This cannot be undone.")) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $accountDeletion->delete($user);
        $this->info("Account {$id} deleted.");

        return self::SUCCESS;
    }
}
