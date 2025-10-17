<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedWebhook extends Model
{
    protected $fillable = [
        'webhook_id',
        'medusa_order_id',
        'user_email',
        'processed_at',
    ];
}
