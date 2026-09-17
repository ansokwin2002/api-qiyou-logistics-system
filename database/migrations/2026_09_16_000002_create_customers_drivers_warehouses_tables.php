<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->string('id_type', 20)->nullable()->comment('national_id|passport|driver_license');
            $table->string('id_number')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('license_number')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 50)->unique();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('type', 30)->default('origin')->comment('origin|destination|both');
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('warehouse_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->timestamps();
        });

        Schema::create('warehouse_racks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_zone_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->timestamps();
        });

        Schema::create('warehouse_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_rack_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->timestamps();
        });

        Schema::create('warehouse_bins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_level_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50)->unique();
            $table->string('status', 20)->default('available')->comment('available|occupied|disabled');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_bins');
        Schema::dropIfExists('warehouse_levels');
        Schema::dropIfExists('warehouse_racks');
        Schema::dropIfExists('warehouse_zones');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('customers');
    }
};