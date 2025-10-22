<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FailedEnrollment extends Model
{
    protected $fillable = [
        'order_id',
        'moodle_user_id',
        'failure_reason',
        'requires_manual_review',
        'user_data',
        'resolved_at',
    ];

    protected $casts = [
        'requires_manual_review' => 'boolean',
        'user_data' => 'array',
        'resolved_at' => 'datetime',
    ];
}
