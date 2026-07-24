<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function getConnection(): ?string
    {
        return config('tracing.connection');
    }

    public function up(): void
    {
        foreach (['tracing_requests', 'tracing_outgoing_requests'] as $table) {
            Schema::table($table, static function (Blueprint $table): void {
                $table->jsonb('tags')->nullable();
            });
        }

        // Postgres: GIN-индекс для быстрого поиска по тегам через оператор @>.
        // Обычный btree по jsonb для containment бесполезен. На MySQL/SQLite
        // whereJsonContains работает без спец-индекса (dev/тесты), поэтому пропускаем.
        if (DB::connection($this->getConnection())->getDriverName() === 'pgsql') {
            foreach (['tracing_requests', 'tracing_outgoing_requests'] as $table) {
                DB::connection($this->getConnection())->statement(
                    "CREATE INDEX {$table}_tags_gin ON {$table} USING gin (tags)"
                );
            }
        }
    }

    public function down(): void
    {
        if (DB::connection($this->getConnection())->getDriverName() === 'pgsql') {
            foreach (['tracing_requests', 'tracing_outgoing_requests'] as $table) {
                DB::connection($this->getConnection())->statement("DROP INDEX IF EXISTS {$table}_tags_gin");
            }
        }

        foreach (['tracing_requests', 'tracing_outgoing_requests'] as $table) {
            Schema::table($table, static function (Blueprint $table): void {
                $table->dropColumn('tags');
            });
        }
    }
};
