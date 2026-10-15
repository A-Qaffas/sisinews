<?php

namespace App\Filament\Resources\LiveStreams;

use App\Filament\Resources\LiveStreams\Pages\CreateLiveStream;
use App\Filament\Resources\LiveStreams\Pages\EditLiveStream;
use App\Filament\Resources\LiveStreams\Pages\ListLiveStreams;
use App\Filament\Resources\LiveStreams\Schemas\LiveStreamForm;
use App\Filament\Resources\LiveStreams\Tables\LiveStreamsTable;
use App\Models\LiveStream;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Live broadcasts hosted on YouTube or Vimeo, which the site only embeds. Editors and administrators only
 * (LiveStreamPolicy): journalists get no menu item and a 403 on every address.
 */
class LiveStreamResource extends Resource
{
    protected static ?string $model = LiveStream::class;

    protected static ?string $slug = 'live-broadcasts';

    // Short, because the red live count sits beside it: "Live broadcasts" plus the badge was cut to "Live broadca..." in
    // the 15rem sidebar. The pages themselves are titled "Live broadcasts".
    protected static ?string $navigationLabel = 'Broadcasts';

    protected static ?string $breadcrumb = 'Live broadcasts';

    protected static ?string $modelLabel = 'broadcast';

    protected static ?string $pluralModelLabel = 'live broadcasts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|\UnitEnum|null $navigationGroup = 'Newsroom';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return LiveStreamForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LiveStreamsTable::configure($table);
    }

    /** A red count in the menu while something is on air, so nobody forgets to end it. */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $live = LiveStream::query()->liveNow()->count();

        return $live > 0 ? (string) $live : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Live now';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['translations', 'governorate']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLiveStreams::route('/'),
            'create' => CreateLiveStream::route('/create'),
            'edit' => EditLiveStream::route('/{record}/edit'),
        ];
    }
}
