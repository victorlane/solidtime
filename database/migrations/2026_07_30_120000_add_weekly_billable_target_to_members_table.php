<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            // Weekly billable-hours target in seconds; null = no target.
            $table->integer('weekly_billable_target')->unsigned()->nullable()->after('billable_rate');
            // Send-once stamp for the weekly reminder mail, compared against
            // the member's current local week start.
            $table->timestamp('weekly_target_email_sent_at')->nullable()->after('weekly_billable_target');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropColumn('weekly_billable_target');
            $table->dropColumn('weekly_target_email_sent_at');
        });
    }
};
