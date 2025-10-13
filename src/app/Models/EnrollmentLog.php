<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnrollmentLog extends Model
{
    protected $fillable = [
        'stripe_payment_intent_id',
        'medusa_order_id',
        'customer_email',
        'customer_name',
        'moodle_user_id',
        'moodle_course_id',
        'status',
        'error_message',
        'webhook_payload',
    ];

    protected $casts = [
        'webhook_payload' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}