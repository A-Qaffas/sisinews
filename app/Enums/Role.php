<?php

namespace App\Enums;

enum Role: string
{
    case Journalist = 'journalist';
    // The stored value stays "editor"; only the name staff read changed (Arabic: رئيس التحرير).
    case Editor = 'editor';
    case Admin = 'admin';

    public function isReviewer(): bool
    {
        return $this === self::Editor || $this === self::Admin;
    }

    /**
     * The ready-made bundle this role starts with. An administrator can add or remove single permissions per person
     * (User::permissions()). These reproduce what each role could do before permissions existed.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Journalist => [Permission::WriteStories],
            self::Editor => [
                Permission::WriteStories, Permission::WriteInAllSections, Permission::EditAllStories,
                Permission::PublishStories, Permission::DeleteStories, Permission::ManageSections,
                Permission::ManageTags, Permission::ManagePages, Permission::ManageBroadcasts,
                Permission::ViewSubscribers,
            ],
            self::Admin => Permission::cases(),
        };
    }

    /** The name staff read in the panel. */
    public function label(): string
    {
        return match ($this) {
            self::Journalist => __('Journalist'),
            self::Editor => __('Editor-in-chief'),
            self::Admin => __('Admin'),
        };
    }
}
