<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** php artisan db:seed  — safe to run again; it updates instead of duplicating. */
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            MenuSeeder::class,
        ]);
    }
}
