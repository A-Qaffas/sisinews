<?php

namespace App\View\Components\Site;

use App\Enums\Locale;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Support\Media\HeroImages;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use InvalidArgumentException;

class ArticleCard extends Component
{
    public const VARIANTS = ['lead', 'card', 'row', 'compact'];

    /**
     * @param  ArticleTranslation  $translation  visible, with `article.category.translations` and `article.author` loaded
     * @param  bool|null  $priority  load the image eagerly at high priority; defaults to true for the lead variant only
     */
    public function __construct(
        public ArticleTranslation $translation,
        public string $variant = 'card',
        public bool $showExcerpt = false,
        public ?bool $priority = null,
    ) {
        if (! in_array($variant, self::VARIANTS, true)) {
            throw new InvalidArgumentException("Unknown article card variant [{$variant}].");
        }

        $this->priority ??= $variant === 'lead';
    }

    private function article(): Article
    {
        return $this->translation->article;
    }

    private function locale(): Locale
    {
        return $this->translation->locale;
    }

    /** @var array<string, string|null> */
    private array $urls = [];

    /** One lookup per size per card: the media layer checks the disk for each variant. */
    private function url(string $size): ?string
    {
        return $this->urls[$size] ??= HeroImages::url($this->article()->hero_image, $size);
    }

    /** False when there is no hero image or the stored path is not one the media layer will serve. */
    public function hasImage(): bool
    {
        return $this->src() !== null;
    }

    /** Intrinsic size of the largest crop served; CSS crops to the card's own aspect ratio. */
    public function width(): int
    {
        return match ($this->variant) {
            'lead' => 1280,
            'card' => 800,
            default => 480,
        };
    }

    public function height(): int
    {
        return match ($this->variant) {
            'lead' => 720,
            'card' => 450,
            default => 360,
        };
    }

    public function src(): ?string
    {
        return $this->url(match ($this->variant) {
            'lead', 'card' => 'card',
            default => 'thumb',
        });
    }

    public function srcset(): ?string
    {
        $widths = match ($this->variant) {
            'lead' => ['thumb' => 480, 'card' => 800, 'full' => 1280],
            'card' => ['thumb' => 480, 'card' => 800],
            default => [],
        };

        $sources = [];
        foreach ($widths as $size => $width) {
            if ($url = $this->url($size)) {
                $sources[] = "{$url} {$width}w";
            }
        }

        return $sources === [] ? null : implode(', ', $sources);
    }

    public function sizes(): ?string
    {
        return match ($this->variant) {
            'lead' => '(min-width: 64rem) 780px, 100vw',
            'card' => '(min-width: 64rem) 380px, (min-width: 40rem) 50vw, 100vw',
            default => null,
        };
    }

    /** @return array{name: string, url: string|null}|null */
    public function category(): ?array
    {
        $category = $this->article()->category;
        $name = $category?->nameIn($this->locale());

        if (blank($name)) {
            return null;
        }

        // The other language's slug would 404 here, so only link when this language has its own.
        $slug = $category->translate($this->locale())?->slug;

        return [
            'name' => $name,
            'url' => $slug ? route('category', ['locale' => $this->locale()->value, 'slug' => $slug]) : null,
        ];
    }

    public function author(): ?string
    {
        return $this->article()->author?->displayName($this->locale());
    }

    public function isoDate(): ?string
    {
        return $this->translation->published_at?->toIso8601String();
    }

    /** Relative within the last day ("3 hours ago"), otherwise the calendar date. */
    public function dateLabel(): string
    {
        $published = $this->translation->published_at;

        if ($published === null) {
            return '';
        }

        $at = $published->copy()->locale($this->locale()->value)->timezone(config('site.timezone'));

        return $at->greaterThan(now()->subDay()) ? $at->diffForHumans() : $at->translatedFormat('j F Y');
    }

    public function render(): View
    {
        return view('components.site.article-card');
    }
}
