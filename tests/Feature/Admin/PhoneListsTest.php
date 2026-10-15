<?php

namespace Tests\Feature\Admin;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Filament\Resources\ReviewQueue\Pages\ListReviewQueue;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Support\PhoneFriendly;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Livewire\Livewire;

/**
 * At 390 px a row keeps the headline, a compact status and its buttons; the rest starts at md.
 * (Checked in a real 390 px browser too; these tests keep the breakpoints from drifting.)
 */
class PhoneListsTest extends AdminTestCase
{
    private function isIconOnPhone(Action $action): bool
    {
        return str_contains((string) $action->getExtraAttributeBag()->get('class'), PhoneFriendly::ICON_ONLY_CLASS);
    }

    public function test_the_article_list_keeps_headline_and_status_on_a_phone_and_defers_the_rest_to_md(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $table = Livewire::test(ListArticles::class)->instance()->getTable();

        foreach (['hero_image', 'ar_title', 'en_title', 'category', 'author.name', 'is_breaking', 'updated_at'] as $secondary) {
            $this->assertSame('md', $table->getColumn($secondary)->getVisibleFrom(), "{$secondary} must wait for md");
        }

        $headline = $table->getColumn('headline');
        $this->assertSame('md', $headline->getHiddenFrom(), 'the phone headline gives way to the two language columns at md');
        $this->assertNull($headline->getVisibleFrom());

        $status = $table->getColumn('versions');
        $this->assertNull($status->getVisibleFrom());
        $this->assertNull($status->getHiddenFrom());
    }

    public function test_the_phone_headline_is_the_arabic_one_or_the_english_one_when_there_is_no_arabic(): void
    {
        $author = User::factory()->journalist()->create();
        $both = $this->story($author, TranslationStatus::Draft, TranslationStatus::Draft);
        $englishOnly = Article::factory()->create(['author_id' => $author->getKey(), 'category_id' => $this->category()->getKey()]);
        ArticleTranslation::factory()->status(TranslationStatus::Draft)->create(['article_id' => $englishOnly->getKey(), 'title' => 'Only in English']);
        $this->actingAs($author);

        Livewire::test(ListArticles::class)
            ->assertTableColumnStateSet('headline', $this->version($both, Locale::Ar)->title, $both)
            ->assertTableColumnStateSet('headline', 'Only in English', $englishOnly->load('translations'));
    }

    public function test_searching_still_finds_a_story_by_either_headline_on_a_phone(): void
    {
        $author = User::factory()->journalist()->create();
        $story = $this->story($author, TranslationStatus::Draft, TranslationStatus::Draft);
        $this->version($story, Locale::En)->forceFill(['title' => 'Zanzibar harbour reopens'])->save();
        $this->story($author);
        $this->actingAs($author);

        Livewire::test(ListArticles::class)
            ->searchTable('Zanzibar')
            ->assertCountTableRecords(1)
            ->assertCanSeeTableRecords([$story]);
    }

    public function test_the_row_button_is_an_icon_on_a_phone(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $table = Livewire::test(ListArticles::class)->instance()->getTable();

        $this->assertTrue($this->isIconOnPhone($table->getAction('edit')));
    }

    public function test_the_review_queue_keeps_headline_language_and_both_buttons_on_a_phone(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $table = Livewire::test(ListReviewQueue::class)->instance()->getTable();

        foreach (['title', 'locale'] as $primary) {
            $this->assertNull($table->getColumn($primary)->getVisibleFrom(), "{$primary} stays on a phone");
        }
        foreach (['article.author.name', 'submitted_at'] as $secondary) {
            $this->assertSame('md', $table->getColumn($secondary)->getVisibleFrom(), "{$secondary} must wait for md");
        }
        // Icon-only below md, so both workflow buttons fit beside the headline instead of scrolling away.
        foreach (['approve', 'requestChanges'] as $button) {
            $this->assertTrue($this->isIconOnPhone($table->getAction($button)), $button);
        }
    }

    public function test_the_team_list_keeps_name_role_and_state_on_a_phone_and_tucks_the_account_moves_into_a_menu(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $table = Livewire::test(ListUsers::class)->instance()->getTable();

        foreach (['name', 'role', 'is_active'] as $primary) {
            $this->assertNull($table->getColumn($primary)->getVisibleFrom(), "{$primary} stays on a phone");
        }
        $this->assertSame('lg', $table->getColumn('name_ar')->getVisibleFrom());

        $rowActions = $table->getRecordActions();
        $this->assertCount(2, $rowActions, 'Edit plus one menu: that is all a 390 px row has room for');
        $this->assertStringContainsString(PhoneFriendly::HIDDEN_CLASS, (string) $rowActions[0]->getExtraAttributeBag()->get('class'), 'on a phone the whole row opens the account instead');
        $this->assertNotNull($table->getRecordUrl(User::factory()->create()), 'the row is a link');
        $this->assertInstanceOf(ActionGroup::class, $rowActions[1]);
        foreach (['resetPassword', 'deactivate', 'activate'] as $move) {
            $this->assertInstanceOf(Action::class, $table->getAction($move), "{$move} is still a row action");
        }
    }
}
