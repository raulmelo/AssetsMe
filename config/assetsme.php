<?php

return [
    'disk' => env('ASSETS_DISK', 'assets'),
    'base_url' => env('ASSETS_BASE_URL', env('APP_URL').'/assets'),
    'max_file_size' => (int) env('ASSETS_MAX_FILE_SIZE', 10 * 1024 * 1024),
    'variants' => [
        'small' => ['width' => 200, 'height' => 300],
        'medium' => ['width' => 500, 'height' => 500],
        'large' => ['width' => 1500, 'height' => 1200],
    ],
    'max_width' => (int) env('ASSETS_MAX_WIDTH', 4000),
    'max_height' => (int) env('ASSETS_MAX_HEIGHT', 4000),
];
