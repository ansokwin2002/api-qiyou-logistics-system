<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (! Schema::hasColumn('roles', 'code')) {
                $table->string('code', 50)->nullable()->after('slug');
            }
            if (! Schema::hasColumn('roles', 'sort')) {
                $table->unsignedInteger('sort')->default(0)->after('description');
            }
            if (! Schema::hasColumn('roles', 'is_enable')) {
                $table->boolean('is_enable')->default(true)->after('sort');
            }
            if (! Schema::hasColumn('roles', 'permissions')) {
                $table->json('permissions')->nullable()->after('is_enable');
            }
        });

        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->default(0)->index();
            $table->unsignedTinyInteger('menu_type')->default(0)->comment('0 = menu, 1 = button');
            $table->string('name_key')->comment('i18n translation key');
            $table->string('authority')->nullable()->comment('permission key e.g. order:add');
            $table->string('route_name')->nullable();
            $table->string('path')->nullable();
            $table->string('component')->nullable();
            $table->string('icon')->nullable();
            $table->integer('sort')->default(0);
            $table->boolean('hide')->default(false);
            $table->boolean('is_keep_alive')->default(false);
            $table->boolean('is_affix')->default(false);
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->string('group', 50)->default('general')->index();
            $table->string('type', 20)->default('string')->comment('string|boolean|number|json');
            $table->string('label')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('action', 50)->index();
            $table->nullableMorphs('subject');
            $table->string('description')->nullable();
            $table->string('method', 10)->nullable();
            $table->string('path')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('payload')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('rate_cards', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->comment('e.g. TW -> KH Air Standard');
            $table->string('transport_method', 20)->default('air')->comment('air|sea|land');
            $table->string('origin_country', 60)->nullable();
            $table->string('destination_country', 60)->nullable();
            $table->unsignedBigInteger('origin_warehouse_id')->nullable();
            $table->unsignedBigInteger('destination_warehouse_id')->nullable();
            $table->decimal('first_kg', 10, 3)->default(0)->comment('chargeable kg covered by base fee');
            $table->decimal('base_fee', 12, 2)->default(0)->comment('fee for the first first_kg');
            $table->decimal('rate_per_kg', 12, 4)->default(0)->comment('fee per additional kg');
            $table->decimal('min_fee', 12, 2)->default(0);
            $table->decimal('per_unit_fee', 12, 2)->default(0)->comment('optional fee per item unit');
            $table->string('currency', 10)->default('USD');
            $table->boolean('status')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['transport_method', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_cards');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('menus');

        Schema::table('roles', function (Blueprint $table) {
            foreach (['code', 'sort', 'is_enable', 'permissions'] as $column) {
                if (Schema::hasColumn('roles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
