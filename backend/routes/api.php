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
use App\Http\Controllers\Api\Admin\SettingsController;
use App\Http\Controllers\Api\Admin\SettlementController;
use App\Http\Controllers\Api\Admin\SmsLogController;
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
use App\Http\Controllers\Api\Seller\Erp\ExpenseController as ErpExpenseController;
use App\Http\Controllers\Api\Seller\Erp\FinanceController as ErpFinanceController;
use App\Http\Controllers\Api\Seller\Erp\InventoryController as ErpInventoryController;
use App\Http\Controllers\Api\Seller\Erp\OverviewController as ErpOverviewController;
use App\Http\Controllers\Api\Seller\Erp\PurchaseController as ErpPurchaseController;
use App\Http\Controllers\Api\Seller\Erp\ReportController as ErpReportController;
use App\Http\Controllers\Api\Seller\Erp\SaleController as ErpSaleController;
use App\Http\Controllers\Api\Seller\Erp\SettingsController as ErpSettingsController;
use App\Http\Controllers\Api\Seller\Erp\SupplierController as ErpSupplierController;
use App\Http\Controllers\Api\Seller\Erp\TransferController as ErpTransferController;
use App\Http\Controllers\Api\Seller\Erp\WarehouseController as ErpWarehouseController;
use App\Http\Controllers\Api\Seller\OrderController as SellerOrderController;
use App\Http\Controllers\Api\Seller\ProductController as SellerProductController;
use App\Http\Controllers\Api\Seller\ProfileController as SellerProfileController;
use App\Http\Controllers\Api\Seller\ReviewController as SellerReviewController;
use App\Http\Controllers\Api\Seller\SummaryController as SellerSummaryController;
use App\Http\Controllers\Api\Seller\VariantController as SellerVariantController;
use App\Models\Setting;
use App\Payments\ChapaGateway;
use App\Payments\FakeGateway;
use App\Payments\PaymentGateway;
use App\Services\DistancePricing;
use App\Services\PaymentService;
use App\Support\Erp\Reports;
use Illuminate\Support\Facades\Route;

Route::get('/config', fn () => response()->json([
    'name' => config('marketplace.name'),
    'currency' => config('marketplace.currency'),
    'max_product_images' => config('marketplace.products.max_images'),
    'max_image_kb' => config('marketplace.products.max_image_kb'),
    'show_seller_phone' => config('marketplace.contact.show_seller_phone'),
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

    Route::post('/conversations', [ConversationController::class, 'store'])->middleware('throttle:inquiries');
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

// Inquiries: customers and sellers each see only their own side
Route::middleware(['auth:sanctum', 'role:customer,seller'])->group(function () {
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/unread-count', [ConversationController::class, 'unreadCount']);
    Route::get('/conversations/{id}', [ConversationController::class, 'show'])->whereNumber('id');
    Route::post('/conversations/{id}/messages', [ConversationController::class, 'reply'])->whereNumber('id')->middleware('throttle:messages');
    Route::post('/conversations/{id}/close', [ConversationController::class, 'close'])->whereNumber('id');
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
    Route::post('/products/{id}/variants', [SellerVariantController::class, 'store'])->whereNumber('id');
    Route::put('/products/{id}/variants/{variantId}', [SellerVariantController::class, 'update'])->whereNumber(['id', 'variantId']);
    Route::delete('/products/{id}/variants/{variantId}', [SellerVariantController::class, 'destroy'])->whereNumber(['id', 'variantId']);

    Route::post('/conversations/{id}/quotes', [QuoteController::class, 'store'])->whereNumber('id')->middleware('throttle:messages');
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

    // ERP: the store's own stock, purchasing and money records
    Route::prefix('erp')->group(function () {
        Route::get('/overview', ErpOverviewController::class);
        Route::get('/inventory', [ErpInventoryController::class, 'index']);
        Route::get('/inventory.csv', [ErpInventoryController::class, 'export']);
        Route::get('/inventory/{id}', [ErpInventoryController::class, 'show'])->whereNumber('id');
        Route::put('/inventory/{id}', [ErpInventoryController::class, 'update'])->whereNumber('id');
        Route::post('/inventory/{id}/adjust', [ErpInventoryController::class, 'adjust'])->whereNumber('id')->middleware('throttle:erp-writes');
        Route::post('/inventory/{id}/sku', [ErpInventoryController::class, 'generateSku'])->whereNumber('id');
        Route::get('/movements', [ErpInventoryController::class, 'movements']);
        Route::get('/movements.csv', [ErpInventoryController::class, 'exportMovements']);
        Route::get('/items', [ErpInventoryController::class, 'pick']);
        Route::get('/settings', [ErpSettingsController::class, 'show']);
        Route::put('/settings', [ErpSettingsController::class, 'update']);

        Route::get('/warehouses', [ErpWarehouseController::class, 'index']);
        Route::get('/warehouses/{id}', [ErpWarehouseController::class, 'show'])->whereNumber('id');
        Route::middleware('throttle:erp-writes')->group(function () {
            Route::post('/warehouses', [ErpWarehouseController::class, 'store']);
            Route::put('/warehouses/{id}', [ErpWarehouseController::class, 'update'])->whereNumber('id');
            Route::post('/warehouses/{id}/close', [ErpWarehouseController::class, 'close'])->whereNumber('id');
            Route::post('/warehouses/{id}/reopen', [ErpWarehouseController::class, 'reopen'])->whereNumber('id');
        });

        Route::get('/transfers', [ErpTransferController::class, 'index']);
        Route::get('/transfers/{id}', [ErpTransferController::class, 'show'])->whereNumber('id');
        Route::middleware('throttle:erp-writes')->group(function () {
            Route::post('/transfers', [ErpTransferController::class, 'store']);
            Route::post('/transfers/{id}/receive', [ErpTransferController::class, 'receive'])->whereNumber('id');
            Route::post('/transfers/{id}/cancel', [ErpTransferController::class, 'cancel'])->whereNumber('id');
        });

        Route::get('/suppliers', [ErpSupplierController::class, 'index']);
        Route::get('/suppliers/{id}', [ErpSupplierController::class, 'show'])->whereNumber('id');
        Route::middleware('throttle:erp-writes')->group(function () {
            Route::post('/suppliers', [ErpSupplierController::class, 'store']);
            Route::put('/suppliers/{id}', [ErpSupplierController::class, 'update'])->whereNumber('id');
            Route::delete('/suppliers/{id}', [ErpSupplierController::class, 'destroy'])->whereNumber('id');
            Route::post('/suppliers/{id}/invoices', [ErpSupplierController::class, 'storeInvoice'])->whereNumber('id');
            Route::post('/supplier-invoices/{id}/void', [ErpSupplierController::class, 'voidInvoice'])->whereNumber('id');
            Route::post('/suppliers/{id}/payments', [ErpSupplierController::class, 'storePayment'])->whereNumber('id');
            Route::post('/supplier-payments/{id}/void', [ErpSupplierController::class, 'voidPayment'])->whereNumber('id');
        });

        Route::get('/purchases', [ErpPurchaseController::class, 'index']);
        Route::get('/purchases/{id}', [ErpPurchaseController::class, 'show'])->whereNumber('id');
        Route::middleware('throttle:erp-writes')->group(function () {
            Route::post('/purchases', [ErpPurchaseController::class, 'store']);
            Route::put('/purchases/{id}', [ErpPurchaseController::class, 'update'])->whereNumber('id');
            Route::post('/purchases/{id}/order', [ErpPurchaseController::class, 'order'])->whereNumber('id');
            Route::post('/purchases/{id}/cancel', [ErpPurchaseController::class, 'cancel'])->whereNumber('id');
            Route::post('/purchases/{id}/close', [ErpPurchaseController::class, 'close'])->whereNumber('id');
            Route::post('/purchases/{id}/receive', [ErpPurchaseController::class, 'receive'])->whereNumber('id');
        });

        Route::get('/sales', [ErpSaleController::class, 'index']);
        Route::get('/sales/report', [ErpSaleController::class, 'report']);
        Route::get('/sales.csv', [ErpSaleController::class, 'export']);
        Route::get('/sales/offline/{id}', [ErpSaleController::class, 'showOffline'])->whereNumber('id');
        Route::get('/sales/orders/{id}', [ErpSaleController::class, 'showOrder'])->whereNumber('id');
        Route::middleware('throttle:erp-writes')->group(function () {
            Route::post('/sales', [ErpSaleController::class, 'store']);
            Route::put('/sales/offline/{id}', [ErpSaleController::class, 'update'])->whereNumber('id');
            Route::post('/sales/offline/{id}/void', [ErpSaleController::class, 'void'])->whereNumber('id');
            Route::post('/sales/offline/{id}/payments', [ErpSaleController::class, 'storePayment'])->whereNumber('id');
            Route::post('/sale-payments/{id}/void', [ErpSaleController::class, 'voidPayment'])->whereNumber('id');
            Route::post('/sales/offline/{id}/returns', [ErpSaleController::class, 'storeOfflineReturn'])->whereNumber('id');
            Route::post('/sales/orders/{id}/returns', [ErpSaleController::class, 'storeOrderReturn'])->whereNumber('id');
            Route::post('/sale-returns/{id}/void', [ErpSaleController::class, 'voidReturn'])->whereNumber('id');
        });

        Route::get('/expenses', [ErpExpenseController::class, 'index']);
        Route::get('/expenses.csv', [ErpExpenseController::class, 'export']);
        Route::get('/expenses/{id}', [ErpExpenseController::class, 'show'])->whereNumber('id');
        Route::get('/expenses/{id}/attachment', [ErpExpenseController::class, 'attachment'])->whereNumber('id');
        Route::get('/expense-categories', [ErpExpenseController::class, 'categories']);
        Route::middleware('throttle:erp-writes')->group(function () {
            Route::post('/expenses', [ErpExpenseController::class, 'store']);
            Route::put('/expenses/{id}', [ErpExpenseController::class, 'update'])->whereNumber('id');
            Route::post('/expenses/{id}/attachment', [ErpExpenseController::class, 'attach'])->whereNumber('id');
            Route::post('/expenses/{id}/void', [ErpExpenseController::class, 'void'])->whereNumber('id');
            Route::post('/expense-categories', [ErpExpenseController::class, 'storeCategory']);
            Route::put('/expense-categories/{id}', [ErpExpenseController::class, 'updateCategory'])->whereNumber('id');
        });

        Route::get('/finance', [ErpFinanceController::class, 'show']);
        Route::get('/finance.csv', [ErpFinanceController::class, 'export']);
        Route::get('/reports', [ErpReportController::class, 'index']);
        Route::get('/reports/{report}.csv', [ErpReportController::class, 'export'])->whereIn('report', array_keys(Reports::definitions()));
        Route::get('/reports/{report}', [ErpReportController::class, 'show'])->whereIn('report', array_keys(Reports::definitions()));
    });
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
});
