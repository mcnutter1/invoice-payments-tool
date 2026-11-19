<?php

return [

    'auth' => [
        // 'mcnutt' (production) or 'dev' (local only; refused when APP_ENV=production).
        'driver' => env('AUTH_DRIVER', 'mcnutt'),
        'login_base' => rtrim((string) env('AUTH_LOGIN_BASE', 'https://login.mcnutt.cloud'), '/'),
        'app_id' => env('AUTH_APP_ID'),
        'app_secret' => env('AUTH_APP_SECRET'),
        // Placeholders: {login_base} {app_id} {return_url} {token}. Defaults match the official client helper.
        'login_url_template' => env('AUTH_LOGIN_URL_TEMPLATE', '{login_base}/?return_url={return_url}&app_id={app_id}'),
        'logout_url_template' => env('AUTH_LOGOUT_URL_TEMPLATE', '{login_base}/logout_confirm.php?token={token}&return_url={return_url}'),
        'validate_endpoint' => env('AUTH_VALIDATE_ENDPOINT', 'https://login.mcnutt.cloud/api/validate.php'),
        'expected_issuer' => env('AUTH_EXPECTED_ISSUER', 'https://login.mcnutt.cloud'),
        'revalidate_seconds' => (int) env('AUTH_REVALIDATE_SECONDS', 300),
        'callback_max_age_seconds' => (int) env('AUTH_CALLBACK_MAX_AGE_SECONDS', 300),
        'validate_on_callback' => (bool) env('AUTH_VALIDATE_ON_CALLBACK', true),
        // Identity emails are not documented as verified; invitations then require owner approval.
        'email_is_verified' => (bool) env('AUTH_EMAIL_IS_VERIFIED', false),
        // 'all' = any authenticated app user may create a business; 'mapped' = needs a central role mapping.
        'business_creation' => env('AUTH_BUSINESS_CREATION', 'all'),
        'allowed_principal_types' => ['user'],
        'http_timeout' => 8,
    ],

    'notifications' => [
        'enabled' => (bool) env('NOTIFICATIONS_ENABLED', true),
        // Fixed allowlisted origin; the path is not configurable.
        'base_url' => rtrim((string) env('NOTIFICATIONS_BASE_URL', 'https://notifications.mcnutt.cloud'), '/'),
        'allowed_hosts' => ['notifications.mcnutt.cloud'],
        'api_token' => env('NOTIFICATIONS_API_TOKEN'),
        'provider' => env('NOTIFICATIONS_PROVIDER'),
        'default_from_email' => env('NOTIFICATIONS_FROM_EMAIL'),
        // Comma list of sender addresses approved on the notification service that businesses may use.
        'approved_senders' => array_values(array_filter(array_map('trim', explode(',', (string) env('NOTIFICATIONS_APPROVED_SENDERS', ''))))),
        // 'mcnutt' (real) or 'log' (non-production only, writes to the log, never claims delivery).
        'driver' => env('NOTIFICATIONS_DRIVER', 'mcnutt'),
        'timeout' => 15,
    ],

    'storage' => [
        'private_root' => env('FINANCE_PRIVATE_STORAGE', storage_path('app/private')),
        'max_upload_kb' => (int) env('FINANCE_MAX_UPLOAD_KB', 10240),
    ],

    'links' => [
        'invoice_ttl_days' => 90,
        'contract_ttl_days' => 30,
        'max_ttl_days' => 365,
        'min_ttl_days' => 1,
    ],

    'signing' => [
        'otp_ttl_minutes' => 10,
        'otp_max_attempts' => 5,
        'otp_max_sends' => 5,
        'otp_resend_cooldown_seconds' => 60,
        'consent_version' => '2026-10-1',
    ],

    'cron' => [
        'outbox_batch' => (int) env('FINANCE_OUTBOX_BATCH', 25),
        'recurring_batch' => (int) env('FINANCE_RECURRING_BATCH', 50),
        'max_seconds' => (int) env('FINANCE_CRON_MAX_SECONDS', 50),
    ],

    'backup' => [
        'status_file' => env('FINANCE_BACKUP_STATUS_FILE', '/var/lib/mcnutt-finance/backup-status.json'),
    ],

    // IP → approximate location/network for audit and client activity (ipinfo.io). Private IPs are never looked up.
    'geoip' => [
        'enabled' => (bool) env('FINANCE_GEOIP_ENABLED', true),
        'token' => env('IPINFO_TOKEN'),
        'timeout' => 2,
        'cache_days' => 30,
    ],

    'reminders' => [
        // UTC time the daily finance:reminders job runs.
        'run_at' => env('FINANCE_REMINDERS_AT', '14:00'),
    ],

    // Bank name lookups for ACH/wire instructions. {number} = 9-digit ABA routing number, {code} = SWIFT/BIC.
    'banks' => [
        'routing_lookup_url' => env('FINANCE_ROUTING_LOOKUP_URL', 'https://bankrouting.io/api/v1/aba/{number}'),
        'swift_lookup_url' => env('FINANCE_SWIFT_LOOKUP_URL', env('FINANCE_SWIFT_LOOKUP_KEY') ? 'https://api.api-ninjas.com/v1/swiftcode?swift={code}' : ''),
        'swift_lookup_key' => env('FINANCE_SWIFT_LOOKUP_KEY'),
        'timeout' => 4,
    ],

    // Tax estimate parameters. Optional HTTPS JSON feed: {"tables": [{"jurisdiction": "NJ", "year": 2027, "data": {...}}]}.
    'tax' => [
        'feed_url' => env('FINANCE_TAX_FEED_URL', ''),
    ],

    'stripe' => [
        'api_base' => 'https://api.stripe.com/v1',
        'webhook_tolerance_seconds' => 300,
        'timeout' => 20,
    ],

    // Expenses → Import → subscription charges. Each class implements Services\Expenses\Subscriptions\SubscriptionSource;
    // swap a sample source for a live API connector by replacing its class here (keep the same key()).
    'subscription_sources' => [
        \App\Services\Expenses\Subscriptions\Sources\AwsSampleSource::class,
        \App\Services\Expenses\Subscriptions\Sources\Microsoft365SampleSource::class,
        \App\Services\Expenses\Subscriptions\Sources\HiscoxSampleSource::class,
    ],

    'tracking' => [
        // Opens/clicks this soon after the mail service accepted the email are treated as security pre-checks.
        'prefetch_seconds' => (int) env('FINANCE_PREFETCH_SECONDS', 30),
    ],

    // Server Manager helper endpoint (POST /srvmgr/helper). Token is the HMAC key shared with manage.mcnutt.cloud.
    'srvmgr' => [
        'token' => env('SRVMGR_HELPER_TOKEN', ''),
        'allow_legacy_token' => (bool) env('SRVMGR_ALLOW_LEGACY_TOKEN', false),
        'allowed_ips' => env('SRVMGR_ALLOWED_IPS', ''),
        'tolerance_seconds' => 300,
    ],

    'trusted_proxies' => env('TRUSTED_PROXIES'),

    // Comma list of central principal IDs allowed to manage platform role mappings (no tenant access implied).
    'platform_admins' => env('FINANCE_PLATFORM_ADMINS', ''),
];
