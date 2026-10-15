<?php

namespace Database\Seeders\Support;

/** One validated story of the launch content package, ready to be imported. */
final readonly class LaunchStory
{
    /**
     * @param  string  $image  path as written in stories.json, relative to the content directory
     * @param  string  $sourceFile  absolute path of that image
     * @param  string  $extension  lower-case, no dot; kept when the image is copied
     * @param  list<array{en: string, ar: string}>  $tags
     * @param  array<string, array{title: string, slug: string, excerpt: string, body: string, hero_caption: string, meta_description: string}>  $text  keyed by locale value
     */
    public function __construct(
        public string $key,
        public string $category,
        public string $image,
        public string $sourceFile,
        public string $extension,
        public bool $featured,
        public int $hoursAgo,
        public array $tags,
        public array $text,
    ) {}

    /**
     * Where the picture lives on the public disk. It doubles as the story's identity:
     * a story is "already imported" once an article points at a path for its key.
     */
    public function imagePath(): string
    {
        return LaunchContentImporter::IMAGE_DIRECTORY."/{$this->key}.{$this->extension}";
    }
}
