<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('silver_prices', function (Blueprint $table) {
            $table->id();
            $table->string('timestamp');
            $table->integer('mithqal_price');
            $table->float('gram_price');
            $table->integer('mithqal_price_buy');
            $table->float('gram_price_buy');
            $table->float('silver_ounce');
            $table->float('dollar_price');
            $table->float('tether_price')->nullable();
            $table->float('bubble_mithqal');
            $table->float('bubble_gram');
            $table->float('dirham_price')->nullable();
            $table->float('euro_price')->nullable();
            $table->float('bar_999_price')->nullable();
            $table->float('bar_nadir_price')->nullable();
            $table->float('gram_995')->nullable();
            $table->float('gram_995_buy')->nullable();
            $table->float('mithqal_995_price')->nullable();
            $table->float('mithqal_995_price_buy')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('silver_prices');
    }
};
