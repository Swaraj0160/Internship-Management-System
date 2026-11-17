<?php
/**
 * InternHub — Cloudflare R2 & Object Storage Configuration Template
 * Copy this file to `config/storage_config.php` and configure your credentials.
 * DO NOT commit `config/storage_config.php` to Git or public repositories.
 */

return [
    // Storage Driver: 'local' for local secure directory staging, 'r2' for Cloudflare R2
    'driver' => getenv('STORAGE_DRIVER') ?: 'local',

    // Cloudflare R2 S3-Compatible Credentials
    // Obtain from: Cloudflare Dashboard -> R2 -> Manage R2 API Tokens
    'r2' => [
        'account_id' => getenv('R2_ACCOUNT_ID') ?: '',
        'bucket_name' => getenv('R2_BUCKET_NAME') ?: 'internhub-uploads',
        'access_key_id' => getenv('R2_ACCESS_KEY_ID') ?: '',
        'secret_access_key' => getenv('R2_SECRET_ACCESS_KEY') ?: '',
        'endpoint' => getenv('R2_ENDPOINT') ?: '', // https://<account_id>.r2.cloudflarestorage.com
        'public_asset_base_url' => getenv('R2_PUBLIC_ASSET_BASE_URL') ?: '', // https://pub-<hash>.r2.dev or custom CDN domain
        'region' => 'auto'
    ],

    // Local Storage Configuration (Secure Fallback)
    'local' => [
        'base_dir' => dirname(__DIR__) . '/uploads',
        'public_url_prefix' => '/uploads'
    ]
];
