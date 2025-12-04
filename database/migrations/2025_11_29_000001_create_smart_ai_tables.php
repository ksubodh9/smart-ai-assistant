<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('smart_ai_error_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('service')->default('AEPS');
            $table->string('key_text');
            $table->text('answer_en');
            $table->text('answer_hi')->nullable();
            $table->json('meta')->nullable();
            $table->boolean('created_by_ai')->default(false);
            $table->timestamps();
        });

        Schema::create('smart_ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('service')->default('AEPS');
            $table->string('status')->default('resolved'); // resolved/open/needs_human
            $table->string('page_url')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('smart_ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('smart_ai_conversations')->onDelete('cascade');
            $table->enum('sender_type', ['user', 'ai', 'system']);
            $table->text('message')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smart_ai_messages');
        Schema::dropIfExists('smart_ai_conversations');
        Schema::dropIfExists('smart_ai_error_definitions');
    }
};
