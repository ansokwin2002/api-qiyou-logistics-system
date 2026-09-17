<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->string('shipment_no', 50)->unique();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('draft')->comment('draft|in_transit|completed|cancelled');
            $table->timestamp('departed_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('shipment_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->integer('leg_no')->default(1);
            $table->foreignId('origin_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('destination_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('transport_method', 20)->default('air')->comment('air|sea|land');
            $table->string('status', 30)->default('pending')->comment('pending|in_transit|arrived|cancelled');
            $table->timestamp('departure_date')->nullable();
            $table->timestamp('arrival_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('customs_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('receiver_id_type', 30)->nullable()->comment('national_id|passport|tax_id');
            $table->string('receiver_id_number')->nullable();
            $table->string('english_item_name')->nullable();
            $table->string('hs_code', 20)->nullable();
            $table->string('purpose', 50)->nullable();
            $table->string('material', 50)->nullable();
            $table->decimal('declared_value', 10, 2)->default(0);
            $table->string('currency', 10)->default('USD');
            $table->string('status', 30)->default('pending')->comment('pending|declared|cleared');
            $table->timestamp('declared_at')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customs_declarations');
        Schema::dropIfExists('shipment_legs');
        Schema::dropIfExists('shipments');
    }
};