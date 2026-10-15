<?php

namespace Database\Seeders\Support;

/** What an import (or a dry run of it) did, by story key. */
final class LaunchImportResult
{
    /** @var list<string> imported, or for a dry run: would be imported */
    public array $imported = [];

    /** @var list<string> already in the database */
    public array $skipped = [];

    /** @var array<string, string> story key => reason it was rolled back */
    public array $failed = [];

    public int $createdTags = 0;

    public bool $createdByline = false;

    public function successful(): bool
    {
        return $this->failed === [];
    }
}
