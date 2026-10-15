<?php

namespace Tests\Feature\Admin;

use App\Enums\LiveProvider;
use App\Enums\LiveStatus;
use App\Enums\Locale;
use App\Filament\Resources\LiveStreams\Pages\CreateLiveStream;
use App\Filament\Resources\LiveStreams\Pages\EditLiveStream;
use App\Filament\Resources\LiveStreams\Support\LiveStreamWriter;
use App\Filament\Support\StatusPresenter;
use App\Models\Governorate;
use App\Models\LiveStream;
use App\Models\LiveStreamTranslation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

class LiveStreamFormTest extends AdminTestCase
{
    private const YT = 'dQw4w9WgXcQ';

    private function editor(): User
    {
        return tap(User::factory()->editor()->create(), fn (User $user) => $this->actingAs($user));
    }

    private function governorate(string $slug = 'cairo', string $english = 'Cairo', string $arabic = 'القاهرة', int $sort = 1): Governorate
    {
        return Governorate::factory()->named($slug, $english, $arabic, $sort)->create();
    }

    /** What the form posts for a typical new broadcast; $override replaces any key. */
    private function entry(array $override = []): array
    {
        return [
            'video_link' => 'https://www.youtube.com/watch?v='.self::YT,
            'ar' => ['title' => 'بث مباشر من القاهرة', 'description' => 'تغطية مباشرة.'],
            'en' => ['title' => 'Live from Cairo', 'description' => 'Live coverage.'],
            ...$override,
        ];
    }

    private function submit(array $override = []): Testable
    {
        return Livewire::test(CreateLiveStream::class)->fillForm($this->entry($override))->call('create');
    }

    private function onlyStream(): LiveStream
    {
        return LiveStream::query()->with('translations')->sole();
    }

    private function translation(LiveStream $stream, Locale $locale): ?LiveStreamTranslation
    {
        return LiveStreamTranslation::query()->where('live_stream_id', $stream->getKey())->where('locale', $locale->value)->first();
    }

    // --- creating -------------------------------------------------------------------------------

    public function test_an_editor_registers_a_broadcast_from_a_pasted_youtube_link(): void
    {
        $editor = $this->editor();
        $cairo = $this->governorate();
        $at = CarbonImmutable::now(StatusPresenter::TIMEZONE)->addDays(2)->setTime(18, 30);

        $this->submit(['governorate_id' => $cairo->getKey(), 'scheduled_at' => $at->format('Y-m-d H:i:s')])
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $stream = $this->onlyStream();
        $this->assertSame(LiveProvider::YouTube, $stream->provider);
        $this->assertSame(self::YT, $stream->video_ref);
        $this->assertSame($cairo->getKey(), $stream->governorate_id);
        $this->assertTrue($stream->scheduled_at->equalTo($at), 'the Cairo time that was picked is the stored instant');
        $this->assertSame($editor->getKey(), $stream->created_by);
        $this->assertSame(LiveStatus::Scheduled, $stream->status, 'a new broadcast is never on air');
        $this->assertNull($stream->started_at);
        $this->assertNull($stream->ended_at);
        $this->assertCount(2, $stream->translations);
        $this->assertSame('بث مباشر من القاهرة', $this->translation($stream, Locale::Ar)->title);
        $this->assertSame('تغطية مباشرة.', $this->translation($stream, Locale::Ar)->description);
        $this->assertSame('Live from Cairo', $this->translation($stream, Locale::En)->title);
    }

    public function test_only_the_provider_and_the_id_are_stored_never_the_pasted_text(): void
    {
        $link = 'https://www.youtube.com/watch?v='.self::YT.'&t=42s&list=PL123&si=tracking';
        $this->editor();

        $this->submit(['video_link' => $link])->assertHasNoFormErrors();

        $row = (array) DB::table('live_streams')->sole();
        $this->assertSame('youtube', $row['provider']);
        $this->assertSame(self::YT, $row['video_ref']);
        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString('http', (string) $value, "{$column} must not hold the pasted link");
            $this->assertStringNotContainsString('tracking', (string) $value, "{$column} must not hold the link's query");
        }
        foreach (DB::table('live_stream_translations')->get() as $translation) {
            $this->assertStringNotContainsString('youtube', json_encode($translation));
        }
    }

    /** @return array<string, array{0: string, 1: LiveProvider, 2: string}> */
    public static function pastedLinks(): array
    {
        return [
            'watch link' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', LiveProvider::YouTube, 'dQw4w9WgXcQ'],
            'mobile watch link' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ&t=5', LiveProvider::YouTube, 'dQw4w9WgXcQ'],
            'short link' => ['https://youtu.be/dQw4w9WgXcQ?si=abc', LiveProvider::YouTube, 'dQw4w9WgXcQ'],
            'live link' => ['https://www.youtube.com/live/dQw4w9WgXcQ?feature=share', LiveProvider::YouTube, 'dQw4w9WgXcQ'],
            'embed link' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', LiveProvider::YouTube, 'dQw4w9WgXcQ'],
            'bare YouTube id' => ['dQw4w9WgXcQ', LiveProvider::YouTube, 'dQw4w9WgXcQ'],
            'vimeo link' => ['https://vimeo.com/123456789', LiveProvider::Vimeo, '123456789'],
            'vimeo player link' => ['https://player.vimeo.com/video/123456789?h=abc', LiveProvider::Vimeo, '123456789'],
            'bare vimeo id' => ['123456789', LiveProvider::Vimeo, '123456789'],
            'with spaces around it' => ['   https://youtu.be/dQw4w9WgXcQ  ', LiveProvider::YouTube, 'dQw4w9WgXcQ'],
            'with an invisible right-to-left mark in front, as pasted from a chat' => ["\u{200F}https://youtu.be/dQw4w9WgXcQ\u{200F}", LiveProvider::YouTube, 'dQw4w9WgXcQ'],
            'with a no-break space behind it' => ["https://vimeo.com/123456789\u{00A0}", LiveProvider::Vimeo, '123456789'],
        ];
    }

    #[DataProvider('pastedLinks')]
    public function test_each_accepted_shape_is_stored_as_provider_and_id(string $pasted, LiveProvider $provider, string $id): void
    {
        $this->editor();

        $this->submit(['video_link' => $pasted])->assertHasNoFormErrors();

        $stream = $this->onlyStream();
        $this->assertSame($provider, $stream->provider);
        $this->assertSame($id, $stream->video_ref);
        $this->assertSame($provider->embedUrl($id), $stream->embedUrl());
    }

    /** @return array<string, array{0: string}> */
    public static function refusedLinks(): array
    {
        return [
            'plain http' => ['http://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            'plain http short link' => ['http://youtu.be/dQw4w9WgXcQ'],
            'another host' => ['https://evil.com/watch?v=dQw4w9WgXcQ'],
            'a look-alike host' => ['https://youtube.com.evil.com/watch?v=dQw4w9WgXcQ'],
            'a YouTube address inside another host path' => ['https://evil.com/youtu.be/dQw4w9WgXcQ'],
            'a different video site' => ['https://www.dailymotion.com/video/x8abcde'],
            'javascript' => ['javascript:alert(1)'],
            'uppercase javascript' => ['JAVASCRIPT:alert(document.cookie)'],
            'a data url' => ['data:text/html,<script>alert(1)</script>'],
            'markup' => ['<iframe src="https://evil.example"></iframe>'],
            'a YouTube short' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ'],
            'a channel page' => ['https://www.youtube.com/@aljazeera/live'],
            'a Vimeo private hash link' => ['https://vimeo.com/123456789/abcdef1234'],
            'a sentence' => ['the live stream starts at nine'],
            'a link without a scheme' => ['www.youtube.com/watch?v=dQw4w9WgXcQ'],
            'a Cyrillic look-alike host' => ['https://www.yоutube.com/watch?v=dQw4w9WgXcQ'],
            'an id that is one character short' => ['dQw4w9WgXc'],
            'a very long text' => [str_repeat('a', 6000)],
            'a very long link' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&'.str_repeat('x=1&', 3000)],
            'a link just over the length limit' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&junk='.str_repeat('a', 300)],
        ];
    }

    #[DataProvider('refusedLinks')]
    public function test_every_other_link_is_refused_with_a_clear_message_and_nothing_is_saved(string $pasted): void
    {
        $this->editor();

        // The box under the field says the link was not recognised (the error itself: see the test below).
        $this->submit(['video_link' => $pasted])
            ->assertHasFormErrors(['video_link'])
            ->assertSee('Not recognised');

        $this->assertSame(0, LiveStream::query()->count());
        $this->assertSame(0, LiveStreamTranslation::query()->count());
    }

    public function test_the_refusal_names_what_is_accepted(): void
    {
        $this->editor();

        $this->submit(['video_link' => 'https://example.com/live'])
            ->assertSee('Not a YouTube or Vimeo video')
            ->assertSee('https://')
            ->assertSee('video ID');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function linksPeopleCopyThatCannotWork(): array
    {
        return [
            'a channel live page' => ['https://www.youtube.com/@aljazeera/live', LiveStreamWriter::YOUTUBE_CHANNEL_ERROR],
            'a channel page by id' => ['https://www.youtube.com/channel/UC1234567890abcdefghij/live', LiveStreamWriter::YOUTUBE_CHANNEL_ERROR],
            'a channel home' => ['https://youtube.com/@sisinews', LiveStreamWriter::YOUTUBE_CHANNEL_ERROR],
            'a Vimeo event' => ['https://vimeo.com/event/1234567', LiveStreamWriter::VIMEO_EVENT_ERROR],
            'a Vimeo event embed' => ['https://vimeo.com/event/1234567/embed', LiveStreamWriter::VIMEO_EVENT_ERROR],
            'a private Vimeo link' => ['https://vimeo.com/123456789/abcdef1234', LiveStreamWriter::VIMEO_PRIVATE_ERROR],
            'anything else' => ['https://example.com/live', LiveStreamWriter::LINK_ERROR],
            'plain http channel' => ['http://www.youtube.com/@aljazeera/live', LiveStreamWriter::LINK_ERROR],
        ];
    }

    #[DataProvider('linksPeopleCopyThatCannotWork')]
    public function test_a_link_people_often_copy_is_refused_with_what_to_do_instead(string $pasted, string $message): void
    {
        $this->editor();
        $this->assertSame($message, LiveStreamWriter::refusal($pasted));

        $this->submit(['video_link' => $pasted])->assertHasFormErrors(['video_link'])->assertSee($message);
        $this->assertSame(0, LiveStream::query()->count());

        Livewire::test(CreateLiveStream::class)
            ->fillForm(['video_link' => $pasted])
            ->assertSee($message === LiveStreamWriter::LINK_ERROR ? 'Not recognised: only YouTube and Vimeo video links' : 'Not recognised. That is a');
    }

    public function test_the_box_warns_that_a_channel_page_or_vimeo_event_will_not_work(): void
    {
        $this->editor();

        Livewire::test(CreateLiveStream::class)->assertSee('A channel&#039;s live page or a Vimeo event link will not work.', false);
    }

    public function test_the_link_is_required(): void
    {
        $this->editor();

        $this->submit(['video_link' => ''])->assertHasFormErrors(['video_link' => 'required']);
        $this->submit(['video_link' => '   '])->assertHasFormErrors(['video_link']);

        $this->assertSame(0, LiveStream::query()->count());
    }

    public function test_the_box_says_what_it_recognised_before_anything_is_saved(): void
    {
        $this->editor();

        Livewire::test(CreateLiveStream::class)
            ->assertSee('Paste a YouTube or Vimeo link')
            ->fillForm(['video_link' => 'https://youtu.be/'.self::YT])
            ->assertSee('Recognised: YouTube video '.self::YT)
            ->fillForm(['video_link' => 'https://vimeo.com/123456789'])
            ->assertSee('Recognised: Vimeo video 123456789')
            ->fillForm(['video_link' => 'https://evil.example/x'])
            ->assertSee('Not recognised');
    }

    public function test_the_writer_refuses_a_bad_link_even_if_the_form_check_were_skipped(): void
    {
        $user = User::factory()->editor()->create();

        foreach (['javascript:alert(1)', 'http://youtu.be/'.self::YT, '', 'https://evil.com/'.self::YT, str_repeat('a', 9000)] as $bad) {
            try {
                LiveStreamWriter::create($this->entry(['video_link' => $bad]), $user);
                $this->fail("Accepted: {$bad}");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('data.video_link', $exception->errors());
            }
        }

        $this->assertSame(0, LiveStream::query()->count());
    }

    // --- languages ------------------------------------------------------------------------------

    public function test_a_broadcast_may_start_in_arabic_only(): void
    {
        $this->editor();

        $this->submit(['en' => ['title' => '', 'description' => '']])->assertHasNoFormErrors();

        $stream = $this->onlyStream();
        $this->assertCount(1, $stream->translations);
        $this->assertNotNull($this->translation($stream, Locale::Ar));
        $this->assertNull($this->translation($stream, Locale::En));
    }

    public function test_a_broadcast_may_start_in_english_only(): void
    {
        $this->editor();

        $this->submit(['ar' => ['title' => '', 'description' => '']])->assertHasNoFormErrors();

        $stream = $this->onlyStream();
        $this->assertCount(1, $stream->translations);
        $this->assertNotNull($this->translation($stream, Locale::En));
        $this->assertNull($this->translation($stream, Locale::Ar));
    }

    public function test_one_language_title_is_required(): void
    {
        $this->editor();

        $this->submit(['ar' => ['title' => '', 'description' => ''], 'en' => ['title' => '', 'description' => '']])
            ->assertHasFormErrors(['ar.title' => 'required', 'en.title' => 'required']);

        $this->submit(['ar' => ['title' => '   ', 'description' => ''], 'en' => ['title' => '   ', 'description' => '']])
            ->assertHasFormErrors(['ar.title', 'en.title']);

        $this->assertSame(0, LiveStream::query()->count());
    }

    public function test_a_description_without_a_title_in_the_same_language_is_refused(): void
    {
        $this->editor();

        $this->submit(['en' => ['title' => '', 'description' => 'A description with no headline.']])
            ->assertHasFormErrors(['en.title' => 'required']);

        $this->assertSame(0, LiveStream::query()->count());
    }

    public function test_a_title_is_limited_to_what_the_column_holds(): void
    {
        $this->editor();

        $this->submit(['ar' => ['title' => str_repeat('ب', 161), 'description' => '']])->assertHasFormErrors(['ar.title' => 'max']);
        $this->submit(['ar' => ['title' => str_repeat('ب', 160), 'description' => '']])->assertHasNoFormErrors();

        $this->assertSame(160, mb_strlen($this->translation($this->onlyStream(), Locale::Ar)->title));
    }

    public function test_titles_and_descriptions_are_trimmed_and_a_blank_description_is_stored_as_null(): void
    {
        $this->editor();

        $this->submit(['ar' => ['title' => '  عنوان  ', 'description' => "  \n "], 'en' => ['title' => ' Title ', 'description' => ' Text ']])
            ->assertHasNoFormErrors();

        $stream = $this->onlyStream();
        $this->assertSame('عنوان', $this->translation($stream, Locale::Ar)->title);
        $this->assertNull($this->translation($stream, Locale::Ar)->description);
        $this->assertSame('Title', $this->translation($stream, Locale::En)->title);
        $this->assertSame('Text', $this->translation($stream, Locale::En)->description);
    }

    public function test_the_language_tabs_come_arabic_first_and_the_fields_carry_their_direction(): void
    {
        $this->editor();

        $component = Livewire::test(CreateLiveStream::class);

        $component->assertSeeInOrder(['Arabic', 'English']);
        $html = $component->html();
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('lang="ar"', $html);
        $this->assertStringContainsString('dir="ltr"', $html);
    }

    // --- governorate ----------------------------------------------------------------------------

    public function test_the_governorate_choice_is_optional_and_empty_means_egypt_wide(): void
    {
        $this->editor();
        $this->governorate();

        Livewire::test(CreateLiveStream::class)
            ->assertFormFieldExists('governorate_id', fn (Select $select): bool => ! $select->isRequired() && $select->getPlaceholder() === 'Egypt-wide');

        $this->submit(['governorate_id' => null])->assertHasNoFormErrors();

        $this->assertNull($this->onlyStream()->governorate_id);
    }

    public function test_the_governorate_options_show_both_names_in_list_order(): void
    {
        $this->editor();
        $this->governorate('giza', 'Giza', 'الجيزة', 2);
        $this->governorate('cairo', 'Cairo', 'القاهرة', 1);

        Livewire::test(CreateLiveStream::class)
            ->assertFormFieldExists('governorate_id', function (Select $select): bool {
                $options = $select->getOptions();

                return array_values($options) === ['Cairo · القاهرة', 'Giza · الجيزة'] && $select->isSearchable();
            });
    }

    public function test_a_governorate_that_does_not_exist_is_refused_even_if_posted_by_hand(): void
    {
        $this->editor();
        $this->governorate();

        $this->submit(['governorate_id' => 987654])->assertHasFormErrors(['governorate_id']);

        $this->assertSame(0, LiveStream::query()->count());
    }

    // --- scheduling -----------------------------------------------------------------------------

    public function test_the_scheduled_time_is_optional_and_read_as_cairo_time(): void
    {
        $this->editor();

        Livewire::test(CreateLiveStream::class)
            ->assertFormFieldExists('scheduled_at', fn (DateTimePicker $picker): bool => $picker->getLabel() === 'Scheduled for'
                && $picker->getTimezone() === StatusPresenter::TIMEZONE
                && ! $picker->isRequired());

        $this->submit(['scheduled_at' => null])->assertHasNoFormErrors();

        $this->assertNull($this->onlyStream()->scheduled_at);
    }

    public function test_a_time_beyond_a_year_ahead_is_a_form_error_not_a_database_error(): void
    {
        $this->editor();

        $this->submit(['scheduled_at' => '2040-01-01 09:00:00'])->assertHasFormErrors(['scheduled_at']);

        $this->assertSame(0, LiveStream::query()->count());
    }

    // --- what a hand-made request cannot do ----------------------------------------------------

    public function test_status_and_the_other_controlled_columns_are_not_form_fields(): void
    {
        $this->editor();

        $component = Livewire::test(CreateLiveStream::class);
        foreach (['status', 'started_at', 'ended_at', 'created_by', 'provider', 'video_ref'] as $name) {
            $component->assertFormFieldDoesNotExist($name);
        }
    }

    public function test_a_crafted_request_cannot_create_a_broadcast_that_is_already_live(): void
    {
        $editor = $this->editor();

        Livewire::test(CreateLiveStream::class)
            ->fillForm($this->entry())
            ->set('data.status', 'live')
            ->set('data.started_at', now()->toDateTimeString())
            ->set('data.ended_at', now()->toDateTimeString())
            ->set('data.created_by', 987654)
            ->set('data.provider', 'vimeo')
            ->set('data.video_ref', '999999999')
            ->call('create')
            ->assertHasNoFormErrors();

        $stream = $this->onlyStream();
        $this->assertSame(LiveStatus::Scheduled, $stream->status);
        $this->assertNull($stream->started_at);
        $this->assertNull($stream->ended_at);
        $this->assertSame($editor->getKey(), $stream->created_by);
        $this->assertSame(LiveProvider::YouTube, $stream->provider);
        $this->assertSame(self::YT, $stream->video_ref);
        $this->assertSame(0, LiveStream::query()->liveNow()->count());
    }

    public function test_the_writer_ignores_status_and_times_even_when_handed_them_directly(): void
    {
        $user = User::factory()->editor()->create();

        $stream = LiveStreamWriter::create($this->entry(['status' => 'live', 'started_at' => now(), 'created_by' => 987654, 'id' => 4242]), $user);

        $this->assertSame(LiveStatus::Scheduled, $stream->status);
        $this->assertNull($stream->started_at);
        $this->assertSame($user->getKey(), $stream->created_by);
        $this->assertNotSame(4242, $stream->getKey());
    }

    // --- editing --------------------------------------------------------------------------------

    private function stored(array $attributes = []): LiveStream
    {
        return LiveStream::factory()->withTranslations('بث تجريبي', 'Test broadcast')->create($attributes);
    }

    public function test_the_edit_form_shows_the_stored_values_and_a_link_that_reads_back_to_the_same_video(): void
    {
        $this->editor();
        $cairo = $this->governorate();
        $stream = $this->stored(['governorate_id' => $cairo->getKey(), 'video_ref' => self::YT]);

        Livewire::test(EditLiveStream::class, ['record' => $stream->getKey()])
            ->assertSet('data.governorate_id', $cairo->getKey())
            ->assertSet('data.video_link', 'https://www.youtube.com/watch?v='.self::YT)
            ->assertSet('data.ar.title', 'بث تجريبي')
            ->assertSet('data.en.title', 'Test broadcast');

        $vimeo = $this->stored()->forceFill(['provider' => LiveProvider::Vimeo, 'video_ref' => '123456789']);
        $vimeo->save();
        Livewire::test(EditLiveStream::class, ['record' => $vimeo->getKey()])
            ->assertSet('data.video_link', 'https://vimeo.com/123456789');
    }

    public function test_saving_an_untouched_form_changes_nothing_including_the_scheduled_instant(): void
    {
        $this->editor();
        $at = CarbonImmutable::now()->addDays(3)->startOfMinute();
        $stream = $this->stored(['scheduled_at' => $at, 'video_ref' => self::YT]);

        Livewire::test(EditLiveStream::class, ['record' => $stream->getKey()])->call('save')->assertHasNoFormErrors();

        $fresh = $stream->fresh();
        $this->assertTrue($fresh->scheduled_at->equalTo($at), 'saving must not shift the time by the Cairo offset');
        $this->assertSame(self::YT, $fresh->video_ref);
        $this->assertSame(LiveProvider::YouTube, $fresh->provider);
        $this->assertSame(['بث تجريبي', 'Test broadcast'], $fresh->translations()->orderBy('id')->pluck('title')->all());
    }

    public function test_an_editor_changes_the_link_governorate_time_and_titles(): void
    {
        $this->editor();
        $giza = $this->governorate('giza', 'Giza', 'الجيزة', 2);
        $stream = $this->stored(['video_ref' => 'AAAAAAAAAAA']);
        $at = CarbonImmutable::now(StatusPresenter::TIMEZONE)->addDays(4)->setTime(10, 0);

        Livewire::test(EditLiveStream::class, ['record' => $stream->getKey()])
            ->fillForm([
                'video_link' => 'https://vimeo.com/123456789',
                'governorate_id' => $giza->getKey(),
                'scheduled_at' => $at->format('Y-m-d H:i:s'),
                'ar' => ['title' => 'عنوان جديد', 'description' => 'وصف جديد'],
                'en' => ['title' => 'New title'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $stream->fresh();
        $this->assertSame(LiveProvider::Vimeo, $fresh->provider);
        $this->assertSame('123456789', $fresh->video_ref);
        $this->assertSame($giza->getKey(), $fresh->governorate_id);
        $this->assertTrue($fresh->scheduled_at->equalTo($at));
        $this->assertSame('عنوان جديد', $this->translation($fresh, Locale::Ar)->title);
        $this->assertSame('وصف جديد', $this->translation($fresh, Locale::Ar)->description);
        $this->assertSame('New title', $this->translation($fresh, Locale::En)->title);
        $this->assertSame(2, LiveStreamTranslation::query()->count(), 'rows are updated, not duplicated');
    }

    public function test_the_governorate_can_be_cleared_back_to_egypt_wide(): void
    {
        $this->editor();
        $stream = $this->stored(['governorate_id' => $this->governorate()->getKey()]);

        Livewire::test(EditLiveStream::class, ['record' => $stream->getKey()])
            ->fillForm(['governorate_id' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($stream->fresh()->governorate_id);
    }

    public function test_clearing_a_language_title_removes_that_language_but_the_last_one_cannot_be_removed(): void
    {
        $this->editor();
        $stream = $this->stored();

        Livewire::test(EditLiveStream::class, ['record' => $stream->getKey()])
            ->fillForm(['en' => ['title' => '', 'description' => '']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($this->translation($stream, Locale::En));
        $this->assertNotNull($this->translation($stream, Locale::Ar));

        Livewire::test(EditLiveStream::class, ['record' => $stream->getKey()])
            ->fillForm(['ar' => ['title' => '', 'description' => '']])
            ->call('save')
            ->assertHasFormErrors(['ar.title' => 'required', 'en.title' => 'required']);

        $this->assertNotNull($this->translation($stream, Locale::Ar), 'a broadcast always keeps a title');
    }

    public function test_a_language_can_be_added_later(): void
    {
        $this->editor();
        $stream = LiveStream::factory()->arabicOnly('عنوان')->create();

        Livewire::test(EditLiveStream::class, ['record' => $stream->getKey()])
            ->fillForm(['en' => ['title' => 'Now in English', 'description' => 'Added later.']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Now in English', $this->translation($stream, Locale::En)->title);
        $this->assertSame('عنوان', $this->translation($stream, Locale::Ar)->title);
    }

    public function test_an_edit_with_a_bad_link_changes_nothing(): void
    {
        $this->editor();
        $stream = $this->stored(['video_ref' => self::YT]);

        Livewire::test(EditLiveStream::class, ['record' => $stream->getKey()])
            ->fillForm(['video_link' => 'http://youtu.be/'.self::YT, 'ar' => ['title' => 'يجب ألا يُحفظ']])
            ->call('save')
            ->assertHasFormErrors(['video_link']);

        $fresh = $stream->fresh();
        $this->assertSame(self::YT, $fresh->video_ref);
        $this->assertSame('بث تجريبي', $this->translation($fresh, Locale::Ar)->title);
    }

    public function test_an_overdue_scheduled_time_can_still_be_saved(): void
    {
        $this->editor();
        $stream = $this->stored(['scheduled_at' => CarbonImmutable::now()->subHours(3)->startOfMinute()]);

        Livewire::test(EditLiveStream::class, ['record' => $stream->getKey()])
            ->fillForm(['ar' => ['title' => 'عنوان معدل']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('عنوان معدل', $this->translation($stream, Locale::Ar)->title);
    }

    public function test_a_crafted_edit_cannot_change_the_status_or_the_times(): void
    {
        $this->editor();
        $startedAt = CarbonImmutable::now()->subHour()->startOfSecond();
        $scheduled = $this->stored();
        $live = $this->stored();
        $live->forceFill(['status' => LiveStatus::Live, 'started_at' => $startedAt])->save();
        $ended = LiveStream::factory()->ended()->withTranslations()->create();

        Livewire::test(EditLiveStream::class, ['record' => $scheduled->getKey()])
            ->set('data.status', 'live')
            ->set('data.started_at', now()->toDateTimeString())
            ->call('save');
        Livewire::test(EditLiveStream::class, ['record' => $live->getKey()])
            ->set('data.status', 'ended')
            ->set('data.ended_at', now()->toDateTimeString())
            ->set('data.started_at', now()->subDays(5)->toDateTimeString())
            ->call('save');
        Livewire::test(EditLiveStream::class, ['record' => $ended->getKey()])
            ->set('data.status', 'live')
            ->call('save');

        $this->assertSame(LiveStatus::Scheduled, $scheduled->fresh()->status);
        $this->assertNull($scheduled->fresh()->started_at);
        $this->assertSame(LiveStatus::Live, $live->fresh()->status);
        $this->assertNull($live->fresh()->ended_at);
        $this->assertTrue($live->fresh()->started_at->equalTo($startedAt));
        $this->assertSame(LiveStatus::Ended, $ended->fresh()->status);
    }

    public function test_a_live_broadcast_can_be_edited_while_it_is_on_air_and_stays_live(): void
    {
        $this->editor();
        $stream = LiveStream::factory()->live()->withTranslations()->create(['video_ref' => 'AAAAAAAAAAA']);
        $startedAt = $stream->fresh()->started_at;

        Livewire::test(EditLiveStream::class, ['record' => $stream->getKey()])
            ->fillForm(['video_link' => 'https://youtu.be/BBBBBBBBBBB'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $stream->fresh();
        $this->assertSame('BBBBBBBBBBB', $fresh->video_ref, 'the stream link can be swapped while on air');
        $this->assertSame(LiveStatus::Live, $fresh->status);
        $this->assertTrue($fresh->started_at->equalTo($startedAt));
    }

    public function test_the_edit_page_says_where_the_broadcast_stands(): void
    {
        $this->editor();
        $scheduled = $this->stored(['scheduled_at' => CarbonImmutable::now()->addDay()]);
        $live = LiveStream::factory()->live()->withTranslations()->create();
        $ended = LiveStream::factory()->ended()->withTranslations()->create();

        Livewire::test(EditLiveStream::class, ['record' => $scheduled->getKey()])->assertSee('Not live yet. Scheduled for');
        Livewire::test(EditLiveStream::class, ['record' => $live->getKey()])->assertSee('Live now, since');
        Livewire::test(EditLiveStream::class, ['record' => $ended->getKey()])->assertSee('Ended');
    }
}
