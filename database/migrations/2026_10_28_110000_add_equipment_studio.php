<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nl_studio_equipment', function (Blueprint $t) {
            $t->string('content_key', 100)->primary();
            $t->foreignId('draft_id')->constrained('nl_studio_drafts')->restrictOnDelete();
            $t->string('slot', 20);
            $t->foreignId('backend_item_id')->unique()->constrained('items')->restrictOnDelete();
            $t->foreignId('catalog_id')->unique()->constrained('nl_catalog')->restrictOnDelete();
            $t->unique(['draft_id', 'slot']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain equipment publication links and economic history; use reviewed forward recovery.');
    }
};
