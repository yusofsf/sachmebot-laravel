<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * جدول bar_status — وضعیت فعلی شمش‌ها و قیمت نقره 995.
 * مقدار: عدد (قیمت) یا رشته "unavailable".
 */
class BarStatus extends Model
{
    protected $table = 'bar_status';

    public $timestamps = false;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
