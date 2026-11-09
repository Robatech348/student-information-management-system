<?php
/**
 * UniSphere – core configuration.
 * Override any value in config/config.local.php (not committed) instead of editing this file.
 */
return [
    'app_name'        => 'UniSphere',
    'app_tagline'     => 'University Management & Intelligence System',
    'vendor'          => 'Tech Dynamic Enterprises',
    'timezone'        => 'Africa/Nairobi',
    'debug'           => true,

    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'unisphere',
        'user' => 'root',
        'pass' => '',
    ],

    'session' => [
        'name'            => 'unisphere_sid',
        'idle_timeout'    => 30 * 60,   // seconds of inactivity before forced logout
    ],

    'security' => [
        'max_failed_logins_per_email' => 5,    // within the lockout window
        'max_failed_logins_per_ip'    => 20,
        'lockout_window_minutes'      => 15,
        'min_password_length'         => 10,
    ],

    'uploads' => [
        'max_document_bytes' => 5 * 1024 * 1024,
        'max_photo_bytes'    => 2 * 1024 * 1024,
        'document_mimes'     => [
            'application/pdf' => 'pdf',
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/msword' => 'doc',
        ],
        'photo_mimes' => [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ],
    ],
];
