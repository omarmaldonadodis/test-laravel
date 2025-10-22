<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_enrollments', function (Blueprint $table) {
            $table->id();
            $table->string('order_id')->index();
            $table->unsignedBigInteger('moodle_user_id')->index();
            $table->text('failure_reason');
            $table->boolean('requires_manual_review')->default(true);
            $table->json('user_data')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            
            $table->index(['requires_manual_review', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_enrollments');
    }
};
