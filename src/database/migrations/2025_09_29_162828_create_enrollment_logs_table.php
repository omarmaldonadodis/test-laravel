<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_logs', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_payment_intent_id')->unique();
            $table->string('medusa_order_id')->nullable();
            $table->string('customer_email');
            $table->string('customer_name');
            $table->integer('moodle_user_id')->nullable();
            $table->integer('moodle_course_id')->nullable();
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->json('webhook_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_logs');
    }
};