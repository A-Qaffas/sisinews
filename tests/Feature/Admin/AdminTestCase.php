<?php

namespace Tests\Feature\Admin;

use App\Enums\Locale;
use App\Enums\Role;
use App\Enums\TranslationStatus;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\User;
use App\Support\Slugger;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class AdminTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The app default is Arabic (public site); the panel itself is English.
        app()->setLocale('en');
        Filament::setCurrentPanel('admin');
    }

    /** Number of SQL queries $callback runs. */
    protected function queryCount(Closure $callback): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    protected function category(): Category
    {
        return Category::factory()->create();
    }

    /** A section with known names, so a test can read them back in a select or a table. */
    protected function section(string $english, string $arabic, int $order = 0): Category
    {
        $category = Category::factory()->create(['sort_order' => $order]);

        foreach ([Locale::En->value => $english, Locale::Ar->value => $arabic] as $locale => $name) {
            CategoryTranslation::query()
                ->where('category_id', $category->getKey())
                ->where('locale', $locale)
                ->update(['name' => $name, 'slug' => Slugger::make($name)]);
        }

        return $category->load('translations');
    }

    /** A journalist an admin has assigned to $sections (none given: a journalist with no section yet). */
    protected function journalistIn(Category ...$sections): User
    {
        $journalist = User::factory()->journalist()->create();
        $journalist->allowedCategories()->attach(array_map(fn (Category $section): int => $section->getKey(), $sections));

        return $journalist;
    }

    /**
     * A story written by $author with an Arabic and an English version. In a section of its own, which a
     * journalist author is given, as if an admin had assigned it before they wrote the story. Pass $category
     * to put the story in a section you made: then the journalist's sections are left exactly as they are.
     */
    protected function story(User $author, TranslationStatus $ar = TranslationStatus::Draft, ?TranslationStatus $en = TranslationStatus::Draft, ?Category $category = null): Article
    {
        $ownSection = $category === null;
        $category ??= $this->category();
        $article = Article::factory()->create(['author_id' => $author->getKey(), 'category_id' => $category->getKey()]);

        if ($ownSection && $author->role === Role::Journalist) {
            $author->allowedCategories()->syncWithoutDetaching([$category->getKey()]);
        }

        ArticleTranslation::factory()->arabic()->status($ar)->create(['article_id' => $article->getKey()]);
        if ($en !== null) {
            ArticleTranslation::factory()->status($en)->create(['article_id' => $article->getKey()]);
        }

        return $article->load('translations');
    }

    protected function version(Article $article, Locale $locale): ArticleTranslation
    {
        return ArticleTranslation::query()->where('article_id', $article->getKey())->where('locale', $locale->value)->firstOrFail();
    }
}
