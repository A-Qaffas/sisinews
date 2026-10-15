<?php

namespace App\Filament\Resources\ReviewQueue\Tables;

use App\Editorial\EditorialWorkflow;
use App\Enums\Locale;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Support\PhoneFriendly;
use App\Filament\Support\PublishAtField;
use App\Filament\Support\StatusPresenter;
use App\Filament\Support\WorkflowOutcome;
use App\Models\ArticleTranslation;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReviewQueueTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->weight('bold')
                    ->wrap()
                    ->lineClamp(2)
                    // Each headline resolves its own direction, so an Arabic one reads right-to-left.
                    ->extraAttributes(['dir' => 'auto', 'class' => 'sisi-headline sisi-headline--wide'])
                    ->searchable(),
                // Phones keep the headline and the language; everything else is one tap away on the story.
                TextColumn::make('locale')
                    ->label('Language')
                    ->badge()
                    ->formatStateUsing(fn (Locale $state): string => StatusPresenter::language($state)),
                TextColumn::make('article.author.name')
                    ->label('Author')
                    ->visibleFrom('md'),
                TextColumn::make('category')
                    // The query leaves out deleted stories; null-safe anyway, so one odd row can never take the whole queue down.
                    ->state(fn (ArticleTranslation $record): string => $record->article?->category?->nameIn(Locale::En) ?? '-')
                    ->visibleFrom('2xl'),
                TextColumn::make('submitted_at')
                    ->label('Waiting since')
                    ->dateTime('j M, H:i', StatusPresenter::TIMEZONE)
                    ->sortable()
                    ->visibleFrom('md'),
            ])
            ->defaultSort('submitted_at')
            ->recordUrl(fn (ArticleTranslation $record): string => ArticleResource::getUrl('edit', ['record' => $record->article_id]))
            ->recordActions([
                // Below md the buttons shrink to their icon (PhoneFriendly), so both stay on screen next to the headline.
                PhoneFriendly::iconOnPhone(Action::make('approve')
                    ->label('Approve & publish')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (ArticleTranslation $record): bool => auth()->user()->can('approve', $record))
                    ->requiresConfirmation()
                    ->modalDescription('The text goes live on the site now, or at the time you pick below.')
                    ->schema([PublishAtField::make()])
                    ->action(function (ArticleTranslation $record, array $data): void {
                        $publishAt = PublishAtField::parse($data[PublishAtField::NAME] ?? null);

                        WorkflowOutcome::run(
                            fn () => app(EditorialWorkflow::class)->approve($record, auth()->user(), $publishAt),
                            PublishAtField::successTitle('Version', $publishAt),
                        );
                    })),
                PhoneFriendly::iconOnPhone(Action::make('requestChanges')
                    ->label('Request changes')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('warning')
                    ->visible(fn (ArticleTranslation $record): bool => auth()->user()->can('requestChanges', $record))
                    ->modalHeading('Request changes')
                    ->modalDescription('The author sees this note on the article and can edit and resubmit.')
                    ->modalSubmitActionLabel('Send back to the author')
                    ->modalSubmitAction(fn (Action $action) => $action->color('primary'))
                    ->schema([
                        Textarea::make('note')
                            ->label('What needs to change?')
                            ->required()
                            ->maxLength(2000)
                            ->rows(5),
                    ])
                    ->action(fn (ArticleTranslation $record, array $data) => WorkflowOutcome::run(
                        fn () => app(EditorialWorkflow::class)->requestChanges($record, auth()->user(), (string) $data['note']),
                        'Changes requested. The author will see your note on the article.',
                    ))),
            ]);
    }
}
