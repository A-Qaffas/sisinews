<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\NewsletterSubscribers\NewsletterSubscriberResource;
use App\Filament\Resources\NewsletterSubscribers\Pages\ListNewsletterSubscribers;
use App\Filament\Support\SubscriberCsv;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Livewire;

class NewsletterSubscriberTest extends AdminTestCase
{
    private function subscriber(string $email, string $locale = 'en', bool $unsubscribed = false): NewsletterSubscriber
    {
        $subscriber = NewsletterSubscriber::query()->create(['email' => $email, 'locale' => $locale]);

        if ($unsubscribed) {
            $subscriber->forceFill(['unsubscribed_at' => now()])->save();
        }

        return $subscriber;
    }

    private function csv(Builder $query): string
    {
        ob_start();
        SubscriberCsv::download($query)->sendContent();

        return (string) ob_get_clean();
    }

    public function test_an_editor_sees_the_list(): void
    {
        $subscriber = $this->subscriber('reader@example.com');
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(ListNewsletterSubscribers::class)->assertCanSeeTableRecords([$subscriber]);
    }

    public function test_a_journalist_cannot_see_subscriber_addresses(): void
    {
        $this->subscriber('reader@example.com');
        $this->actingAs(User::factory()->journalist()->create());

        Livewire::test(ListNewsletterSubscribers::class)->assertForbidden();
        $this->get(NewsletterSubscriberResource::getUrl('index'))->assertForbidden();
        $this->get('/admin')->assertDontSee('/admin/newsletter-subscribers');
    }

    public function test_the_list_is_read_only(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->assertFalse(NewsletterSubscriberResource::canCreate());
        $this->assertFalse(User::factory()->admin()->create()->can('update', $this->subscriber('a@example.com')));
        $this->assertArrayNotHasKey('create', NewsletterSubscriberResource::getPages());
        $this->assertArrayNotHasKey('edit', NewsletterSubscriberResource::getPages());
    }

    public function test_only_an_admin_may_delete_a_subscriber(): void
    {
        $subscriber = $this->subscriber('reader@example.com');

        $this->actingAs(User::factory()->editor()->create());
        Livewire::test(ListNewsletterSubscribers::class)->assertTableActionHidden('delete', $subscriber);
        $this->assertModelExists($subscriber);

        $this->actingAs(User::factory()->admin()->create());
        Livewire::test(ListNewsletterSubscribers::class)->callTableAction('delete', $subscriber);
        $this->assertModelMissing($subscriber);
    }

    public function test_the_csv_export_is_a_download_for_editors(): void
    {
        $this->subscriber('reader@example.com');
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(ListNewsletterSubscribers::class)
            ->callAction('export')
            ->assertFileDownloaded('subscribers-'.now()->format('Y-m-d').'.csv');
    }

    public function test_the_csv_has_a_utf8_bom_a_header_and_one_row_per_subscriber(): void
    {
        $this->subscriber('a@example.com', 'ar');
        $this->subscriber('b@example.com', 'en', unsubscribed: true);

        $csv = $this->csv(NewsletterSubscriber::query());

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = array_values(array_filter(explode("\n", trim(substr($csv, 3)))));
        $this->assertSame('Email,Language,Status,"Signed up (UTC)","Unsubscribed (UTC)"', $lines[0]);
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('a@example.com,ar,subscribed,', $csv);
        $this->assertStringContainsString('b@example.com,en,unsubscribed,', $csv);
    }

    public function test_spreadsheet_formulas_in_an_address_are_neutralised(): void
    {
        $this->subscriber('=HYPERLINK("http://evil.example")@example.com');
        $this->subscriber('+1555@example.com');
        $this->subscriber('safe@example.com');

        $csv = $this->csv(NewsletterSubscriber::query());

        $this->assertStringNotContainsString("\n=HYPERLINK", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+1555@example.com", $csv);
        $this->assertStringContainsString("\nsafe@example.com,", $csv);
    }

    public function test_the_export_follows_the_table_filters(): void
    {
        $this->subscriber('active@example.com');
        $this->subscriber('gone@example.com', 'en', unsubscribed: true);
        $this->actingAs(User::factory()->editor()->create());

        $page = Livewire::test(ListNewsletterSubscribers::class)->filterTable('subscribed', false);
        $csv = $this->csv($page->instance()->getTableQueryForExport());

        $this->assertStringContainsString('gone@example.com', $csv);
        $this->assertStringNotContainsString('active@example.com', $csv);
    }
}
