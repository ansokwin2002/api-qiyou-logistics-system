<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('carrier', 100)->nullable()->after('order_id');
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('contact_name', 100)->nullable()->after('phone');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('customer_id')
                ->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('carrier');
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('contact_name');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });
    }
};
