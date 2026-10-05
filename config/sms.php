<?php

/*
| SMS notification settings (Semaphore - https://semaphore.co).
| If SEMAPHORE_API_KEY is empty, messages are written to storage/logs/laravel.log
| and saved with status "logged" instead of being sent.
*/
return [
    'semaphore' => [
        'api_key' => env('SEMAPHORE_API_KEY'),
        'sender_name' => env('SEMAPHORE_SENDER_NAME'),
        'url' => env('SEMAPHORE_URL', 'https://api.semaphore.co/api/v4/messages'),
    ],
];
