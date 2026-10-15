<?php

namespace Database\Factories;

use App\Enums\LiveProvider;
use App\Enums\LiveStatus;
use App\Enums\Locale;
use App\Models\Governorate;
use App\Models\LiveStream;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A YouTube broadcast that is scheduled, Egypt-wide and has no titles yet. Compose with the states:
 * ->live(), ->ended(), ->vimeo(), ->inGovernorate(), ->withTranslations() and so on. The model keeps status and the
 * timestamps out of mass assignment, but factories bypass that guard, so the states set them directly.
 *
 * @extends Factory<LiveStream>
 */
class LiveStreamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'governorate_id' => null,
            'provider' => LiveProvider::YouTube,
            // A YouTube id is 11 characters of letters, digits, "-" and "_"; Str::random only uses the first two.
            'video_ref' => Str::random(11),
            'status' => LiveStatus::Scheduled,
            'scheduled_at' => null,
            'started_at' => null,
            'ended_at' => null,
            'created_by' => null,
        ];
    }

    public function youtube(?string $id = null): static
    {
        return $this->state(['provider' => LiveProvider::YouTube, 'video_ref' => $id ?? Str::random(11)]);
    }

    public function vimeo(?string $id = null): static
    {
        return $this->state(['provider' => LiveProvider::Vimeo, 'video_ref' => $id ?? (string) fake()->numberBetween(100_000_000, 999_999_999)]);
    }

    /** Announced for a time (default tomorrow); it only goes live when someone presses the button. */
    public function scheduled(?\DateTimeInterface $at = null): static
    {
        return $this->state(fn (): array => [
            'status' => LiveStatus::Scheduled,
            'scheduled_at' => $at ?? now()->addDay(),
            'started_at' => null,
            'ended_at' => null,
        ]);
    }

    public function live(?\DateTimeInterface $since = null): static
    {
        return $this->state(fn (): array => [
            'status' => LiveStatus::Live,
            'started_at' => $since ?? now()->subMinutes(10),
            'ended_at' => null,
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn (): array => [
            'status' => LiveStatus::Ended,
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
        ]);
    }

    /** $governorate: a model, an id, a governorate factory, or null for a new made-up one. */
    public function inGovernorate(Governorate|GovernorateFactory|int|null $governorate = null): static
    {
        return $this->state(['governorate_id' => $governorate ?? Governorate::factory()]);
    }

    public function egyptWide(): static
    {
        return $this->state(['governorate_id' => null]);
    }

    public function createdBy(User|int $user): static
    {
        return $this->state(['created_by' => $user]);
    }

    /** Arabic and English titles (and descriptions); pass text to choose it. */
    public function withTranslations(?string $arabicTitle = null, ?string $englishTitle = null): static
    {
        return $this->afterCreating(function (LiveStream $stream) use ($arabicTitle, $englishTitle): void {
            $stream->translations()->create(['locale' => Locale::Ar, 'title' => $arabicTitle ?? 'بث مباشر '.fake()->numberBetween(100, 999), 'description' => 'وصف البث المباشر.']);
            $stream->translations()->create(['locale' => Locale::En, 'title' => $englishTitle ?? 'Live broadcast '.fake()->numberBetween(100, 999), 'description' => 'About this live broadcast.']);
            $stream->unsetRelation('translations');
        });
    }

    public function arabicOnly(?string $title = null): static
    {
        return $this->afterCreating(function (LiveStream $stream) use ($title): void {
            $stream->translations()->create(['locale' => Locale::Ar, 'title' => $title ?? 'بث مباشر '.fake()->numberBetween(100, 999), 'description' => null]);
            $stream->unsetRelation('translations');
        });
    }

    public function englishOnly(?string $title = null): static
    {
        return $this->afterCreating(function (LiveStream $stream) use ($title): void {
            $stream->translations()->create(['locale' => Locale::En, 'title' => $title ?? 'Live broadcast '.fake()->numberBetween(100, 999), 'description' => null]);
            $stream->unsetRelation('translations');
        });
    }
}
