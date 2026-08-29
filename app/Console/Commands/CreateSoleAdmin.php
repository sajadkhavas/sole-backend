<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateSoleAdmin extends Command
{
    protected $signature = 'sole:admin:create {email : Administrator email} {--name= : Administrator display name}';

    protected $description = 'Create an inactive SOLE administrator using an interactively entered password';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (User::query()->where('email', $email)->exists()) {
            $this->error('A user with this email already exists.');

            return self::FAILURE;
        }

        $password = $this->secret('Password (minimum 12 characters)');
        $confirmation = $this->secret('Confirm password');

        if (! is_string($password) || strlen($password) < 12 || ! hash_equals($password, (string) $confirmation)) {
            $this->error('Password requirements or confirmation failed.');

            return self::FAILURE;
        }

        User::query()->create([
            'name' => (string) ($this->option('name') ?: $email),
            'email' => $email,
            'password' => Hash::make($password),
            'is_active' => false,
        ]);

        $this->info('Inactive administrator created. Run sole:admin:grant explicitly to grant access.');

        return self::SUCCESS;
    }
}
