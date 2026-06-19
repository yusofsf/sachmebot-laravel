<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * جدول silver_prices — هر رکورد یک snapshot از قیمت‌هاست.
 */
class SilverPrice extends Model
{
    protected $table = 'silver_prices';

    public $timestamps = false; // ستون timestamp دستی پر می‌شود

    protected $guarded = [];
}
