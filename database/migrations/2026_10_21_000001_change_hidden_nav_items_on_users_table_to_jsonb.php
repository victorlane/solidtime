<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * PostgreSQL has no equality operator for `json`, so any `select distinct users.*` fails while
     * the users table carries a `json` column. The Filament admin panel issues exactly that query
     * when attaching a user to an organization. `jsonb` does support equality.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->jsonb('hidden_nav_items')->default('[]')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('hidden_nav_items')->default('[]')->change();
        });
    }
};
