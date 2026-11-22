<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bookkeeping for the automatic English edition (App\Translation\EnglishEdition). Both are null on every row a person
 * wrote. auto_source_hash = the Arabic text the English was made from (a changed Arabic text is translated again);
 * auto_output_hash = the English text exactly as the machine wrote it (once a person edits the English it no longer
 * matches, and the machine never touches that row again).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_translations', function (Blueprint $table): void {
            $table->char('auto_source_hash', 64)->nullable()->after('published_at');
            $table->char('auto_output_hash', 64)->nullable()->after('auto_source_hash');
        });
    }

    public function down(): void
    {
        Schema::table('article_translations', function (Blueprint $table): void {
            $table->dropColumn(['auto_source_hash', 'auto_output_hash']);
        });
    }
};
