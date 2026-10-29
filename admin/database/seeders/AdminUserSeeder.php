<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@whitemessenger.local');

        if (User::query()->where('email', $email)->exists()) {
            $this->command?->info("Admin user {$email} already exists — password unchanged.");

            return;
        }

        $password = env('ADMIN_PASSWORD') ?: Str::password(14, symbols: false);

        User::query()->create([
            'name' => env('ADMIN_NAME', 'Admin'),
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->command?->warn('Admin login created:');
        $this->command?->line("  Email:    {$email}");
        $this->command?->line(env('ADMIN_PASSWORD') ? '  Password: (the ADMIN_PASSWORD from .env)' : "  Password: {$password}   ← note this down now");
    }
}
