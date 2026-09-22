<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\I18nTranslationController;
use App\Http\Controllers\Api\CodController;
use App\Http\Controllers\Api\CostController;
use App\Http\Controllers\Api\CustomsController;
use App\Http\Controllers\Api\CustomerAppController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\WarehouseController;
use App\Http\Controllers\Api\ManageApiController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/admin-login', [AuthController::class, 'adminLogin']);
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
        Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
    });

    Route::get('track/{query?}', [OrderController::class, 'track']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::prefix('customer')->group(function () {
            Route::get('profile', [CustomerAppController::class, 'profile']);
            Route::put('profile', [CustomerAppController::class, 'updateProfile']);
            Route::get('orders', [CustomerAppController::class, 'orders']);
            Route::get('orders/{order}', [CustomerAppController::class, 'show']);
            Route::post('orders', [CustomerAppController::class, 'store']);
            Route::get('warehouses', [CustomerAppController::class, 'warehouses']);
            Route::post('support', [CustomerAppController::class, 'submitSupport']);
        });
        Route::apiResource('customers', CustomerController::class);
        Route::apiResource('drivers', DriverController::class);

        Route::apiResource('orders', OrderController::class);

        Route::get('packages', [PackageController::class, 'index']);
        Route::get('packages/{package}', [PackageController::class, 'show']);
        Route::post('packages/scan', [PackageController::class, 'scan']);
        Route::post('packages/receive', [PackageController::class, 'receive']);
        Route::post('packages/{package}/assign-bin', [PackageController::class, 'assignBin']);
        Route::post('packages/{package}/move', [PackageController::class, 'move']);

        Route::apiResource('shipments', ShipmentController::class)->except(['update']);
        Route::post('shipments/{shipment}/legs', [ShipmentController::class, 'addLeg']);
        Route::put('shipment-legs/{leg}', [ShipmentController::class, 'updateLeg']);
        Route::post('shipments/{shipment}/depart', [ShipmentController::class, 'depart']);
        Route::post('shipment-legs/{leg}/arrive', [ShipmentController::class, 'arriveLeg']);

        Route::apiResource('customs', CustomsController::class);
        Route::post('customs/{customsDeclaration}/declared', [CustomsController::class, 'markDeclared']);
        Route::post('customs/{customsDeclaration}/cleared', [CustomsController::class, 'markCleared']);

        Route::apiResource('deliveries', DeliveryController::class)->except(['update', 'destroy']);
        Route::put('deliveries/{delivery}', [DeliveryController::class, 'update']);
        Route::delete('deliveries/{delivery}', [DeliveryController::class, 'destroy']);
        Route::post('deliveries/{delivery}/assign-driver', [DeliveryController::class, 'assignDriver']);
        Route::post('deliveries/{delivery}/complete-pickup', [DeliveryController::class, 'completePickup']);
        Route::post('deliveries/{delivery}/complete-delivery', [DeliveryController::class, 'completeDelivery']);
        Route::post('deliveries/{delivery}/fail', [DeliveryController::class, 'markFailed']);

        Route::get('cod', [CodController::class, 'index']);
        Route::get('cod/{cod}', [CodController::class, 'show']);
        Route::post('cod/{cod}/settle', [CodController::class, 'settle']);

        Route::apiResource('costs', CostController::class);

        Route::apiResource('invoices', InvoiceController::class)->except(['update']);
        Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue']);
        Route::post('invoices/{invoice}/paid', [InvoiceController::class, 'markPaid']);

        Route::get('dashboard/stats', [DashboardController::class, 'stats']);

        Route::apiResource('users', UserController::class);

        Route::apiResource('warehouses', WarehouseController::class);
        Route::get('warehouses/{warehouse}/structure', [WarehouseController::class, 'structure']);
        Route::post('warehouses/{warehouse}/zones', [WarehouseController::class, 'storeZone']);
        Route::delete('warehouse-zones/{zone}', [WarehouseController::class, 'destroyZone']);
        Route::post('warehouse-zones/{zone}/racks', [WarehouseController::class, 'storeRack']);
        Route::delete('warehouse-racks/{rack}', [WarehouseController::class, 'destroyRack']);
        Route::post('warehouse-racks/{rack}/levels', [WarehouseController::class, 'storeLevel']);
        Route::delete('warehouse-levels/{level}', [WarehouseController::class, 'destroyLevel']);
        Route::post('warehouse-levels/{level}/bins', [WarehouseController::class, 'storeBin']);
        Route::put('warehouse-bins/{bin}', [WarehouseController::class, 'updateBin']);
        Route::delete('warehouse-bins/{bin}', [WarehouseController::class, 'destroyBin']);
    });
});

// i18n translation routes (no auth required, with manageapi prefix for frontend proxy)
Route::prefix('manageapi')->group(function () {
    Route::get('/i18n/translate', [I18nTranslationController::class, 'translate']);
    Route::get('/i18n/locales', [I18nTranslationController::class, 'getAllLocales']);
});

// Sysuser routes for frontend (manageapi/sysuser/*) - no auth for testing
Route::prefix('manageapi')->group(function () {
    Route::post('/sysuser/add', [UserController::class, 'store']);
    Route::post('/sysuser/getlist', [UserController::class, 'index']);
    Route::post('/sysuser/get', [UserController::class, 'show']);
    Route::post('/sysuser/del', [UserController::class, 'destroy']);
    Route::post('/sysuser/edit', [UserController::class, 'update']);
    Route::post('/sysuser/editinfo', [UserController::class, 'editInfo']);
    Route::post('/sysuser/editpass', [UserController::class, 'editPass']);
    Route::post('/sysuser/resetpass', [UserController::class, 'resetPass']);
    Route::post('/sysuser/batchdel', [UserController::class, 'batchDelete']);
});

// ManageAPI routes for frontend admin panel compatibility (maps CRUD-style calls to backend data)
    // Note: auth middleware is disabled for development/demo mode. Enable in production.
    Route::prefix('manageapi')->group(function () {
        // Order CRUD
        Route::post('/order/getlist', [ManageApiController::class, 'orderList']);
        Route::match(['get', 'post'], '/order/get', [ManageApiController::class, 'orderGet']);
        Route::post('/order/add', [ManageApiController::class, 'orderAdd']);
        Route::post('/order/edit', [ManageApiController::class, 'orderEdit']);
        Route::match(['get', 'post'], '/order/del', [ManageApiController::class, 'orderDel']);

        // Customer CRUD
        Route::post('/customer/getlist', [ManageApiController::class, 'customerList']);
        Route::post('/customer/add', [ManageApiController::class, 'customerAdd']);
        Route::post('/customer/edit', [ManageApiController::class, 'customerEdit']);
        Route::match(['get', 'post'], '/customer/del', [ManageApiController::class, 'customerDel']);

        // Warehouse CRUD
        Route::post('/warehouse/getlist', [ManageApiController::class, 'warehouseList']);
        Route::match(['get', 'post'], '/warehouse/get', [ManageApiController::class, 'warehouseGet']);
        Route::post('/warehouse/add', [ManageApiController::class, 'warehouseAdd']);
        Route::post('/warehouse/edit', [ManageApiController::class, 'warehouseEdit']);
        Route::match(['get', 'post'], '/warehouse/del', [ManageApiController::class, 'warehouseDel']);

        // Package CRUD
        Route::post('/package/getlist', [ManageApiController::class, 'packageList']);
        Route::post('/package/add', [ManageApiController::class, 'packageAdd']);
        Route::match(['get', 'post'], '/package/del', [ManageApiController::class, 'packageDel']);

        // Shipment
        Route::post('/shipment/getlist', [ManageApiController::class, 'shipmentList']);

        // Delivery
        Route::post('/delivery/getlist', [ManageApiController::class, 'deliveryList']);

        // COD
        Route::post('/cod/getlist', [ManageApiController::class, 'codList']);

        // Cost
        Route::post('/cost/getlist', [ManageApiController::class, 'costList']);
        Route::post('/cost/add', [ManageApiController::class, 'costAdd']);
        Route::match(['get', 'post'], '/cost/del', [ManageApiController::class, 'costDel']);

        // Tracking
        Route::post('/tracking/getlist', [ManageApiController::class, 'trackingList']);

        // Customs
        Route::post('/customs/getlist', [ManageApiController::class, 'customsList']);

        // Pickup
        Route::post('/pickup/getlist', [ManageApiController::class, 'pickupList']);

        // Report
        Route::post('/report/getlist', [ManageApiController::class, 'reportList']);
        Route::match(['get', 'post'], '/report/getstatistics', [ManageApiController::class, 'reportStatistics']);

        // Statement
        Route::post('/statement/getlist', [ManageApiController::class, 'statementList']);
        Route::post('/statement/add', [ManageApiController::class, 'statementAdd']);
        Route::post('/statement/edit', [ManageApiController::class, 'statementEdit']);
        Route::match(['get', 'post'], '/statement/del', [ManageApiController::class, 'statementDel']);

        // System settings
        Route::post('/systemset/getlist', [ManageApiController::class, 'systemsetList']);
    });