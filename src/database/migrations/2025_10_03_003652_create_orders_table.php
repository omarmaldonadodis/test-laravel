<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            
            // Datos de Medusa
            $table->string('medusa_order_id')->unique()->comment('ID de orden en Medusa');
            $table->string('display_id')->nullable()->comment('Display ID de orden en Medusa');
            
            // Datos del cliente
            $table->string('customer_email')->index();
            $table->string('customer_name');
            
            // Datos financieros
            $table->decimal('total', 10, 2)->default(0); 
            $table->decimal('subtotal', 10, 2)->default(0); 
            $table->decimal('tax_total', 10, 2)->default(0);
            $table->decimal('shipping_total', 10, 2)->default(0);            
            $table->string('currency', 3)->default('USD');
                    
            // Estado
            $table->string('payment_status')->index();
            
            // Productos comprados (JSON)
            $table->json('items')->nullable();
            $table->json('metadata')->nullable();
            
            // Integración con Moodle
            $table->unsignedBigInteger('moodle_user_id')->nullable()->index();
            $table->boolean('processed')->default(false)->index();
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            
            // Timestamps
            $table->timestamps();
            
            // Índices compuestos
            $table->index(['customer_email', 'processed']);
            $table->index(['payment_status', 'processed']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};