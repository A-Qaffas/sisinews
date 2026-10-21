{{-- The other language, in its own script, under the sign-in form. --}}
@php($other = app()->getLocale() === 'ar' ? 'en' : 'ar')
<p class="sn-login-language">
    <a href="{{ route('admin.language', ['locale' => $other]) }}" lang="{{ $other }}" hreflang="{{ $other }}">{{ $other === 'en' ? 'English' : 'العربية' }}</a>
</p>
