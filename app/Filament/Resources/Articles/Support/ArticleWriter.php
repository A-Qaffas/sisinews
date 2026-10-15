<?php

namespace App\Filament\Resources\Articles\Support;

use App\Enums\Locale;
use App\Models\Article;
use App\Models\Category;
use App\Models\Tag;
use App\Models\User;
use App\Support\HtmlSanitizer;
use App\Support\Media\HeroImages;
use App\Support\Slugger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Persists the article form. Every write is re-checked against the policies here
 * (not only by disabling fields in the form), so a crafted payload cannot edit a
 * language or the shared shell the user has no right to touch. It never writes
 * `status` or `published_at`: only EditorialWorkflow does.
 */
final class ArticleWriter
{
    private const SHELL = ['category_id', 'hero_image', 'is_featured', 'is_breaking', 'breaking_until'];

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $by): Article
    {
        $versions = $this->versionsToWrite($data);

        if ($versions === []) {
            throw ValidationException::withMessages(['data.ar.title' => 'Write a title in at least one language.']);
        }

        $sectionId = $this->checkedSection($data, $by, null);

        return DB::transaction(function () use ($data, $by, $versions, $sectionId): Article {
            $article = new Article([...$this->shellAttributes($data), 'category_id' => $sectionId]);
            $article->author_id = $this->chosenAuthorId($data, $by) ?? $by->getKey();
            $article->save();

            $this->syncTags($article, $data);

            foreach ($versions as $locale => $fields) {
                $article->translations()->create([...$fields, 'locale' => $locale]);
            }

            $this->processHeroImage($article);

            return $article->load('translations');
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Article $article, array $data, User $by): Article
    {
        return DB::transaction(function () use ($article, $data, $by): Article {
            $article->load('translations');

            if (ArticleAccess::canEditShell($by, $article)) {
                $sectionId = $this->checkedSection($data, $by, $article);
                $article->fill([...$this->shellAttributes($data), ...($sectionId === null ? [] : ['category_id' => $sectionId])]);
                if (($authorId = $this->chosenAuthorId($data, $by)) !== null) {
                    $article->author_id = $authorId;
                }
                $article->save();
                $this->syncTags($article, $data);
            }

            foreach ($this->versionsToWrite($data) as $locale => $fields) {
                $locale = Locale::from($locale);
                if (! ArticleAccess::canEditTranslation($by, $article, $locale)) {
                    continue;
                }

                $existing = $article->translation($locale);
                $existing === null
                    ? $article->translations()->create([...$fields, 'locale' => $locale])
                    : $existing->fill($fields)->save();
            }

            $this->processHeroImage($article);

            return $article->load('translations');
        });
    }

    /**
     * The section a story is written into must exist and be one $by may write in (a journalist: only the
     * sections an admin assigned). The form's select already offers nothing else; this is the check a
     * tampered request meets. An update that leaves the section alone is not checked again: whoever may
     * edit the story already holds its current section (ArticlePolicy::update).
     *
     * @param  array<string, mixed>  $data
     * @return ?int the section to store, or null when an update does not touch it
     */
    private function checkedSection(array $data, User $by, ?Article $current): ?int
    {
        if ($current !== null && ! array_key_exists('category_id', $data)) {
            return null;
        }

        $id = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT);

        if ($current !== null && $id === (int) $current->category_id) {
            return $id;
        }
        if ($id === false || ! Category::query()->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['data.category_id' => 'Choose a section from the list.']);
        }
        if (! $by->canWriteIn($id)) {
            throw ValidationException::withMessages(['data.category_id' => 'You cannot write in that section. An admin chooses the sections each journalist may use.']);
        }

        return $id;
    }

    /** @param  array<string, mixed>  $data */
    private function shellAttributes(array $data): array
    {
        $shell = Arr::only($data, self::SHELL);
        if (! ($shell['is_breaking'] ?? false)) {
            $shell['breaking_until'] = null;
        }

        return $shell;
    }

    /**
     * Only reviewers may hand a story to someone else; the target must be active staff.
     *
     * @param  array<string, mixed>  $data
     */
    private function chosenAuthorId(array $data, User $by): ?int
    {
        if (! $by->isReviewer() || blank($data['author_id'] ?? null)) {
            return null;
        }

        return User::query()->whereKey($data['author_id'])->where('is_active', true)->value('id');
    }

    /** @param  array<string, mixed>  $data */
    private function syncTags(Article $article, array $data): void
    {
        if (array_key_exists('tags', $data)) {
            $article->tags()->sync(Tag::query()->whereKey(Arr::wrap($data['tags']))->pluck('id')->all());
        }
    }

    /**
     * A language is written only when its title is filled, so a story may exist in one language.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, mixed>> locale value => cleaned columns
     */
    private function versionsToWrite(array $data): array
    {
        $versions = [];
        foreach (Locale::cases() as $locale) {
            $fields = $data[$locale->value] ?? null;
            if (is_array($fields) && filled(trim((string) ($fields['title'] ?? '')))) {
                $versions[$locale->value] = $this->cleanVersion($fields);
            }
        }

        return $versions;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, ?string>
     */
    private function cleanVersion(array $fields): array
    {
        $title = trim((string) $fields['title']);

        return [
            'title' => $title,
            'slug' => Slugger::make((string) ($fields['slug'] ?? '')) ?: Slugger::make($title),
            'excerpt' => $this->nullIfBlank($fields['excerpt'] ?? null),
            'body' => $this->cleanBody($fields['body'] ?? null),
            'hero_caption' => $this->nullIfBlank($fields['hero_caption'] ?? null),
            'meta_description' => $this->nullIfBlank($fields['meta_description'] ?? null),
        ];
    }

    private function cleanBody(mixed $body): ?string
    {
        $html = is_string($body) ? $body : '';
        $hasContent = trim(strip_tags($html)) !== '' || str_contains($html, '<img');

        return $hasContent ? HtmlSanitizer::clean($html) : null;
    }

    private function nullIfBlank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function processHeroImage(Article $article): void
    {
        if (filled($article->hero_image) && ($article->wasRecentlyCreated || $article->wasChanged('hero_image'))) {
            HeroImages::process($article->hero_image);
        }
    }
}
