<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@allstar.test'],
            ['name' => 'Admin', 'password' => 'password', 'permissions' => User::Presets['everything']['areas']],
        );

        if (app()->environment('local')) {
            $admin->update(['permissions' => User::Presets['everything']['areas']]);
            $this->call([
                CarSeeder::class,
                CompetitorSeeder::class,
            ]);
        }
    }
}
