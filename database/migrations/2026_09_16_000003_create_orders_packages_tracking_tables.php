<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 50)->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('origin_warehouse_id')->constrained('warehouses')->nullOnDelete();
            $table->foreignId('destination_warehouse_id')->constrained('warehouses')->nullOnDelete();
            $table->string('transport_method', 20)->default('air')->comment('air|sea|land');
            $table->string('payment_method', 20)->default('prepaid')->comment('prepaid|cod|both');
            $table->string('fulfillment_method', 20)->default('delivery')->comment('delivery|pickup');
            $table->string('status', 30)->default('pending')->comment('pending|confirmed|in_progress|completed|cancelled');
            $table->decimal('estimated_fee', 10, 2)->default(0);
            $table->string('currency', 10)->default('USD');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('package_no', 50)->unique();
            $table->string('barcode')->nullable()->unique();
            $table->string('description')->nullable();
            $table->decimal('weight', 10, 3)->default(0)->comment('kg');
            $table->decimal('length', 10, 2)->nullable()->comment('cm');
            $table->decimal('width', 10, 2)->nullable()->comment('cm');
            $table->decimal('height', 10, 2)->nullable()->comment('cm');
            $table->decimal('volumetric_weight', 10, 3)->default(0)->comment('kg');
            $table->decimal('chargeable_weight', 10, 3)->default(0)->comment('kg');
            $table->integer('quantity')->default(1);
            $table->decimal('declared_value', 10, 2)->default(0);
            $table->string('currency', 10)->default('USD');
            $table->string('status', 30)->default('pending')->comment('pending|received|in_warehouse|customs|cleared|in_transit|arrived|ready_for_pickup|out_for_delivery|delivered|picked_up');
            $table->foreignId('current_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('current_bin_id')->nullable()->constrained('warehouse_bins')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('tracking_events', function (Blueprint $table) {
            $table->id();
            $table->morphs('trackable');
            $table->string('status');
            $table->string('location')->nullable();
            $table->text('note')->nullable();
            $table->string('actor_name')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_events');
        Schema::dropIfExists('packages');
        Schema::dropIfExists('orders');
    }
};