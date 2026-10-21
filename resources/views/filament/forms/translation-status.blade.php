@php
    use App\Enums\TranslationStatus;
    use App\Filament\Support\StatusPresenter;

    /** @var ?\App\Models\ArticleTranslation $translation */
    /** @var \App\Enums\Locale $locale */
    /** @var bool $canEdit */
    /** @var ?string $lostSection the story's section, when it is the viewer's own story in a section no longer theirs */
    $lostSection ??= null;
    $language = StatusPresenter::language($locale);
    $when = fn ($date) => StatusPresenter::when($date, 'j M Y, H:i');
    $notYourSection = $lostSection === null
        ? null
        : __('This story is in :section, which is not one of your sections, so you can read it but not change it. Ask an admin to add the section, or ask the Editor-in-chief to finish it.', ['section' => $lostSection]);
@endphp

<div class="sisi-status">
    @if ($translation === null)
        @if ($canEdit)
            <p>{{ __('There is no :language version of this story yet. Fill in a title below to create one; it starts as a draft.', ['language' => $language]) }}</p>
        @else
            <p>{{ __('There is no :language version of this story yet.', ['language' => $language]) }}</p>
            @if ($notYourSection !== null)
                <p class="sisi-status-hint">{{ $notYourSection }}</p>
            @endif
        @endif
    @else
        <div class="sisi-status-head">
            @php
                $scheduled = StatusPresenter::isScheduled($translation);
            @endphp

            {{-- The stored status stays "published"; a date still ahead reads "Scheduled" in its own colour. --}}
            <x-filament::badge :color="StatusPresenter::stateColor($translation)">
                {{ StatusPresenter::shortLabel($translation) }}
            </x-filament::badge>

            @if ($translation->published_at !== null && $translation->status === TranslationStatus::Published)
                <span>
                    @if ($scheduled)
                        {{ __('Scheduled for :date. It goes live by itself then.', ['date' => $when($translation->published_at)]) }}
                    @else
                        {{ __('Live since :date', ['date' => $when($translation->published_at)]) }}
                    @endif
                </span>
            @elseif ($translation->published_at !== null && $translation->status === TranslationStatus::Archived)
                <span>{{ __('Was live from :date', ['date' => $when($translation->published_at)]) }}</span>
            @endif
        </div>

        @php
            $hint = match (true) {
                // A draft or sent-back text the author could normally edit, locked only because the section was taken away.
                $notYourSection !== null && ! $canEdit && $translation->status->isAuthorEditable() => $notYourSection,
                $translation->status === TranslationStatus::Draft && $canEdit => __('Only the author and the Editor-in-chief can see this draft. When it is ready, use Submit for review at the top right.'),
                $translation->status === TranslationStatus::ChangesRequested && $canEdit => __('The Editor-in-chief sent this back. Fix what the note above asks for, then submit it for review again.'),
                $translation->status === TranslationStatus::InReview && ! $canEdit => __('Waiting for the Editor-in-chief. This version is locked until they publish it or send it back to you.'),
                $scheduled && ! $canEdit => __('This version is scheduled and not public yet. Only the Editor-in-chief can change it or cancel the schedule.'),
                $translation->status === TranslationStatus::Published && ! $canEdit => __('This version is live. Only the Editor-in-chief can change it or take it down.'),
                $translation->status === TranslationStatus::Archived && ! $canEdit => __('This version is off the site. Only the Editor-in-chief can reopen it.'),
                default => null,
            };
        @endphp

        @if ($hint !== null)
            <p class="sisi-status-hint">{{ $hint }}</p>
        @endif

        @if ($translation->statusChanges->isNotEmpty())
            <details class="sisi-status-history">
                <summary>{{ __('Status history (:count)', ['count' => $translation->statusChanges->count()]) }}</summary>

                <table>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('When') }}</th>
                            <th scope="col">{{ __('Who') }}</th>
                            <th scope="col">{{ __('Change') }}</th>
                            <th scope="col">{{ __('Note') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($translation->statusChanges as $change)
                            <tr>
                                <td>{{ $when($change->created_at) }}</td>
                                <td>{{ $change->user?->name ?? __('Former staff member') }}</td>
                                {{-- One key, so another language can word the change its own way (Arabic: no arrow to point the wrong way). --}}
                                <td>{{ __(':transition: :from → :to', ['transition' => $change->transition->label(), 'from' => $change->from_status->label(), 'to' => $change->to_status->label()]) }}</td>
                                <td class="sisi-status-note">{{ $change->note }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
        @endif
    @endif
</div>
