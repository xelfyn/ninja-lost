<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing portal actor=user: the earlier user/key uniqueness already ensures this backstop.
        Schema::table('nl_claims', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id')->nullable()->change();
            $table->unique(['actor_id', 'idempotency_key'], 'nl_claims_actor_idempotency_unique');
        });
        Schema::create('nl_account_revisions', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('revision')->default(0);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Economy rollback requires an audited data-preserving plan; retain the additive schema.');
    }
};
