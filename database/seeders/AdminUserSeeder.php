<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make(env('ADMIN_PASSWORD', 'password'));

        User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@library.local')],
            [
                'fname' => 'System',
                'lname' => 'Admin',
                'password' => $password,
                'role' => 'admin',
            ]
        );

        User::updateOrCreate(
            ['email' => env('SHS_ADMIN_EMAIL', 'shs-admin@library.local')],
            [
                'fname' => 'SHS',
                'lname' => 'Admin',
                'password' => $password,
                'role' => 'shs_admin',
            ]
        );

        User::updateOrCreate(
            ['email' => env('K10_ADMIN_EMAIL', 'k10-admin@library.local')],
            [
                'fname' => 'K10',
                'lname' => 'Admin',
                'password' => $password,
                'role' => 'k10_admin',
            ]
        );

        $this->command?->info('Admin accounts ready (password from ADMIN_PASSWORD or “password”):');
        $this->command?->line('  Superadmin:  '.env('ADMIN_EMAIL', 'admin@library.local'));
        $this->command?->line('  SHS Admin:   '.env('SHS_ADMIN_EMAIL', 'shs-admin@library.local').'  → Grade 11–12 only');
        $this->command?->line('  K–10 Admin:  '.env('K10_ADMIN_EMAIL', 'k10-admin@library.local').'  → Kinder–Grade 10 only');
    }
}
