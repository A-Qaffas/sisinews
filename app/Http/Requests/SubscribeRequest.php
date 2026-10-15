<?php

namespace App\Http\Requests;

use App\Enums\Locale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** The footer newsletter form: an address and the language of the page it was submitted from. */
class SubscribeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        $this->merge([
            'email' => is_string($email) ? Str::lower(trim($email)) : $email,
            'locale' => $this->filled('locale') ? $this->input('locale') : Locale::default()->value,
        ]);
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'locale' => ['required', Rule::enum(Locale::class)],
        ];
    }

    public function messages(): array
    {
        $locale = $this->locale()->value;

        return [
            'email.*' => __('newsletter.invalid_email', [], $locale),
            'locale.*' => __('newsletter.invalid_locale', [], $locale),
        ];
    }

    public function locale(): Locale
    {
        return Locale::tryFrom((string) $this->input('locale')) ?? Locale::default();
    }

    /**
     * The page the form was on, scrolled to the form. The Referer is client-supplied,
     * so only a same-host URL is trusted; anything else goes to the home page.
     */
    public function formUrl(): string
    {
        $previous = url()->previous();
        $target = parse_url($previous, PHP_URL_HOST) === $this->getHost()
            ? $previous
            : route('home', ['locale' => $this->locale()->value]);

        return Str::before($target, '#').'#newsletter';
    }

    /** Validation errors (and old input) go back to the form, not to wherever the Referer says. */
    protected function getRedirectUrl(): string
    {
        return $this->formUrl();
    }
}
