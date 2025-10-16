<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'medusa_order_id',
        'display_id',
        'customer_email',
        'customer_name',
        'total',
        'subtotal',
        'shipping_total',
        'tax_total',
        'currency',
        'payment_status',
        'items',
        'metadata',
        'moodle_user_id',
        'processed',
        'processed_at',
        'error_message',
    ];

    protected $casts = [
        'processed' => 'boolean',
        'processed_at' => 'datetime',
        'total' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'shipping_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'items' => 'array',
        'metadata' => 'array',
    ];

    // Valores por defecto para campos NOT NULL
    protected $attributes = [
        'payment_status' => 'pending',
    ];
}