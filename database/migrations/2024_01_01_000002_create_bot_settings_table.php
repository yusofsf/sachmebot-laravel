<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('value');
        });

        // مقادیر پیش‌فرض (مثل INSERT OR IGNORE در پایتون)
        DB::table('bot_settings')->insertOrIgnore([
            ['key' => 'is_active', 'value' => '1'],
            ['key' => 'buy_percent', 'value' => '3'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_settings');
    }
};
