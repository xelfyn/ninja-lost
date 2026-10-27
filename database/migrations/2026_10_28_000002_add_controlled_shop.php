<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nl_reward_ledger', function (Blueprint $t) {
            // Existing grant entries remain positive credits; no historical balances are rewritten.
            $t->string('direction', 6)->default('credit');
        });
        Schema::create('nl_shop_offers', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 80)->unique();
            $t->string('title', 120);
            $t->foreignId('catalog_id')->constrained('nl_catalog')->restrictOnDelete();
            $t->char('definition_hash', 64);
            $t->unsignedInteger('price_tokens');
            $t->unsignedInteger('price_gold');
            $t->unsignedSmallInteger('max_quantity');
            $t->boolean('active')->default(false);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('nl_shop_purchases', function (Blueprint $t) {
            $t->uuid('claim_id')->primary();
            $t->foreign('claim_id')->references('id')->on('nl_claims')->restrictOnDelete();
            $t->foreignId('offer_id')->constrained('nl_shop_offers')->restrictOnDelete();
            $t->unsignedSmallInteger('quantity');
            $t->unsignedInteger('charged_tokens');
            $t->unsignedInteger('charged_gold');
            $t->json('terms');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain purchase receipts and debit/credit history. Reviewed forward recovery required.');
    }
};
