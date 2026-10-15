<?php

namespace App\Filament\Resources\LiveStreams\Pages;

use App\Enums\LiveStatus;
use App\Filament\Resources\LiveStreams\LiveStreamResource;
use App\Filament\Resources\LiveStreams\Support\LiveStreamActions;
use App\Filament\Resources\LiveStreams\Support\LiveStreamWriter;
use App\Filament\Support\StatusPresenter;
use App\Filament\Support\WorkflowOutcome;
use App\Models\LiveStream;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** @property LiveStream $record */
class EditLiveStream extends EditRecord
{
    protected static string $resource = LiveStreamResource::class;

    protected static ?string $title = 'Edit broadcast';

    /** The status is shown here and changed only by the buttons above: it is not a form field. */
    public function getSubheading(): ?string
    {
        $record = $this->record;

        return match ($record->status) {
            LiveStatus::Live => 'Live now'.($record->started_at === null ? '' : ', since '.StatusPresenter::when($record->started_at)).'.',
            LiveStatus::Ended => 'Ended'.($record->ended_at === null ? '' : ' '.StatusPresenter::when($record->ended_at)).'.',
            LiveStatus::Scheduled => $record->scheduled_at === null
                ? 'Not live yet.'
                : 'Not live yet. Scheduled for '.StatusPresenter::when($record->scheduled_at).'.',
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            // Goes live with what is on screen: a link pasted here and not yet saved is saved first, so readers never
            // get the old video while the editor sees the new one.
            LiveStreamActions::goLive(
                saveFirst: fn (): bool => $this->saveBeforeGoingLive(),
                onScreen: fn (): array => is_array($this->data) ? $this->data : [],
            ),
            LiveStreamActions::end(),
            // Hidden by the policy while the broadcast is live: end it first.
            DeleteAction::make()
                ->modalDescription('The broadcast and its titles are removed from the dashboard and the site. The video itself stays on YouTube or Vimeo.'),
        ];
    }

    /**
     * Saves the form without leaving the page. A field that does not validate stops the Go live: its error shows on
     * the form and nothing goes on air. Private, so Livewire does not expose it to the browser.
     */
    private function saveBeforeGoingLive(): bool
    {
        try {
            $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            WorkflowOutcome::refuse('Fix the highlighted fields first. The broadcast did not go live.');

            return false;
        }

        return true;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, ...LiveStreamWriter::formState($this->record)];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return LiveStreamWriter::update($record, $data);
    }
}
