<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nl_training_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('character_id')->constrained('characters')->restrictOnDelete();
            $t->uuid('request_key');
            $t->char('request_hash', 64);
            $t->char('runtime_hash', 64);
            $t->longText('bundle');
            $t->char('bundle_hash', 64);
            $t->text('seed'); // Encrypted using the existing application key, never returned to the client.
            $t->longText('state');
            $t->unsignedInteger('revision')->default(0);
            $t->string('status', 16)->default('active');
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->unique(['user_id', 'request_key']);
            $t->index(['user_id', 'character_id', 'status']);
        });
        Schema::create('nl_training_actions', function (Blueprint $t) {
            $t->id();
            $t->uuid('run_id');
            $t->foreign('run_id')->references('id')->on('nl_training_runs')->restrictOnDelete();
            $t->unsignedInteger('sequence');
            $t->uuid('request_key');
            $t->char('request_hash', 64);
            $t->text('command');
            $t->timestamp('created_at');
            $t->unique(['run_id', 'sequence']);
            $t->unique(['run_id', 'request_key']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain training snapshots and action receipts; use reviewed forward recovery.');
    }
};
