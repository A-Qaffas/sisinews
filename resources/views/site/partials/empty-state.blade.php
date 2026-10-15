{{-- Shown on the home page until the first story is published. --}}
<section class="mx-auto max-w-xl py-14 text-center md:py-20" aria-labelledby="empty-title">
    <img src="{{ asset('brand/logo-380.webp') }}" width="380" height="106" alt="" class="mx-auto h-14 w-auto" decoding="async">
    <h2 id="empty-title" class="mt-8 text-2xl md:text-3xl">{{ __('layout.empty.title') }}</h2>
    <p class="mt-3 text-lg text-ink-soft">{{ __('layout.empty.text') }}</p>
</section>
