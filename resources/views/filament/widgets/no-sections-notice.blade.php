@php
    use App\Filament\Resources\Articles\Support\NoSectionsNotice;
    use Filament\Support\Icons\Heroicon;
@endphp

<x-filament-widgets::widget>
    <x-filament::section :icon="Heroicon::OutlinedExclamationTriangle" icon-color="warning">
        <x-slot name="heading">{{ NoSectionsNotice::HEADING }}</x-slot>

        <p>{{ NoSectionsNotice::BODY }}</p>
    </x-filament::section>
</x-filament-widgets::widget>
