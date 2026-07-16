<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DashboardUser extends Command
{
    protected $signature = 'dashboard:user {email} {--name=} {--password= : Ak nezadáš, vygeneruje sa náhodné}';

    protected $description = 'Create or update a dashboard user';

    public function handle(): int
    {
        $email = $this->argument('email');
        $password = $this->option('password') ?: Str::password(16);

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $this->option('name') ?: Str::before($email, '@'),
                'password' => $password,
            ]
        );

        $this->info("Používateľ {$email} pripravený.");
        if (!$this->option('password')) {
            $this->line("Vygenerované heslo: {$password}");
        }

        return self::SUCCESS;
    }
}
