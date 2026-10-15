<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The 27 governorates of Egypt: reference data seeded once, never edited in the CMS.
        Schema::create('governorates', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name_ar', 60);
            $table->string('name_en', 60);
            $table->unsignedSmallInteger('sort_order')->default(0);
        });

        // A live broadcast hosted by an outside video service (YouTube or Vimeo). The site only EMBEDS it:
        // no video ever touches this server. governorate_id NULL = an Egypt-wide broadcast.
        Schema::create('live_streams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('governorate_id')->nullable()->constrained('governorates')->nullOnDelete();
            $table->string('provider', 12);
            $table->string('video_ref', 64);
            $table->string('status', 12)->default('scheduled');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'governorate_id']);
        });

        Schema::create('live_stream_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_stream_id')->constrained()->cascadeOnDelete();
            $table->char('locale', 2);
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->unique(['live_stream_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_stream_translations');
        Schema::dropIfExists('live_streams');
        Schema::dropIfExists('governorates');
    }
};
