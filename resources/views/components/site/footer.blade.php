@php
    $siteName = config('site.name.'.$locale->value);
    $socialLinks = $social();
    $email = config('site.contact.email');
    $phone = config('site.contact.phone');
@endphp
<footer class="site-footer">
    @unless ($minimal)
        <div class="container-site grid gap-10 py-10 md:grid-cols-12 md:py-12">
            <div class="md:col-span-5">
                <a href="{{ route('home', ['locale' => $locale->value]) }}" class="inline-block">
                    <img src="{{ asset('brand/logo-380.webp') }}" width="380" height="106" alt="{{ $siteName }}" class="h-10 w-auto" loading="lazy" decoding="async">
                </a>
                <p class="mt-4 max-w-md text-ink-soft">{{ __('layout.footer.about') }}</p>

                @if ($socialLinks)
                    <h2 class="footer-heading mt-6">{{ __('layout.footer.follow') }}</h2>
                    <ul class="flex flex-wrap gap-2">
                        @foreach ($socialLinks as $label => $url)
                            <li><a class="social-link" href="{{ $url }}" rel="noopener" target="_blank">{{ $label }}</a></li>
                        @endforeach
                    </ul>
                @endif

                @if (filled($email) || filled($phone))
                    <h2 class="footer-heading mt-6">{{ __('layout.footer.contact') }}</h2>
                    {{-- The address and number read left to right, but the list itself keeps the page direction so it lines up under its heading in Arabic. --}}
                    <ul class="footer-links">
                        @if (filled($email))
                            <li><bdi dir="ltr"><a href="mailto:{{ $email }}">{{ $email }}</a></bdi></li>
                        @endif
                        @if (filled($phone))
                            <li><bdi dir="ltr"><a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}">{{ $phone }}</a></bdi></li>
                        @endif
                    </ul>
                @endif
            </div>

            <nav class="md:col-span-3" aria-labelledby="footer-sections">
                <h2 id="footer-sections" class="footer-heading">{{ __('layout.sections') }}</h2>
                <ul class="footer-links">
                    @foreach ($categories() as $category)
                        <li><a href="{{ route('category', ['locale' => $locale->value, 'slug' => $category['slug']]) }}">{{ $category['name'] }}</a></li>
                    @endforeach
                    <li><a href="{{ route('feed', ['locale' => $locale->value]) }}">{{ __('layout.footer.rss') }}</a></li>
                </ul>
            </nav>

            <div class="md:col-span-4">
                @if ($showNewsletter())
                    <section id="newsletter" aria-labelledby="newsletter-title">
                        <h2 id="newsletter-title" class="footer-heading">{{ __('layout.newsletter.title') }}</h2>
                        <p class="mb-3 text-ink-soft">{{ __('layout.newsletter.text') }}</p>

                        @if (session('newsletter_status'))
                            <p role="status" class="mb-3 font-bold">{{ session('newsletter_status') }}</p>
                        @endif

                        <form method="POST" action="{{ route('newsletter.subscribe') }}">
                            @csrf
                            <input type="hidden" name="locale" value="{{ $locale->value }}">
                            <div class="sr-only" aria-hidden="true">
                                <label for="newsletter-website">Website</label>
                                <input id="newsletter-website" type="text" name="website" value="" tabindex="-1" autocomplete="off">
                            </div>
                            <label class="sr-only" for="newsletter-email">{{ __('layout.newsletter.email') }}</label>
                            <div class="flex gap-2">
                                <input id="newsletter-email" class="field-input" type="email" name="email" value="{{ old('email') }}" required maxlength="254" autocomplete="email" dir="ltr" placeholder="name@example.com" @error('email') aria-invalid="true" aria-describedby="newsletter-errors" @enderror>
                                <button class="btn-dark shrink-0" type="submit">{{ __('layout.newsletter.submit') }}</button>
                            </div>
                            @if ($errors->any())
                                <ul id="newsletter-errors" role="alert" class="mt-2 font-bold text-crimson">
                                    @foreach ($errors->all() as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </form>
                    </section>
                @endif

                {{-- Static pages (About, Standards, Privacy...): one cached query, dropped whenever a page is saved. --}}
                @if ($aboutPages = app(\App\Services\FooterPages::class)->forLocale($locale))
                    <nav @class(['mt-8' => $showNewsletter()]) aria-labelledby="footer-about">
                        <h2 id="footer-about" class="footer-heading">{{ __('layout.footer.pages') }}</h2>
                        <ul class="footer-links">
                            @foreach ($aboutPages as $aboutPage)
                                <li><a href="{{ route('page', ['locale' => $locale->value, 'slug' => $aboutPage['slug']]) }}">{{ $aboutPage['title'] }}</a></li>
                            @endforeach
                        </ul>
                    </nav>
                @endif
            </div>
        </div>
    @endunless

    <div class="footer-base on-dark">
        <div class="container-site flex flex-wrap items-center justify-between gap-x-6 gap-y-1 py-4">
            {{-- The imprint: who is responsible for the paper, then the copyright. --}}
            <x-site.masthead-credits :locale="$locale" :credits="$credits" variant="footer" />
            <p>{{ __('layout.footer.rights', ['year' => now(config('site.timezone'))->year, 'name' => $siteName]) }}</p>
        </div>
    </div>
</footer>
