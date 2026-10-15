<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\TranslationStatus;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Widgets\NewsroomStats;
use App\Models\User;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

/** The editor is called "Editor-in-chief" (رئيس التحرير) wherever staff read a role; the stored value is still "editor". */
class RoleLabelsTest extends AdminTestCase
{
    private function admin(): User
    {
        return tap(User::factory()->admin()->create(), fn (User $admin) => $this->actingAs($admin));
    }

    public function test_the_editor_reads_editor_in_chief_and_the_stored_value_stays_editor(): void
    {
        $this->assertSame('Journalist', Role::Journalist->label());
        $this->assertSame('Editor-in-chief', Role::Editor->label());
        $this->assertSame('Admin', Role::Admin->label());

        $this->assertSame('editor', Role::Editor->value);
        $editor = User::factory()->editor()->create();
        $this->assertSame('editor', $editor->fresh()->getRawOriginal('role'), 'no migration: the database still says editor');
        $this->assertSame(Role::Editor, $editor->fresh()->role);
    }

    public function test_the_team_form_offers_journalist_editor_in_chief_and_admin(): void
    {
        $this->admin();
        $expected = ['journalist' => 'Journalist', 'editor' => 'Editor-in-chief', 'admin' => 'Admin'];

        Livewire::test(CreateUser::class)
            ->assertFormFieldExists('role', fn (Select $role): bool => $role->getOptions() === $expected);

        $account = User::factory()->journalist()->create();
        Livewire::test(EditUser::class, ['record' => $account->getKey()])
            ->assertFormFieldExists('role', fn (Select $role): bool => $role->getOptions() === $expected);
    }

    public function test_the_team_list_names_the_role_in_its_badge_and_its_filter(): void
    {
        $this->admin();
        $editor = User::factory()->editor()->create();

        $page = Livewire::test(ListUsers::class)
            ->assertTableColumnFormattedStateSet('role', 'Editor-in-chief', $editor);

        $this->assertSame(
            ['journalist' => 'Journalist', 'editor' => 'Editor-in-chief', 'admin' => 'Admin'],
            $page->instance()->getTable()->getFilter('role')->getOptions(),
        );
    }

    public function test_the_dashboard_says_a_journalists_versions_wait_for_the_editor_in_chief(): void
    {
        $journalist = User::factory()->journalist()->create();
        $this->story($journalist, TranslationStatus::InReview, null);
        $this->actingAs($journalist);

        $stats = invade(Livewire::test(NewsroomStats::class)->instance())->getStats();
        $review = collect($stats)->first(fn ($stat): bool => (string) $stat->getLabel() === 'My versions in review');

        $this->assertSame('Waiting for the Editor-in-chief', (string) $review->getDescription());
    }

    public function test_the_submit_dialog_and_the_status_notes_name_the_editor_in_chief(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::Draft, null);
        $this->actingAs($author);

        $page = Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertSee('Only the author and the Editor-in-chief can see this draft')
            ->mountAction('ar_submit');

        $this->assertStringContainsString(
            'sent to the Editor-in-chief',
            (string) $page->instance()->getMountedAction()->getModalDescription(),
        );
    }
}
