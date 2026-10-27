<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nl_battle_rules', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 80)->unique();
            $t->string('mission_id');
            $t->char('definition_hash', 64);
            $t->unsignedSmallInteger('minimum_seconds');
            $t->unsignedSmallInteger('ttl_seconds');
            $t->boolean('active')->default(false);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('nl_battles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('character_id')->constrained('characters')->restrictOnDelete();
            $t->foreignId('rule_id')->constrained('nl_battle_rules')->restrictOnDelete();
            $t->uuid('start_key');
            $t->char('request_hash', 64);
            $t->json('terms');
            $t->string('status', 16)->default('started');
            $t->dateTime('started_at');
            $t->dateTime('not_before');
            $t->dateTime('expires_at');
            $t->char('proof_hash', 64)->nullable();
            $t->string('verifier', 80)->nullable();
            $t->char('evidence_hash', 64)->nullable();
            $t->uuid('claim_id')->nullable()->unique();
            $t->foreign('claim_id')->references('id')->on('nl_claims')->restrictOnDelete();
            $t->json('result')->nullable();
            $t->dateTime('settled_at')->nullable();
            $t->unique(['user_id', 'start_key']);
            $t->index(['character_id', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain battle settlements and reward history; reviewed data-preserving recovery required.');
    }
};
