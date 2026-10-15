<?php

namespace Tests\Feature\Security;

use App\Enums\Locale;
use App\Models\Article;
use App\Models\ArticleTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ArticleBodySanitizingTest extends TestCase
{
    use RefreshDatabase;

    private const DIRTY = '<p onclick="alert(1)">Hello</p><script>alert(2)</script><a href="javascript:alert(3)">x</a>';

    private const CLEAN = '<p>Hello</p><a>x</a>';

    private function storedBody(ArticleTranslation $translation): ?string
    {
        return DB::table('article_translations')->where('id', $translation->id)->value('body');
    }

    public function test_create_stores_the_sanitised_body(): void
    {
        $translation = ArticleTranslation::factory()->create(['body' => self::DIRTY]);

        $this->assertSame(self::CLEAN, $this->storedBody($translation));
        $this->assertSame(self::CLEAN, $translation->body);
    }

    public function test_mass_assignment_through_create_and_fill_is_sanitised(): void
    {
        $article = Article::factory()->create();

        $created = ArticleTranslation::create([
            'article_id' => $article->id,
            'locale' => Locale::Ar,
            'title' => 'عنوان',
            'slug' => 'عنوان',
            'body' => self::DIRTY,
        ]);
        $this->assertSame(self::CLEAN, $this->storedBody($created));

        $filled = (new ArticleTranslation)->fill([
            'article_id' => $article->id,
            'locale' => Locale::En,
            'title' => 't',
            'slug' => 't',
            'body' => self::DIRTY,
        ]);
        $this->assertSame(self::CLEAN, $filled->body);
    }

    public function test_update_and_direct_assignment_are_sanitised(): void
    {
        $translation = ArticleTranslation::factory()->create();

        $translation->update(['body' => self::DIRTY]);
        $this->assertSame(self::CLEAN, $this->storedBody($translation));

        $translation->body = '<img src=x onerror=alert(1)>';
        $translation->save();
        $this->assertSame('<img src="x" alt="">', $this->storedBody($translation));

        $translation->forceFill(['body' => '<iframe src="https://evil.example"></iframe><p>ok</p>'])->save();
        $this->assertSame('<p>ok</p>', $this->storedBody($translation));
    }

    public function test_a_null_body_stays_null_and_a_blank_body_becomes_empty(): void
    {
        $translation = ArticleTranslation::factory()->create(['body' => null]);
        $this->assertNull($this->storedBody($translation));

        $translation->update(['body' => "  \n "]);
        $this->assertSame('', $this->storedBody($translation));
    }

    public function test_arabic_body_is_stored_intact(): void
    {
        $body = '<p dir="rtl" lang="ar">أعلنت الحكومة اليوم عن خطة جديدة لدعم الصناعة المحلية.</p>';

        $translation = ArticleTranslation::factory()->create(['body' => $body]);

        $this->assertSame($body, $this->storedBody($translation));
    }

    public function test_reading_does_not_re_sanitise_or_alter_stored_html(): void
    {
        $translation = ArticleTranslation::factory()->create(['body' => '<p>kept</p>']);

        $this->assertSame('<p>kept</p>', ArticleTranslation::find($translation->id)->body);
    }
}
