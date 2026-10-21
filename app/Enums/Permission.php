<?php

namespace App\Enums;

/**
 * What a staff member may do in the panel. A role is a ready-made bundle of these (Role::permissions()); an
 * administrator can add or remove single permissions for one person on top of it (users.permissions_granted /
 * permissions_revoked). Policies ask User::hasPermission(), never the role.
 *
 * The role bundles reproduce exactly what each role could do before permissions existed, so nobody's access changed
 * when they were introduced.
 */
enum Permission: string
{
    case WriteStories = 'stories.write';
    case WriteInAllSections = 'stories.all_sections';
    case EditAllStories = 'stories.edit_all';
    case PublishStories = 'stories.publish';
    case DeleteStories = 'stories.delete';
    case PurgeStories = 'stories.purge';
    case ManageSections = 'sections.manage';
    case ManageTags = 'tags.manage';
    case ManagePages = 'pages.manage';
    case DeletePages = 'pages.delete';
    case ManageBroadcasts = 'broadcasts.manage';
    case ViewSubscribers = 'subscribers.view';
    case DeleteSubscribers = 'subscribers.delete';
    case ManageTeam = 'team.manage';
    case ManageSettings = 'settings.manage';

    /** The four boxes of checkboxes on the Team form, in display order. */
    public const GROUPS = ['stories', 'taxonomy', 'site', 'administration'];

    public function group(): string
    {
        return match ($this) {
            self::WriteStories, self::WriteInAllSections, self::EditAllStories, self::PublishStories,
            self::DeleteStories, self::PurgeStories => 'stories',
            self::ManageSections, self::ManageTags => 'taxonomy',
            self::ManagePages, self::DeletePages, self::ManageBroadcasts, self::ViewSubscribers,
            self::DeleteSubscribers => 'site',
            self::ManageTeam, self::ManageSettings => 'administration',
        };
    }

    public static function groupLabel(string $group): string
    {
        return match ($group) {
            'stories' => __('Stories'),
            'taxonomy' => __('Sections and tags'),
            'site' => __('Site'),
            'administration' => __('Administration'),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::WriteStories => __('Write stories and send them for review'),
            self::WriteInAllSections => __('Write in every section'),
            self::EditAllStories => __('See and edit everyone’s stories'),
            self::PublishStories => __('Approve and publish stories'),
            self::DeleteStories => __('Delete stories'),
            self::PurgeStories => __('Restore or permanently delete removed stories'),
            self::ManageSections => __('Create and edit sections'),
            self::ManageTags => __('Manage tags'),
            self::ManagePages => __('Edit the site’s pages'),
            self::DeletePages => __('Delete extra pages'),
            self::ManageBroadcasts => __('Manage live broadcasts'),
            self::ViewSubscribers => __('See newsletter subscribers'),
            self::DeleteSubscribers => __('Delete newsletter subscribers'),
            self::ManageTeam => __('Manage the team and permissions'),
            self::ManageSettings => __('Change site settings'),
        };
    }

    public function description(): ?string
    {
        return match ($this) {
            self::WriteInAllSections => __('Without it, only in the sections ticked below.'),
            self::PublishStories => __('Also schedule, request changes, unpublish and archive.'),
            self::DeletePages => __('The five core pages can never be deleted.'),
            self::ManageTeam => __('Create accounts, change roles and permissions. Give it only to people you trust with everything.'),
            default => null,
        };
    }

    /** @return list<self> */
    public static function inGroup(string $group): array
    {
        return array_values(array_filter(self::cases(), fn (self $p): bool => $p->group() === $group));
    }
}
