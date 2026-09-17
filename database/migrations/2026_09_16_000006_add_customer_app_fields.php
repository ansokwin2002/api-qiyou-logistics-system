<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('status')->constrained('customers')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('sender_name')->nullable()->after('customer_id');
            $table->string('sender_phone', 30)->nullable()->after('sender_name');
            $table->string('receiver_name')->nullable()->after('fulfillment_method');
            $table->string('receiver_phone', 30)->nullable()->after('receiver_name');
            $table->string('receiver_address')->nullable()->after('receiver_phone');
            $table->string('tracking_ref', 50)->nullable()->after('order_no');
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_no', 50)->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->text('message');
            $table->string('attachment')->nullable();
            $table->string('status', 20)->default('open')->comment('open|in_progress|resolved|closed');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['sender_name', 'sender_phone', 'receiver_name', 'receiver_phone', 'receiver_address', 'tracking_ref']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};