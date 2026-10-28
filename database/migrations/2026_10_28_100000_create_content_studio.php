<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nl_studio_drafts', function (Blueprint $t) {
            $t->id();
            $t->string('content_key', 100)->unique();
            $t->unsignedInteger('revision')->default(1);
            $t->json('definition');
            $t->char('definition_hash', 64);
            $t->foreignId('backend_skill_id')->nullable()->constrained('skills')->restrictOnDelete();
            $t->foreignId('catalog_id')->nullable()->constrained('nl_catalog')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('nl_studio_releases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('draft_id')->constrained('nl_studio_drafts')->restrictOnDelete();
            $t->unsignedInteger('draft_revision');
            $t->char('definition_hash', 64);
            // Exact JSON bytes, not a MySQL JSON object whose property order may change.
            $t->longText('payload');
            $t->char('payload_hash', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['draft_id', 'draft_revision']);
        });
        Schema::create('nl_studio_heads', function (Blueprint $t) {
            $t->foreignId('draft_id')->primary()->constrained('nl_studio_drafts')->restrictOnDelete();
            $t->uuid('release_id');
            $t->foreign('release_id')->references('id')->on('nl_studio_releases')->restrictOnDelete();
            $t->foreignId('activated_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Content releases may be referenced by inventory and battle snapshots. Use reviewed forward recovery; retain provenance and history.');
    }
};
