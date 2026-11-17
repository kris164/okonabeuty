<?php
declare(strict_types=1);

return [
    'origin' => 'https://lysina.info',
    'admin_token' => '',
    'storage' => 'mysql',
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'lysina',
        'user' => 'lysina',
        'password' => '',
        'ssl_ca' => '',
    ],
    'whatsapp' => [
        'access_token' => '',
        'phone_number_id' => '',
        'graph_version' => '',
        'template_name' => '',
        'template_language' => 'pl',
    ],
    'email' => [
        'enabled' => false,
        'from' => '',
        'reply_to' => '',
    ],
];
