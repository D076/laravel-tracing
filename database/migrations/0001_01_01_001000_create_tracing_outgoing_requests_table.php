<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function getConnection(): ?string
    {
        return config('tracing.connection');
    }

    public function up(): void
    {
        Schema::create('tracing_outgoing_requests', static function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Soft-reference to tracing_requests.id (no FK — works from jobs/CLI too)
            $table->string('trace_id')->nullable()->index();

            $table->string('method', 10);
            $table->text('url');

            $table->jsonb('request_headers')->nullable();
            $table->text('request_body')->nullable();

            $table->smallInteger('response_status')->nullable();
            $table->jsonb('response_headers')->nullable();
            $table->text('response_body')->nullable();

            $table->string('exception_class')->nullable();
            $table->text('exception_message')->nullable();

            $table->integer('duration_ms')->nullable();

            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracing_outgoing_requests');
    }
};
