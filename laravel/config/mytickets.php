<?php

declare(strict_types=1);

return [
    'data_dir' => env('DATA_DIR'),
    'sqlite_file' => env('SQLITE_FILE', 'mytickets.sqlite'),
    'seed_email' => env('SEED_USER_EMAIL'),
    'seed_name' => env('SEED_USER_NAME'),
    'seed_password' => env('SEED_USER_PASSWORD'),
    'cookie' => 'mt_session',
];
