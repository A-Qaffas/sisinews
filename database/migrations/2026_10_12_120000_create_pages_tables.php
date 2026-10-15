<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Static pages (About, Standards, Privacy...). Like articles, the shell is language-neutral and
        // each language is its own row, so one language can be live while the other is still unwritten.
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            // Part of the public address (/{locale}/page/{slug}); fixed when the page is created.
            $table->string('slug', 80)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('show_in_footer')->default(false);
            $table->timestamps();
        });

        Schema::create('page_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->char('locale', 2);
            $table->string('title');
            $table->longText('body');
            $table->string('meta_description', 300)->nullable();
            // A page is taken off the site by unpublishing a language, never by deleting the row.
            $table->boolean('is_published')->default(true);
            $table->timestamps();
            $table->unique(['page_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_translations');
        Schema::dropIfExists('pages');
    }
};
