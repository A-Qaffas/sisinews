<?php

namespace App\Filament\Resources\Articles\Pages\Concerns;

use App\Editorial\EditorialWorkflow;
use App\Editorial\Transition;
use App\Enums\Locale;
use App\Filament\Resources\Articles\Support\ArticleAccess;
use App\Filament\Support\PublishAtField;
use App\Filament\Support\StatusPresenter;
use App\Filament\Support\WorkflowOutcome;
use App\Models\ArticleTranslation;
use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Illuminate\Validation\ValidationException;

/**
 * Header actions that move one language version through the editorial workflow.
 * Nothing here changes status itself: every move goes through EditorialWorkflow,
 * which checks the policy (who) and the transition table (which move) again.
 * The methods are protected on purpose, so Livewire does not expose them to the browser.
 */
trait HasWorkflowActions
{
    /** @return list<Action> */
    protected function workflowActions(): array
    {
        $actions = [];

        foreach (Locale::cases() as $locale) {
            foreach (Transition::cases() as $transition) {
                $actions[] = $this->transitionAction($locale, $transition);
            }
            $actions[] = $this->publishNowAction($locale);
        }

        return $actions;
    }

    private function transitionAction(Locale $locale, Transition $transition): Action
    {
        $language = StatusPresenter::language($locale);
        $heading = __(':action (:language)', ['action' => $transition->label(), 'language' => $language]);

        $action = Action::make("{$locale->value}_{$transition->value}")
            ->label($heading)
            ->color(StatusPresenter::transitionColor($transition))
            ->visible(fn (): bool => in_array($transition, $this->availableTransitions($locale), true))
            ->action(function (array $data, Action $action) use ($locale, $transition, $language): void {
                $publishAt = PublishAtField::parse($data[PublishAtField::NAME] ?? null);

                $moved = $this->runWorkflow(
                    $locale,
                    saveFirst: $transition === Transition::Submit || $transition === Transition::Approve,
                    move: fn (ArticleTranslation $t) => $this->performTransition($transition, $t, (string) ($data['note'] ?? ''), $publishAt),
                    success: match ($transition) {
                        Transition::Submit => __(':language version submitted for review.', ['language' => $language]),
                        Transition::Approve => PublishAtField::successTitle(__(':language version', ['language' => $language]), $publishAt),
                        Transition::RequestChanges => __('Changes requested. The author will see your note on the article.'),
                        Transition::Archive => __(':language version unpublished.', ['language' => $language]),
                        Transition::Reopen => __(':language version reopened as a draft.', ['language' => $language]),
                    },
                );

                if (! $moved) {
                    $action->cancel();
                }
            });

        if ($transition === Transition::RequestChanges) {
            return $action
                ->modalHeading(__('Request changes (:language)', ['language' => $language]))
                ->modalDescription(__('The author sees this note on the article and can edit and resubmit.'))
                ->modalSubmitActionLabel(__('Send back to the author'))
                ->modalSubmitAction(fn (Action $action) => $action->color('primary'))
                ->schema([
                    Textarea::make('note')
                        ->label(__('What needs to change?'))
                        ->required()
                        ->maxLength(2000)
                        ->rows(5),
                ]);
        }

        $action
            ->requiresConfirmation()
            ->modalHeading($heading)
            ->modalDescription(match ($transition) {
                Transition::Submit => __('The text is saved and sent to the Editor-in-chief. You cannot edit it while it is in review.'),
                Transition::Approve => __('The text goes live on the site now, or at the time you pick below.'),
                Transition::Archive => __('The text disappears from the site (or is no longer scheduled). It can be reopened as a draft later.'),
                Transition::Reopen => __('The text becomes an editable draft again.'),
            });

        return $transition === Transition::Approve ? $action->schema([PublishAtField::make()]) : $action;
    }

    /** Reviewers only: submit and approve in one audited step. */
    private function publishNowAction(Locale $locale): Action
    {
        $language = StatusPresenter::language($locale);
        $heading = __('Publish now (:language)', ['language' => $language]);

        return Action::make("{$locale->value}_publish_now")
            ->label($heading)
            ->color('success')
            ->visible(fn (): bool => $this->canPublishNow($locale))
            ->requiresConfirmation()
            ->modalHeading($heading)
            ->modalDescription(__('The text is saved and goes live now, or at the time you pick below. The history records both the submission and the approval.'))
            ->schema([PublishAtField::make()])
            ->action(function (array $data, Action $action) use ($locale, $language): void {
                $publishAt = PublishAtField::parse($data[PublishAtField::NAME] ?? null);

                $moved = $this->runWorkflow(
                    $locale,
                    saveFirst: true,
                    move: fn (ArticleTranslation $t) => $this->workflow()->publishNow($t, auth()->user(), $publishAt),
                    success: PublishAtField::successTitle(__(':language version', ['language' => $language]), $publishAt),
                );

                if (! $moved) {
                    $action->cancel();
                }
            });
    }

    /** @return list<Transition> */
    protected function availableTransitions(Locale $locale): array
    {
        $translation = ArticleAccess::translation($this->getRecord(), $locale);

        return $translation === null ? [] : $this->workflow()->availableFor($translation, auth()->user());
    }

    protected function canPublishNow(Locale $locale): bool
    {
        $translation = ArticleAccess::translation($this->getRecord(), $locale);
        $user = auth()->user();

        return $translation !== null
            && in_array($translation->status, Transition::Submit->allowedFrom(), true)
            && $user->can('submit', $translation)
            && $user->can('approve', $translation);
    }

    /**
     * Saves the form first when asked (so "Submit" sends what is on screen, not what was
     * last saved), then makes the move. Every refusal becomes a notification, never a 500.
     *
     * @param  Closure(ArticleTranslation): mixed  $move
     */
    private function runWorkflow(Locale $locale, bool $saveFirst, Closure $move, string $success): bool
    {
        $user = auth()->user();
        $language = StatusPresenter::language($locale);

        try {
            if ($saveFirst && ArticleAccess::canEditAnything($user, $this->getRecord())) {
                $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
            }
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            WorkflowOutcome::refuse(__('Fix the highlighted fields first.'));

            return false;
        }

        $this->getRecord()->load('translations');
        $translation = ArticleAccess::translation($this->getRecord(), $locale);

        if ($translation === null) {
            WorkflowOutcome::refuse(__('There is no :language version yet. Write a headline for it first.', ['language' => $language]));

            return false;
        }

        $moved = WorkflowOutcome::run(fn () => $move($translation), $success);
        $this->getRecord()->load('translations');

        return $moved;
    }

    private function workflow(): EditorialWorkflow
    {
        return app(EditorialWorkflow::class);
    }

    private function performTransition(Transition $transition, ArticleTranslation $translation, string $note, ?CarbonInterface $publishAt): void
    {
        $workflow = $this->workflow();
        $user = auth()->user();

        match ($transition) {
            Transition::Submit => $workflow->submit($translation, $user),
            Transition::Approve => $workflow->approve($translation, $user, $publishAt),
            Transition::RequestChanges => $workflow->requestChanges($translation, $user, $note),
            Transition::Archive => $workflow->archive($translation, $user),
            Transition::Reopen => $workflow->reopen($translation, $user),
        };
    }
}
