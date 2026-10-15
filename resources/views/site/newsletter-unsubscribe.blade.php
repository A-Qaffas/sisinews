{{-- Reached from the unsubscribe link, outside the /{locale} group, so the language came from the browser (see NewsletterController::page).
     Minimal layout: no nav, no sign-up box under a "you are unsubscribed" message, no database work. --}}
@extends('layouts.site', ['minimal' => true])

@section('title', __($done ? 'newsletter.unsubscribe.done_title' : 'newsletter.unsubscribe.title'))

@push('head')
    <meta name="robots" content="noindex, nofollow">
@endpush

@section('content')
    <section class="mx-auto max-w-xl py-10 text-center md:py-16" aria-labelledby="unsubscribe-title">
        <h1 id="unsubscribe-title" class="text-2xl md:text-3xl">
            {{ __($done ? 'newsletter.unsubscribe.done_title' : 'newsletter.unsubscribe.title') }}
        </h1>

        @if ($done)
            <p class="mt-3 text-lg text-ink-soft">{{ __('newsletter.unsubscribe.done') }}</p>
            <a href="{{ route('home', ['locale' => $locale->value]) }}" class="btn-dark mt-6 inline-flex items-center no-underline">{{ __('newsletter.unsubscribe.home') }}</a>
        @else
            <p class="mt-3 text-lg text-ink-soft">{{ __('newsletter.unsubscribe.intro') }}</p>
            <form method="POST" action="{{ route('newsletter.unsubscribe.confirm', ['token' => $token]) }}" class="mt-6">
                @csrf
                <button type="submit" class="btn-dark">{{ __('newsletter.unsubscribe.confirm') }}</button>
            </form>
        @endif
    </section>
@endsection
