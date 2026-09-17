<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('delivery')->comment('delivery|pickup');
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('pending')->comment('pending|out_for_delivery|delivered|picked_up|failed');
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('receiver_name')->nullable();
            $table->string('receiver_phone', 30)->nullable();
            $table->string('receiver_id_type', 30)->nullable()->comment('national_id|passport|tax_id');
            $table->string('receiver_id_number')->nullable();
            $table->text('signature')->nullable();
            $table->string('issue_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('cod_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('expected_amount', 10, 2)->default(0);
            $table->decimal('collected_amount', 10, 2)->default(0);
            $table->decimal('difference', 10, 2)->default(0);
            $table->string('collection_via', 20)->nullable()->comment('driver|warehouse');
            $table->string('settlement_status', 20)->default('pending')->comment('pending|collected|partial|settled');
            $table->foreignId('collected_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('collected_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 30)->default('other')->comment('warehouse|freight|customs|last_mile|other');
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency', 10)->default('USD');
            $table->string('description')->nullable();
            $table->date('cost_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 50)->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('total_fee', 10, 2)->default(0);
            $table->decimal('cod_total', 10, 2)->default(0);
            $table->string('currency', 10)->default('USD');
            $table->string('status', 20)->default('draft')->comment('draft|issued|paid');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('costs');
        Schema::dropIfExists('cod_collections');
        Schema::dropIfExists('deliveries');
    }
};