<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processed_webhooks', function (Blueprint $table) {
            // Agregar campos faltantes si no existen
            if (!Schema::hasColumn('processed_webhooks', 'event_type')) {
                $table->string('event_type')->nullable()->after('webhook_id');
            }
            
            if (!Schema::hasColumn('processed_webhooks', 'payload')) {
                $table->json('payload')->nullable()->after('user_email');
            }
            
            // Índices para optimizar búsquedas
            if (!Schema::hasIndex('processed_webhooks', 'processed_webhooks_webhook_id_index')) {
                $table->index('webhook_id');
            }
            
            if (!Schema::hasIndex('processed_webhooks', 'processed_webhooks_medusa_order_id_index')) {
                $table->index('medusa_order_id');
            }
            
            if (!Schema::hasIndex('processed_webhooks', 'processed_webhooks_user_email_index')) {
                $table->index('user_email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('processed_webhooks', function (Blueprint $table) {
            $table->dropColumn(['event_type', 'payload']);
            $table->dropIndex(['webhook_id', 'medusa_order_id', 'user_email']);
        });
    }
};
