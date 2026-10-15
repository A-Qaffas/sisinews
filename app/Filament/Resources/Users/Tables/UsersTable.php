<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\Role;
use App\Filament\Resources\Users\Support\AccountActions;
use App\Filament\Resources\Users\Support\SectionAssignments;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\PhoneFriendly;
use App\Filament\Support\TaxonomyLabels;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // The Sections column reads every row's sections: load them in one go, not one query per row.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('allowedCategories.translations'))
            ->columns([
                // One cell for who they are: the e-mail sits under the name, so a phone needs no second column.
                TextColumn::make('name')
                    ->searchable(['name', 'name_ar', 'email'])
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (User $record): string => $record->email)
                    ->extraAttributes(['class' => 'sisi-person']),
                TextColumn::make('name_ar')
                    ->label('Arabic name')
                    ->placeholder('-')
                    ->extraAttributes(['dir' => 'rtl'])
                    ->extraHeaderAttributes(['class' => 'fi-align-end'])
                    ->visibleFrom('lg'),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn (Role $state): string => $state->label())
                    ->color(fn (Role $state): string => match ($state) {
                        Role::Admin => 'danger',
                        Role::Editor => 'warning',
                        Role::Journalist => 'info',
                    }),
                // Journalists only write in the sections listed; the Editor-in-chief and admins need no list.
                TextColumn::make('sections')
                    ->state(fn (User $record): string => SectionAssignments::summary($record))
                    ->color(fn (User $record): string => match (true) {
                        SectionAssignments::hasNone($record) => 'warning',
                        $record->isReviewer() => 'gray',
                        default => 'primary',
                    })
                    ->icon(fn (User $record): ?Heroicon => SectionAssignments::hasNone($record) ? Heroicon::OutlinedExclamationTriangle : null)
                    ->wrap()
                    ->visibleFrom('md'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->recordUrl(fn (User $record): string => UserResource::getUrl('edit', ['record' => $record]))
            ->filters([
                SelectFilter::make('role')
                    ->options(collect(Role::cases())->mapWithKeys(fn (Role $role): array => [$role->value => $role->label()])->all()),
                SelectFilter::make('section')
                    ->label('Section')
                    ->options(fn (): array => TaxonomyLabels::categoryOptions())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('role', Role::Journalist->value)
                            ->whereHas('allowedCategories', fn (Builder $categories) => $categories->whereKey($data['value']))
                        : $query),
                Filter::make('no_sections')
                    ->label('Journalists with no section yet')
                    ->query(fn (Builder $query): Builder => $query->where('role', Role::Journalist->value)->whereDoesntHave('allowedCategories')),
                TernaryFilter::make('is_active')
                    ->label('Active')
                    ->trueLabel('Active accounts')
                    ->falseLabel('Deactivated accounts'),
            ])
            ->recordActions([
                // On a phone the row itself opens the account (recordUrl), so only the menu keeps a button there.
                PhoneFriendly::hideOnPhone(EditAction::make()),
                // One menu instead of three links: name, role and state are all a 390 px row has room for.
                ActionGroup::make([
                    AccountActions::resetPassword(),
                    AccountActions::deactivate(),
                    AccountActions::activate(),
                ])->tooltip('Reset password, activate or deactivate'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    AccountActions::deactivateSelected(),
                    AccountActions::activateSelected(),
                ]),
            ]);
    }
}
