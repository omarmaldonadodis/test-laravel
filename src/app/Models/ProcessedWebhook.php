<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedWebhook extends Model
{
    protected $fillable = [
        'webhook_id',
        'event_type',
        'medusa_order_id',
        'user_email',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}