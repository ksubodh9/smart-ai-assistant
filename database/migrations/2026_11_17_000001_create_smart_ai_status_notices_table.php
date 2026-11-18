<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Known issues per service (an outage, a slow operator), shown in the
     * widget and as the reply to messages about that service. Ending a
     * notice sets ends_at; rows are kept as history.
     */
    public function up(): void
    {
        Schema::create('smart_ai_status_notices', function (Blueprint $table) {
            $table->id();
            $table->string('service', 64);             // a category tag, e.g. PAYMENTS
            $table->json('messages');                  // language code => text
            $table->timestamp('starts_at')->nullable(); // null = from creation
            $table->timestamp('ends_at')->nullable();   // null = until ended by hand
            $table->string('posted_by', 100)->nullable();
            $table->timestamps();

            $table->index(['ends_at', 'starts_at']);
            $table->index('service');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_ai_status_notices');
    }
};
