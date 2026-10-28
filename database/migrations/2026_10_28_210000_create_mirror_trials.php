<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nl_trial_rules', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 60)->unique();
            $t->string('title', 120);
            $t->foreignId('battle_rule_id')->unique()->constrained('nl_battle_rules')->restrictOnDelete();
            $t->unsignedInteger('win_limit');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
        });
        Schema::create('nl_trial_runs', function (Blueprint $t) {
            $t->id(); // Stable creation ordering even when two runs share a wall-clock second.
            $t->uuid('battle_id')->unique();
            $t->foreign('battle_id')->references('id')->on('nl_battles')->restrictOnDelete();
            $t->uuid('run_id')->unique();
            $t->foreign('run_id')->references('id')->on('nl_training_runs')->restrictOnDelete();
            $t->foreignId('trial_id')->constrained('nl_trial_rules')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('start_key');
            $t->char('request_hash', 64);
            $t->char('terms_hash', 64);
            $t->char('bundle_hash', 64);
            $t->char('runtime_hash', 64);
            $t->timestamp('created_at');
            $t->unique(['user_id', 'start_key']);
            $t->index(['user_id', 'trial_id']);
        });
    }
    public function down(): void
    {
        throw new RuntimeException('Keep trial bindings and reward history; use reviewed forward recovery.');
    }
};
