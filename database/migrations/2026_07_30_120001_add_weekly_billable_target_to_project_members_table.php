<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_members', function (Blueprint $table): void {
            // Weekly billable-hours target on this project in seconds;
            // null = no per-project target.
            $table->integer('weekly_billable_target')->unsigned()->nullable()->after('billable_rate');
        });
    }

    public function down(): void
    {
        Schema::table('project_members', function (Blueprint $table): void {
            $table->dropColumn('weekly_billable_target');
        });
    }
};
