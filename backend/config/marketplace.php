<?php

/*
|--------------------------------------------------------------------------
| Marketplace configuration
|--------------------------------------------------------------------------
|
| Business settings that are expected to change before launch live here and
| are read from the environment, so nothing is a hardcoded business decision.
| Values that administrators will edit at runtime (commission rates, delivery
| zones) move to database-backed settings in a later milestone.
|
*/

return [

    // Temporary brand name. Change MARKETPLACE_NAME once the name is final.
    'name' => env('MARKETPLACE_NAME', 'Betoch Furniture'),

    'currency' => 'ETB',

    // Default city slug used for new sellers and listings.
    'default_city' => env('MARKETPLACE_DEFAULT_CITY', 'addis-ababa'),

    'catalog' => [
        'per_page_default' => 24,
        'per_page_max' => 48,
        // Browsing requests per minute per signed-in user, or per IP for guests. Mobile carriers
        // put many phones behind one IP, and a product page makes about five requests.
        'requests_per_minute' => (int) env('MARKETPLACE_CATALOG_RATE', 300),
    ],

    'products' => [
        // Max number of images per listing.
        'max_images' => (int) env('MARKETPLACE_MAX_PRODUCT_IMAGES', 8),
        // Max image size in kilobytes.
        'max_image_kb' => (int) env('MARKETPLACE_MAX_IMAGE_KB', 5120),
        // Allowed image extensions (validated against file content, not just the name).
        'image_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
        // When true, editing descriptive fields of a published listing sends it back to moderation.
        'reapprove_on_edit' => (bool) env('MARKETPLACE_REAPPROVE_ON_EDIT', true),
    ],

    'contact' => [
        // Public store phone numbers let buyers skip the platform (and its commission). Set to false
        // once in-app inquiries are the main contact route.
        'show_seller_phone' => (bool) env('MARKETPLACE_SHOW_SELLER_PHONE', true),
    ],

    // Default only: administrators change the live rate in Admin > Settings. Each order stores
    // the rate used when it was placed, so changes never rewrite past orders.
    'commission' => [
        'default_rate_percent' => (float) env('MARKETPLACE_DEFAULT_COMMISSION_PERCENT', 10),
        'max_rate_percent' => 50,
        // Unpaid commission (ETB) above which a store cannot confirm new orders. 0 turns the limit off.
        'credit_limit_birr' => (int) env('MARKETPLACE_COMMISSION_CREDIT_LIMIT', 0),
    ],

    'settlements' => [
        // Shown to sellers next to their balance: where and how to pay the platform.
        'instructions' => env('MARKETPLACE_SETTLEMENT_INSTRUCTIONS', ''),
    ],

    // Business day boundaries for reports. Timestamps are stored in UTC.
    'timezone' => 'Africa/Addis_Ababa',

    'reviews' => [
        // Customers can review (and edit their review) for this many days after delivery.
        'window_days' => 60,
    ],

    'payments' => [
        // Starting value for the Admin > Settings switch.
        'cod_enabled' => (bool) env('MARKETPLACE_COD_ENABLED', true),
        // Starting value for the Admin > Settings switch. Online payment also needs a working gateway.
        'online_enabled' => (bool) env('MARKETPLACE_ONLINE_PAYMENTS_ENABLED', true),
        // 'none' turns online payment off. 'chapa' uses Chapa (Telebirr, CBE Birr, M-Pesa, cards).
        // 'fake' shows a test page instead of a real provider; it never works when APP_ENV=production.
        'gateway' => env('MARKETPLACE_PAYMENT_GATEWAY', 'none'),
        // Minutes a customer has to finish paying before the order is cancelled and the stock released.
        'window_minutes' => (int) env('MARKETPLACE_PAYMENT_WINDOW_MINUTES', 30),
    ],

    // Order and account alerts. In-app alerts are always on; email uses the normal mail settings.
    'alerts' => [
        // 'log' writes each SMS to the log and the admin SMS log without sending it (local use).
        // 'afromessage' sends through AfroMessage (set AFROMESSAGE_* in .env).
        'sms_driver' => env('MARKETPLACE_SMS_DRIVER', 'log'),
        // Starting value for the Admin > Settings switch that pauses all SMS.
        'sms_enabled' => (bool) env('MARKETPLACE_SMS_ENABLED', true),
        // At most one "new message" email per conversation and recipient in this many minutes.
        'message_email_cooldown_minutes' => 30,
        // At most one "new inquiry" SMS per customer and store in this many hours.
        'inquiry_sms_cooldown_hours' => 24,
    ],

    'cart' => [
        'max_lines' => 30,
        'max_quantity' => 99,
    ],

    // Days after acceptance a customer still has to order an accepted quote, if its own validity is shorter.
    'quotes' => [
        'order_grace_days' => 7,
    ],

];
