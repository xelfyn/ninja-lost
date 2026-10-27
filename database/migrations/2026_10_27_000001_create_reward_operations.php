<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nl_catalog', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 16);
            $t->string('content_key', 100);
            $t->string('title', 160);
            $t->string('source_url', 500);
            $t->string('source_revision', 100);
            $t->string('rights_basis', 32);
            $t->text('rights_evidence');
            $t->text('compatibility_note');
            $t->json('effect_metadata')->nullable();
            $t->boolean('enabled')->default(false);
            $t->unsignedInteger('supply_limit')->nullable();
            $t->unsignedInteger('opening_issued')->default(0);
            $t->unsignedInteger('issued')->default(0);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['kind', 'content_key']);
        });
        Schema::create('nl_campaigns', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 80)->unique();
            $t->string('title', 160);
            $t->string('kind', 16);
            $t->string('status', 16)->default('draft');
            $t->char('code_hash', 64)->nullable()->unique();
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->unsignedInteger('max_claims');
            $t->unsignedInteger('claimed_count')->default(0);
            $t->json('choices');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['kind', 'status', 'ends_at']);
        });
        Schema::create('nl_claims', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('campaign_id')->constrained('nl_campaigns')->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('character_id')->constrained()->restrictOnDelete();
            $t->string('idempotency_key', 64);
            $t->char('request_hash', 64);
            $t->string('choice_key', 40);
            $t->string('source', 32);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('reason', 160);
            $t->json('granted');
            $t->timestamp('created_at');
            $t->unique(['campaign_id', 'user_id']);
            $t->unique(['user_id', 'idempotency_key']);
            $t->index(['user_id', 'created_at']);
        });
        Schema::create('nl_reward_ledger', function (Blueprint $t) {
            $t->id();
            $t->uuid('claim_id');
            $t->foreign('claim_id')->references('id')->on('nl_claims')->restrictOnDelete();
            $t->foreignId('catalog_id')->nullable()->constrained('nl_catalog')->restrictOnDelete();
            $t->string('kind', 16);
            $t->string('content_key', 100);
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('balance_before');
            $t->unsignedBigInteger('balance_after');
            $t->timestamp('created_at');
        });
        Schema::create('nl_operations_audit', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 40);
            $t->string('subject', 100);
            $t->json('details');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        // Manual rollback only: exporting reward/audit history is required first.
        Schema::dropIfExists('nl_operations_audit');
        Schema::dropIfExists('nl_reward_ledger');
        Schema::dropIfExists('nl_claims');
        Schema::dropIfExists('nl_campaigns');
        Schema::dropIfExists('nl_catalog');
    }
};
