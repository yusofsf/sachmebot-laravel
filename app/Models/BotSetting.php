<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * جدول bot_settings — کلید/مقدار (is_active, buy_percent).
 */
class BotSetting extends Model
{
    protected $table = 'bot_settings';

    public $timestamps = false;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
