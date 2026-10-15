<?php

namespace App\Filament\Support\Taxonomy;

use App\Enums\Locale;
use App\Filament\Support\StatusPresenter;
use App\Support\Slugger;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Illuminate\Database\Eloquent\Model;

/** The per-language name + slug inputs shared by the category and tag forms. */
final class NameFields
{
    /**
     * @param  class-string<Model>  $translationClass
     * @param  ?Locale  $alternative  when set, the name is only required if this other language has none
     */
    public static function make(Locale $locale, string $translationClass, bool $required, ?Locale $alternative = null): Group
    {
        $language = StatusPresenter::language($locale);
        $direction = ['dir' => $locale->dir(), 'lang' => $locale->value];

        return Group::make([
            TextInput::make('name')
                ->label("Name ({$language})")
                ->required($required)
                ->requiredWithout($alternative === null ? [] : "../{$alternative->value}.name")
                ->maxLength(120)
                ->regex('/[\p{L}\p{N}]/u')
                ->validationMessages(['regex' => 'The name must contain at least one letter or number.'])
                ->extraInputAttributes($direction),
            TextInput::make('slug')
                ->label("URL slug ({$language})")
                ->helperText('Leave blank to build it from the name. Changing it changes the public address.')
                ->maxLength(120)
                ->rules([
                    fn (?Model $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($translationClass, $locale, $record): void {
                        $slug = Slugger::make((string) $value);

                        if ($slug !== '' && self::slugIsTaken($translationClass, $locale, $slug, $record)) {
                            $fail('Another entry already uses this slug in this language.');
                        }
                    },
                ])
                ->extraInputAttributes($direction),
        ])->statePath($locale->value)->columns(2);
    }

    /** @param  class-string<Model>  $translationClass */
    private static function slugIsTaken(string $translationClass, Locale $locale, string $slug, ?Model $owner): bool
    {
        return $translationClass::query()
            ->where('locale', $locale->value)
            ->where('slug', $slug)
            ->when($owner !== null, fn ($query) => $query->where($owner->getForeignKey(), '!=', $owner->getKey()))
            ->exists();
    }
}
