<?php

namespace App\Filament\Resources\LiveStreams\Support;

use App\Enums\LiveProvider;
use App\Enums\LiveStatus;
use App\Enums\Locale;
use App\Filament\Support\WorkflowOutcome;
use App\Models\Governorate;
use App\Models\LiveStream;
use Closure;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * The Go live and End buttons, shared by the list rows and the edit page. They only ever call
 * LiveStream::goLive()/end(); the policy says who may press them and the model says which moves are legal.
 * The confirmation names the broadcast, where it is and which video goes on air, because on a phone the row
 * buttons are bare icons beside a shortened title.
 */
final class LiveStreamActions
{
    /**
     * @param  Closure(): bool|null  $saveFirst  the edit page saves what is on screen before going live (false: it could not, stop)
     * @param  Closure(): array<string, mixed>|null  $onScreen  the edit page's form state, so the confirmation names the video and
     *                                                          governorate that will actually go live, not the last saved ones
     */
    public static function goLive(?Closure $saveFirst = null, ?Closure $onScreen = null): Action
    {
        return Action::make('goLive')
            ->label(fn (LiveStream $record): string => $record->status === LiveStatus::Ended ? 'Resume' : 'Go live')
            ->icon(Heroicon::OutlinedSignal)
            ->color('success')
            ->authorize('goLive')
            ->visible(fn (LiveStream $record): bool => $record->canGoLive())
            ->requiresConfirmation()
            ->modalHeading(fn (LiveStream $record): Htmlable => self::heading($record->status === LiveStatus::Ended ? 'Resume' : 'Go live with', $record))
            ->modalDescription(function (LiveStream $record) use ($saveFirst, $onScreen): string {
                [$where, $video] = self::onAir($record, $onScreen === null ? null : $onScreen());

                $resume = match (true) {
                    $record->status !== LiveStatus::Ended => '',
                    $record->resumesSameSession() => ' It ended less than '.LiveStream::SAME_SESSION_HOURS.' hours ago, so it keeps its first start time.',
                    default => ' It ended more than '.LiveStream::SAME_SESSION_HOURS.' hours ago, so it counts as a new start.',
                };

                return "{$where}. {$video}. Readers will see the video on the Live page straight away. Check that the stream is already running on YouTube or Vimeo."
                    .$resume
                    .($saveFirst === null ? '' : ' Your changes on this page are saved first.');
            })
            ->modalSubmitActionLabel('Go live now')
            ->action(function (LiveStream $record, Action $action, ?Component $livewire = null) use ($saveFirst): void {
                if ($saveFirst !== null && ! $saveFirst()) {
                    $action->cancel();

                    return;
                }

                self::run(fn () => $record->goLive(), 'The broadcast is live.', $livewire);
            });
    }

    public static function end(): Action
    {
        return Action::make('end')
            ->label('End broadcast')
            ->icon(Heroicon::OutlinedStopCircle)
            ->color('danger')
            ->authorize('end')
            ->visible(fn (LiveStream $record): bool => $record->canEnd())
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedStopCircle)
            ->modalHeading(fn (LiveStream $record): Htmlable => self::heading('End', $record))
            ->modalDescription(fn (LiveStream $record): string => self::onAir($record)[0].'. It leaves the live section of the Live page. You can resume it later if the stream comes back.')
            ->modalSubmitActionLabel('End broadcast')
            ->action(fn (LiveStream $record, ?Component $livewire = null) => self::run(fn () => $record->end(), 'The broadcast has ended.', $livewire));
    }

    /** "End “<title>”?" with the title isolated, so an Arabic title cannot reorder the English words around it. */
    private static function heading(string $verb, LiveStream $record): Htmlable
    {
        $title = ($record->translation(Locale::Ar) ?? $record->translation(Locale::En))?->title;

        return new HtmlString($title === null
            ? e("{$verb} this broadcast?")
            : e($verb).' “<bdi>'.e(Str::limit($title, 60)).'</bdi>”?');
    }

    /**
     * Where the broadcast is and which video goes on air, from the form on screen when there is one.
     *
     * @param  array<string, mixed>|null  $form
     * @return array{0: string, 1: string}
     */
    private static function onAir(LiveStream $record, ?array $form = null): array
    {
        $governorate = $record->governorate;
        $video = [$record->provider, $record->video_ref];

        if ($form !== null) {
            $governorate = filled($form['governorate_id'] ?? null) ? Governorate::query()->find($form['governorate_id']) : null;
            $video = LiveStreamWriter::parseLink((string) ($form['video_link'] ?? '')) ?? $video;
        }

        /** @var LiveProvider $provider */
        [$provider, $id] = $video;

        return [
            $governorate === null ? 'Egypt-wide' : "{$governorate->name_en} ({$governorate->name_ar})",
            "{$provider->label()} video {$id}",
        ];
    }

    /**
     * A refused move (someone else already changed it) becomes a message, not an error page. After a move the menu's
     * live count is redrawn: Livewire does not re-render the sidebar on its own.
     */
    private static function run(Closure $move, string $successTitle, ?Component $livewire = null): void
    {
        try {
            $move();
        } catch (DomainException $exception) {
            WorkflowOutcome::refuse($exception->getMessage());

            return;
        }

        Notification::make()->success()->title($successTitle)->send();
        $livewire?->dispatch('refresh-sidebar');
    }
}
