<?php

namespace Database\Seeders;

use App\Models\ZendyUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ZendyAdminSeeder extends Seeder
{
    public function run(): void
    {
        ZendyUser::firstOrCreate(
            ['email' => env('ZENDY_ADMIN_EMAIL', 'zendy-admin@library.local')],
            [
                'fname' => 'Zendy',
                'lname' => 'Admin',
                'password' => Hash::make(env('ZENDY_ADMIN_PASSWORD', 'password')),
                'role' => 'admin',
            ]
        );
    }
}
