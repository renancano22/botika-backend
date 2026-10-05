<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Creates the real administrator account of the Botika ng Bayan.
| Run once after setting up the database:  php artisan botika:create-admin
| Pharmacy staff accounts are then created by the administrator in "User Accounts".
*/
Artisan::command('botika:create-admin', function () {
    $name = trim((string) $this->ask('Full name of the administrator'));
    $email = strtolower(trim((string) $this->ask('Email address (used to log in)')));
    $password = (string) $this->secret('Password (at least 8 characters)');
    $confirm = (string) $this->secret('Type the password again');

    $validator = \Illuminate\Support\Facades\Validator::make(
        ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirm],
        [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]
    );

    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }
        return 1;
    }

    \App\Models\User::create([
        'name' => $name,
        'email' => $email,
        'password_hash' => $password,
        'role' => \App\Models\User::ROLE_ADMIN,
    ]);

    $this->info("Administrator account created for {$email}. You can now log in.");
    return 0;
})->purpose('Create the BulanBotikaCare administrator account');
