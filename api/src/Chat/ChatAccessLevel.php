<?php

declare(strict_types=1);

namespace Muninn\Api\Chat;

/**
 * How much chat an account may use (decision D062). The system administrator picks one per
 * account on the Users page. Each level includes everything the levels before it allow:
 *
 *   Off               no chat at all, not even reading the global channel
 *   OwnWorkspaces     chat in shared workspaces where the user is Owner; read the global channel
 *   MemberWorkspaces  chat in every shared workspace the user is a member of; read the global channel
 *   Global            MemberWorkspaces, plus writing in the global channel ("shout out")
 *
 * The level only ever takes access away: the workspace role still decides who may read
 * (everyone) and write (Editor and up) in a workspace's chat. See ChatPolicy.
 */
enum ChatAccessLevel: string
{
    case Off = 'off';
    case OwnWorkspaces = 'own_workspaces';
    case MemberWorkspaces = 'member_workspaces';
    case Global = 'global';

    /** Parses a level sent by a client, or null when it is not a known level. */
    public static function tryFromInput(mixed $levelInput): ?self
    {
        return is_string($levelInput) ? self::tryFrom($levelInput) : null;
    }

    /** @return list<string> Every level, as the API spells them. */
    public static function inputValues(): array
    {
        return array_map(static fn (self $level): string => $level->value, self::cases());
    }
}
