<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('hero_image')->nullable();
            $table->boolean('is_featured')->default(false)->index();
            $table->boolean('is_breaking')->default(false);
            $table->timestamp('breaking_until')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // One row per language version. Each carries its OWN workflow status, so
        // the Arabic text can go live before the English one is even approved.
        // Slug is cosmetic (URLs are /{locale}/news/{article_id}-{slug}), so it is
        // deliberately NOT unique: Arabic headlines collide often.
        Schema::create('article_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->char('locale', 2);
            $table->string('title');
            $table->string('slug');
            $table->text('excerpt')->nullable();
            $table->longText('body')->nullable();
            $table->string('hero_caption')->nullable();
            $table->string('meta_description', 300)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['article_id', 'locale']);
            $table->index(['locale', 'status', 'published_at']);
        });

        Schema::create('article_tag', function (Blueprint $table) {
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['article_id', 'tag_id']);
        });

        // Append-only editorial audit trail. Also the single source for the
        // latest reviewer note shown to a journalist after "changes requested".
        Schema::create('article_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_translation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('transition', 20);
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('article_translation_id');
        });

        // One row per translation per day; powers "Most Read" cheaply.
        Schema::create('article_daily_views', function (Blueprint $table) {
            $table->foreignId('article_translation_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('views')->default(0);
            $table->primary(['article_translation_id', 'day']);
            $table->index('day');
        });

        // Stores addresses only. v1 sends no email (no sender mailbox yet).
        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->char('locale', 2);
            $table->char('unsubscribe_token', 40)->unique();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
        Schema::dropIfExists('article_daily_views');
        Schema::dropIfExists('article_status_changes');
        Schema::dropIfExists('article_tag');
        Schema::dropIfExists('article_translations');
        Schema::dropIfExists('articles');
    }
};
