<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Columna para ID del usuario en Moodle
            if (!Schema::hasColumn('users', 'moodle_user_id')) {
                $table->unsignedBigInteger('moodle_user_id')->nullable()->index()->after('id');
            }
            
            // Columna para ID de orden de Medusa
            if (!Schema::hasColumn('users', 'medusa_order_id')) {
                $table->string('medusa_order_id')->nullable()->index()->after('moodle_user_id');
            }
            
            // Timestamp de cuando fue procesado en Moodle
            if (!Schema::hasColumn('users', 'moodle_processed_at')) {
                $table->timestamp('moodle_processed_at')->nullable()->after('medusa_order_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['moodle_user_id', 'medusa_order_id', 'moodle_processed_at']);
        });
    }
};