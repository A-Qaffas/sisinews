<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Support\TeamGuard;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The "Team" area: administrators only (UserPolicy), so the sidebar item exists for them alone.
 * Staff are deactivated, never deleted: articles keep their author.
 * The byline account (desk@sisi.news) is not a person and is left out of every query of this
 * resource: it is not listed, its edit URL is a 404 and it cannot be picked for a bulk action.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'users';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::UserGroup;

    // A top-level item (no group) sits right under the Dashboard, above the newsroom groups.
    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('Team');
    }

    public static function getModelLabel(): string
    {
        return __('team member');
    }

    public static function getPluralModelLabel(): string
    {
        return __('team');
    }

    /** @return Builder<User> */
    public static function getEloquentQuery(): Builder
    {
        return TeamGuard::withoutProtectedAccounts(parent::getEloquentQuery());
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
