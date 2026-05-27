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
        Schema::create('tracing_requests', static function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->smallInteger('response_status');
            $table->string('method', 10);
            $table->text('url');
            $table->string('route_name')->nullable();
            $table->string('route_path')->nullable();

            $table->jsonb('request_headers')->nullable();
            $table->jsonb('query_params')->nullable();
            $table->jsonb('body_params')->nullable();

            $table->jsonb('response_headers')->nullable();
            $table->text('response_body')->nullable();

            $table->jsonb('exception')->nullable();

            $table->nullableMorphs('authenticatable');

            $table->integer('duration_ms')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracing_requests');
    }
};
