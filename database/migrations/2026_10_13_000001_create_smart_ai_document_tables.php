<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Documents the host provides (guides, policies, FAQs), split into
     * passages for retrieval. Each upload of a title is a new version; only
     * the active version is searched.
     */
    public function up(): void
    {
        Schema::create('smart_ai_documents', function (Blueprint $table) {
            $table->id();
            $table->string('service', 100);
            $table->string('title');
            $table->string('file_name');
            $table->string('mime', 150);
            $table->unsignedBigInteger('size')->default(0);
            $table->char('checksum', 64);               // sha256 of the file
            $table->string('audience', 20)->default('users'); // public / users
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('active');  // active / archived
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['service', 'title', 'version']);
            $table->index(['service', 'status', 'audience']);
        });

        Schema::create('smart_ai_document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('smart_ai_documents')->onDelete('cascade');
            $table->unsignedInteger('position');        // 1-based order within the document
            $table->string('heading')->nullable();      // section path, e.g. "Refunds > Timelines"
            $table->text('content');

            $table->index(['document_id', 'position']);

            // Retrieval uses MATCH ... AGAINST on MySQL/MariaDB and LIKE elsewhere
            if (in_array(DB::connection($this->getConnection())->getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->fullText(['heading', 'content']);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_ai_document_chunks');
        Schema::dropIfExists('smart_ai_documents');
    }
};
