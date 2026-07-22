<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            // Disiapkan sekarang untuk multi-outlet/multi-warehouse, saat ini selalu bernilai 1.
            $table->foreignId('outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('date');
            // Idempotency key untuk sinkronisasi POS offline-first.
            $table->uuid('local_uuid')->unique();
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('grand_total', 18, 4)->default(0);
            $table->string('payment_method', 50)->nullable();
            $table->enum('status', ['completed', 'void', 'refunded'])->default('completed');
            // Dokumen sumber opsional yang melahirkan sale ini (mis. order online, sesi kasir).
            $table->nullableMorphs('source');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
