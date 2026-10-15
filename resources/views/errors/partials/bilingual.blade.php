{{--
  Error body shown in both languages, the language of the URL first. Error pages can render
  without SetLocale and without a database, so this reads only translation files and routes.
--}}
@php
    // Staff who hit a forbidden or missing page in the CMS get English first and a way back to the CMS, not to the public home.
    $staff = request()->is('admin', 'admin/*');
    $first = $locale ?? ($staff ? \App\Enums\Locale::En : null) ?? \App\Enums\Locale::tryFrom((string) request()->segment(1)) ?? \App\Enums\Locale::default();
    $languages = [$first, $first->other()];
@endphp
<section class="mx-auto max-w-4xl py-8 md:py-14">
    <p class="text-center font-headline text-7xl font-bold text-crimson md:text-8xl" aria-hidden="true">{{ $status }}</p>

    <div class="mt-8 grid gap-10 md:grid-cols-2 md:gap-0">
        @foreach ($languages as $language)
            {{-- The column keeps the page direction so the divider sits between the columns; the inner box switches language. --}}
            <div class="{{ $loop->first ? 'md:pe-8' : 'border-t border-line pt-10 md:border-t-0 md:border-s md:pt-0 md:ps-8' }}">
                <div lang="{{ $language->value }}" dir="{{ $language->dir() }}" class="text-start">
                    <h{{ $loop->first ? 1 : 2 }} class="text-2xl md:text-3xl">{{ __('layout.errors.'.$status.'.title', [], $language->value) }}</h{{ $loop->first ? 1 : 2 }}>
                    <p class="mt-3 text-lg text-ink-soft">{{ __('layout.errors.'.$status.'.text', [], $language->value) }}</p>
                    <a href="{{ $staff ? url('/admin') : route('home', ['locale' => $language->value]) }}" class="btn-dark mt-6 inline-flex items-center no-underline">{{ __($staff ? 'layout.errors.newsroom' : 'layout.errors.home', [], $language->value) }}</a>
                </div>
            </div>
        @endforeach
    </div>
</section>
