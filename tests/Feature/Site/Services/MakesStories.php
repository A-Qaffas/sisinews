<?php

namespace Tests\Feature\Site\Services;

use App\Enums\Locale;
use App\Models\Article;
use App\Models\ArticleTranslation;
use Illuminate\Support\Facades\DB;

/** Builders shared by the public home and service tests. Not a test case itself. */
trait MakesStories
{
    /** A story that is visible to readers unless the overrides say otherwise. */
    protected function story(array $translation = [], array $article = [], Locale $locale = Locale::Ar): ArticleTranslation
    {
        $shell = Article::factory()->create($article);
        $factory = $locale === Locale::Ar ? ArticleTranslation::factory()->arabic() : ArticleTranslation::factory();

        return $factory->published()
            ->create(array_merge(['article_id' => $shell->id, 'locale' => $locale], $translation))
            ->fresh();
    }

    protected function addViews(ArticleTranslation $translation, int $views, ?string $day = null): void
    {
        DB::table('article_daily_views')->insert([
            'article_translation_id' => $translation->id,
            'day' => $day ?? now()->toDateString(),
            'views' => $views,
        ]);
    }

    /** Every public cache lifetime to zero so one test never sees another request's cached lists. */
    protected function disableSiteCache(): void
    {
        config(['site.cache' => array_map(fn () => 0, config('site.cache'))]);
    }

    /** @return list<string> */
    protected function queriesTouching(string $table, callable $work): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries, $table) {
            if (str_contains($query->sql, $table)) {
                $queries[] = $query->sql;
            }
        });

        $work();

        return $queries;
    }
}
