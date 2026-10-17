<?php

use App\Http\Controllers\Api\Account\AlertSettingsController;
use App\Http\Controllers\Api\Account\ProfileController;
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\DeliveryZoneController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\Admin\ProductModerationController;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Api\Admin\SellerController as AdminSellerController;
use App\Http\Controllers\Api\Admin\SellerRequestController as AdminSellerRequestController;
use App\Http\Controllers\Api\Admin\SettingsController;
use App\Http\Controllers\Api\Admin\SettlementController;
use App\Http\Controllers\Api\Admin\SmsLogController;
use App\Http\Controllers\Api\Admin\SupportController as AdminSupportController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\Seller\BalanceController;
use App\Http\Controllers\Api\Seller\OrderController as SellerOrderController;
use App\Http\Controllers\Api\Seller\ProductController as SellerProductController;
use App\Http\Controllers\Api\Seller\ProfileController as SellerProfileController;
use App\Http\Controllers\Api\Seller\RequestController as SellerRequestInboxController;
use App\Http\Controllers\Api\Seller\ReviewController as SellerReviewController;
use App\Http\Controllers\Api\Seller\SummaryController as SellerSummaryController;
use App\Http\Controllers\Api\SupportController;
use App\Models\Setting;
use App\Payments\ChapaGateway;
use App\Payments\FakeGateway;
use App\Payments\PaymentGateway;
use App\Services\DistancePricing;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Route;

Route::get('/config', fn () => response()->json([
    'name' => config('marketplace.name'),
    'currency' => config('marketplace.currency'),
    'max_product_images' => config('marketplace.products.max_images'),
    'max_image_kb' => config('marketplace.products.max_image_kb'),
    'cod_enabled' => Setting::codEnabled(),
    'online_payments' => [
        'available' => app(PaymentService::class)->available(),
        'test_mode' => (fn ($g) => $g instanceof FakeGateway || ($g instanceof ChapaGateway && $g->testMode()))(app(PaymentGateway::class)),
        'window_minutes' => max(5, (int) config('marketplace.payments.window_minutes')),
    ],
    // The browser key is public by design (it is sent to every visitor's browser); restrict it by
    // website address in Google Cloud. The server key never leaves the server.
    'maps' => [
        'browser_key' => config('services.google_maps.browser_key') ?: null,
        'map_id' => config('services.google_maps.map_id'),
        'center' => config('marketplace.distance.service_center'),
    ],
    'delivery' => [
        'by_distance' => DistancePricing::enabled(),
        'base_fee_santim' => Setting::deliveryBaseFeeSantim(),
        'per_km_santim' => Setting::deliveryPerKmSantim(),
        'max_km' => Setting::deliveryMaxKm(),
        'per_floor_santim' => Setting::deliveryPerFloorSantim(),
        'stores_set_fee' => Setting::storesSetDeliveryFee(),
    ],
]));

// Payment provider calls. They only prompt a check with the provider, so they need no sign-in.
Route::middleware('throttle:payment-callbacks')->group(function () {
    Route::get('/payments/chapa/callback', [PaymentController::class, 'chapaCallback']);
    Route::post('/payments/chapa/webhook', [PaymentController::class, 'chapaWebhook']);
});

// Public catalog
Route::middleware('throttle:catalog')->group(function () {
    Route::get('/categories', [CatalogController::class, 'categories']);
    Route::get('/areas', [CatalogController::class, 'areas']);
    Route::get('/products', [CatalogController::class, 'products']);
    Route::get('/products/{slug}', [CatalogController::class, 'product']);
    Route::get('/sellers/{slug}', [CatalogController::class, 'seller']);
    Route::get('/delivery-zones', [DeliveryController::class, 'zones']);
    Route::get('/products/{slug}/delivery-estimate', [DeliveryController::class, 'estimate'])->middleware('throttle:distance');
    Route::get('/products/{slug}/reviews', [ReviewController::class, 'forProduct']);
    Route::get('/sellers/{slug}/reviews', [ReviewController::class, 'forSeller']);
});

// Authentication
Route::prefix('auth')->group(function () {
    Route::middleware('throttle:register')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/register-seller', [AuthController::class, 'registerSeller']);
    });
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

// Any signed-in, active user
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::post('/favorites/{product}', [FavoriteController::class, 'store']);
    Route::delete('/favorites/{product}', [FavoriteController::class, 'destroy']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->whereUuid('id');

    Route::get('/account/profile', [ProfileController::class, 'show']);
    Route::put('/account/profile', [ProfileController::class, 'update'])->middleware('throttle:account');
    Route::put('/account/password', [ProfileController::class, 'password']);
    Route::post('/account/sign-out-others', [ProfileController::class, 'signOutOthers']);

    Route::get('/account/alerts', [AlertSettingsController::class, 'show']);
    Route::put('/account/alerts', [AlertSettingsController::class, 'update']);
});

// Customers only
Route::middleware(['auth:sanctum', 'role:customer'])->group(function () {
    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store'])->middleware('throttle:addresses');
    Route::put('/addresses/{id}', [AddressController::class, 'update'])->whereNumber('id')->middleware('throttle:addresses');
    Route::post('/addresses/{id}/default', [AddressController::class, 'setDefault'])->whereNumber('id');
    Route::delete('/addresses/{id}', [AddressController::class, 'destroy'])->whereNumber('id');

    // Questions go to Betoch support; customers never message stores directly.
    Route::get('/support/tickets', [SupportController::class, 'index']);
    Route::get('/support/unread-count', [SupportController::class, 'unreadCount']);
    Route::post('/support/tickets', [SupportController::class, 'store'])->middleware('throttle:inquiries');
    Route::get('/support/tickets/{id}', [SupportController::class, 'show'])->whereNumber('id');
    Route::post('/support/tickets/{id}/messages', [SupportController::class, 'reply'])->whereNumber('id')->middleware('throttle:messages');
    Route::post('/support/tickets/{id}/close', [SupportController::class, 'close'])->whereNumber('id');
    // Old chats with stores, read-only.
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/{id}', [ConversationController::class, 'show'])->whereNumber('id');

    Route::post('/quotes/{id}/accept', [QuoteController::class, 'accept'])->whereNumber('id');
    Route::post('/quotes/{id}/decline', [QuoteController::class, 'decline'])->whereNumber('id');

    Route::get('/cart', [CartController::class, 'show']);
    Route::get('/cart/count', [CartController::class, 'count']);
    Route::post('/cart/items', [CartController::class, 'add']);
    Route::put('/cart/items/{id}', [CartController::class, 'update'])->whereNumber('id');
    Route::delete('/cart/items/{id}', [CartController::class, 'remove'])->whereNumber('id');
    Route::post('/quotes/{id}/add-to-cart', [CartController::class, 'addQuote'])->whereNumber('id');

    Route::post('/checkout', [OrderController::class, 'checkout'])->middleware('throttle:checkout');
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show'])->whereNumber('id');
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel'])->whereNumber('id');
    Route::post('/checkouts/{id}/pay', [PaymentController::class, 'pay'])->whereNumber('id')->middleware('throttle:checkout');
    Route::get('/checkouts/{id}/payment', [PaymentController::class, 'status'])->whereNumber('id');
    Route::post('/checkouts/{id}/cancel', [PaymentController::class, 'cancel'])->whereNumber('id');
    // Test payment page (MARKETPLACE_PAYMENT_GATEWAY=fake). Answers 404 with any other gateway.
    Route::get('/payments/test/{txRef}', [PaymentController::class, 'testShow']);
    Route::post('/payments/test/{txRef}', [PaymentController::class, 'testOutcome']);
    Route::post('/orders/{orderId}/items/{itemId}/review', [ReviewController::class, 'store'])->whereNumber(['orderId', 'itemId']);
    Route::put('/reviews/{id}', [ReviewController::class, 'update'])->whereNumber('id');
});

// Seller area
Route::prefix('seller')->middleware(['auth:sanctum', 'role:seller'])->group(function () {
    Route::get('/summary', SellerSummaryController::class);
    Route::get('/profile', [SellerProfileController::class, 'show']);
    Route::put('/profile', [SellerProfileController::class, 'update']);

    Route::get('/products', [SellerProductController::class, 'index']);
    Route::post('/products', [SellerProductController::class, 'store'])->middleware('throttle:uploads');
    Route::get('/products/{id}', [SellerProductController::class, 'show'])->whereNumber('id');
    Route::put('/products/{id}', [SellerProductController::class, 'update'])->whereNumber('id');
    Route::delete('/products/{id}', [SellerProductController::class, 'destroy'])->whereNumber('id');
    Route::post('/products/{id}/submit', [SellerProductController::class, 'submit'])->whereNumber('id');
    Route::post('/products/{id}/archive', [SellerProductController::class, 'archive'])->whereNumber('id');
    Route::post('/products/{id}/images', [SellerProductController::class, 'uploadImages'])->whereNumber('id')->middleware('throttle:uploads');
    Route::delete('/products/{id}/images/{imageId}', [SellerProductController::class, 'deleteImage'])->whereNumber(['id', 'imageId']);

    // Requests from Betoch. Stores answer Betoch, never customers.
    Route::get('/requests', [SellerRequestInboxController::class, 'index']);
    Route::get('/requests/unread-count', [SellerRequestInboxController::class, 'unreadCount']);
    Route::get('/requests/{id}', [SellerRequestInboxController::class, 'show'])->whereNumber('id');
    Route::post('/requests/{id}/messages', [SellerRequestInboxController::class, 'reply'])->whereNumber('id')->middleware('throttle:messages');
    // Quotes sent in the old store chats can still be withdrawn until they expire.
    Route::post('/quotes/{id}/withdraw', [QuoteController::class, 'withdraw'])->whereNumber('id');

    Route::get('/orders', [SellerOrderController::class, 'index']);
    Route::get('/orders/summary', [SellerOrderController::class, 'summary']);
    Route::get('/orders/{id}', [SellerOrderController::class, 'show'])->whereNumber('id');
    Route::post('/orders/{id}/confirm', [SellerOrderController::class, 'confirm'])->whereNumber('id');
    Route::post('/orders/{id}/status', [SellerOrderController::class, 'advance'])->whereNumber('id');
    Route::post('/orders/{id}/cancel', [SellerOrderController::class, 'cancel'])->whereNumber('id');

    Route::get('/balance', [BalanceController::class, 'show']);
    Route::get('/balance/entries', [BalanceController::class, 'entries']);

    Route::get('/reviews', [SellerReviewController::class, 'index']);
    Route::post('/reviews/{id}/reply', [SellerReviewController::class, 'reply'])->whereNumber('id');
});

// Administration
Route::prefix('admin')->middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/summary', [DashboardController::class, 'summary']);
    Route::get('/audit-logs', [DashboardController::class, 'auditLogs']);

    Route::get('/sellers', [AdminSellerController::class, 'index']);
    Route::get('/sellers/{seller}', [AdminSellerController::class, 'show']);
    Route::put('/sellers/{seller}', [AdminSellerController::class, 'update']);
    Route::post('/sellers/{seller}/{action}', [AdminSellerController::class, 'review'])
        ->whereIn('action', ['approve', 'reject', 'suspend', 'reinstate']);

    Route::get('/products', [ProductModerationController::class, 'index']);
    Route::get('/products/{id}', [ProductModerationController::class, 'show'])->whereNumber('id');
    Route::post('/products/{id}/approve', [ProductModerationController::class, 'approve'])->whereNumber('id');
    Route::post('/products/{id}/reject', [ProductModerationController::class, 'reject'])->whereNumber('id');

    Route::get('/categories', [AdminCategoryController::class, 'index']);
    Route::post('/categories', [AdminCategoryController::class, 'store']);
    Route::put('/categories/{category}', [AdminCategoryController::class, 'update']);
    Route::delete('/categories/{category}', [AdminCategoryController::class, 'destroy']);

    Route::get('/delivery-zones', [DeliveryZoneController::class, 'index']);
    Route::post('/delivery-zones', [DeliveryZoneController::class, 'store']);
    Route::put('/delivery-zones/{zone}', [DeliveryZoneController::class, 'update']);
    Route::delete('/delivery-zones/{zone}', [DeliveryZoneController::class, 'destroy']);

    Route::get('/orders', [AdminOrderController::class, 'index']);
    Route::get('/orders/{id}', [AdminOrderController::class, 'show'])->whereNumber('id');
    Route::post('/orders/{id}/cancel', [AdminOrderController::class, 'cancel'])->whereNumber('id');

    Route::get('/settlements', [SettlementController::class, 'index']);
    Route::get('/settlements/{seller}', [SettlementController::class, 'show']);
    Route::post('/settlements/{seller}/payments', [SettlementController::class, 'payment']);
    Route::post('/settlements/{seller}/adjustments', [SettlementController::class, 'adjust']);
    Route::post('/settlements/{seller}/payouts', [SettlementController::class, 'payout']);

    Route::get('/payments', [AdminPaymentController::class, 'index']);
    Route::get('/refunds', [AdminPaymentController::class, 'refunds']);
    Route::post('/refunds/{id}/complete', [AdminPaymentController::class, 'completeRefund'])->whereNumber('id');

    Route::get('/reviews', [AdminReviewController::class, 'index']);
    Route::post('/reviews/{id}/hide', [AdminReviewController::class, 'hide'])->whereNumber('id');
    Route::post('/reviews/{id}/restore', [AdminReviewController::class, 'restore'])->whereNumber('id');

    Route::get('/users', [AdminUserController::class, 'index']);
    Route::get('/users/{id}', [AdminUserController::class, 'show'])->whereNumber('id');
    Route::post('/users/{id}/{action}', [AdminUserController::class, 'setStatus'])->whereNumber('id')->whereIn('action', ['suspend', 'reactivate']);

    Route::get('/reports/summary', [ReportController::class, 'summary']);
    Route::get('/reports/orders.csv', [ReportController::class, 'export']);

    Route::get('/settings', [SettingsController::class, 'show']);
    Route::put('/settings', [SettingsController::class, 'update']);
    Route::get('/sms', [SmsLogController::class, 'index']);

    // Support inbox
    Route::get('/support/tickets', [AdminSupportController::class, 'index']);
    Route::get('/support/tickets/{id}', [AdminSupportController::class, 'show'])->whereNumber('id');
    Route::put('/support/tickets/{id}', [AdminSupportController::class, 'update'])->whereNumber('id');
    Route::post('/support/tickets/{id}/messages', [AdminSupportController::class, 'reply'])->whereNumber('id')->middleware('throttle:staff-messages');
    Route::post('/support/tickets/{id}/notes', [AdminSupportController::class, 'note'])->whereNumber('id')->middleware('throttle:staff-messages');
    Route::get('/support/admins', [AdminSupportController::class, 'admins']);
    Route::get('/support/requests', [AdminSellerRequestController::class, 'index']);
    Route::post('/support/requests', [AdminSellerRequestController::class, 'store'])->middleware('throttle:staff-messages');
    Route::get('/support/requests/{id}', [AdminSellerRequestController::class, 'show'])->whereNumber('id');
    Route::post('/support/requests/{id}/messages', [AdminSellerRequestController::class, 'reply'])->whereNumber('id')->middleware('throttle:staff-messages');
    Route::post('/support/requests/{id}/close', [AdminSellerRequestController::class, 'close'])->whereNumber('id');
    Route::post('/support/requests/{id}/proposals/{messageId}/send', [AdminSellerRequestController::class, 'relay'])->whereNumber(['id', 'messageId']);
    Route::post('/support/offers/{id}/withdraw', [AdminSellerRequestController::class, 'withdrawOffer'])->whereNumber('id');
    Route::get('/support/old-chats', [AdminSupportController::class, 'legacyIndex']);
    Route::get('/support/old-chats/{id}', [AdminSupportController::class, 'legacyShow'])->whereNumber('id');
});
