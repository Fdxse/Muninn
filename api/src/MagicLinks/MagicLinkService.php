<?php

declare(strict_types=1);

namespace Muninn\Api\MagicLinks;

use DateTimeImmutable;
use DateTimeZone;
use Muninn\Api\Database\UtcTimestamp;
use Muninn\Api\Http\HttpException;
use Muninn\Api\Security\SecretToken;
use Muninn\Api\Security\UuidGenerator;
use Muninn\Api\Workspaces\WorkspaceMembership;
use PDO;

/**
 * Magic Links (D019, D059): creating, listing and revoking them, opening one in a browser, and
 * checking that browser's access again on every request.
 *
 * Like the other services it never decides on its own who may manage links: the management
 * methods take the WorkspaceMembership that WorkspaceAuthorizer produced (Admin or Owner).
 * Whether a link may be USED is decided here, in one place (statusOf()), from the database on
 * every request, so revoking a link, demoting or disabling its creator, trashing its note or
 * deleting its folder all take effect immediately.
 */
final class MagicLinkService
{
    /** Status: the link works (possibly outside its daily window at this moment). */
    public const STATUS_ACTIVE = 'active';
    /** Status: valid_from is still in the future. */
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';
    /** Status: the creator was disabled, left the workspace, or is no longer an Admin or Owner. */
    public const STATUS_CREATOR_LOST_ACCESS = 'creator_lost_access';
    /** Status: the folder was deleted, or the note was moved to Trash or deleted. */
    public const STATUS_TARGET_GONE = 'target_gone';

    /** Most links a workspace list returns; far more than anyone needs. */
    private const LIST_LIMIT = 200;

    /** Columns every link query selects: the link, its workspace, its creator and its target. */
    private const LINK_SELECT = '
        SELECT magic_links.*,
               workspaces.name AS workspace_name, workspaces.kind AS workspace_kind,
               creator.username AS creator_username, creator.display_name AS creator_name,
               creator.status AS creator_status, creator.is_system_admin AS creator_is_system_admin,
               creator_membership.role AS creator_role,
               target_folder.name AS target_folder_name,
               target_note.title AS target_note_title
        FROM magic_links
        JOIN workspaces ON workspaces.id = magic_links.workspace_id
        JOIN users AS creator ON creator.id = magic_links.created_by_user_id
        LEFT JOIN workspace_members AS creator_membership
               ON creator_membership.workspace_id = magic_links.workspace_id
              AND creator_membership.user_id = magic_links.created_by_user_id
        LEFT JOIN folders AS target_folder
               ON magic_links.target_type = \'folder\'
              AND target_folder.id = magic_links.target_id
              AND target_folder.workspace_id = magic_links.workspace_id
        LEFT JOIN notes AS target_note
               ON magic_links.target_type = \'note\'
              AND target_note.id = magic_links.target_id
              AND target_note.workspace_id = magic_links.workspace_id
              AND target_note.trashed_at IS NULL';

    public function __construct(
        private readonly PDO $database,
        private readonly DateTimeZone $windowTimeZone,
        private readonly int $visitHours,
    ) {
    }

    /** The time zone daily windows are evaluated in (shown next to every window). */
    public function windowTimeZoneName(): string
    {
        return $this->windowTimeZone->getName();
    }

    /**
     * Creates a link in the membership's workspace and returns it with the raw token, which
     * exists only in this return value: the database keeps its hash.
     *
     * @return array{magic_link: array<string, mixed>, raw_token: string}
     * @throws HttpException 422 when the folder or note is not an active one of this workspace.
     */
    public function create(WorkspaceMembership $creatorMembership, MagicLinkInput $linkInput): array
    {
        $this->requireTargetInWorkspace($creatorMembership->workspaceId, $linkInput->targetType, $linkInput->targetId);

        $newLinkId = UuidGenerator::generate();
        $rawToken = SecretToken::generate();
        $insertStatement = $this->database->prepare(
            'INSERT INTO magic_links (id, workspace_id, target_type, target_id, label, permission, token_hash,
                                      valid_from, valid_until, daily_start_time, daily_end_time,
                                      created_by_user_id, created_at)
             VALUES (:id, :workspace_id, :target_type, :target_id, :label, :permission, :token_hash,
                     :valid_from, :valid_until, :daily_start_time, :daily_end_time, :created_by_user_id, UTC_TIMESTAMP())'
        );
        $insertStatement->execute([
            'id' => $newLinkId,
            'workspace_id' => $creatorMembership->workspaceId,
            'target_type' => $linkInput->targetType,
            'target_id' => $linkInput->targetId,
            'label' => $linkInput->label,
            'permission' => $linkInput->permission,
            'token_hash' => SecretToken::hash($rawToken),
            'valid_from' => $linkInput->validFromUtc->format('Y-m-d H:i:s'),
            'valid_until' => $linkInput->validUntilUtc->format('Y-m-d H:i:s'),
            'daily_start_time' => $linkInput->dailyStartTime,
            'daily_end_time' => $linkInput->dailyEndTime,
            'created_by_user_id' => $creatorMembership->userId,
        ]);

        return ['magic_link' => $this->findInWorkspace($creatorMembership, $newLinkId), 'raw_token' => $rawToken];
    }

    /**
     * Lists the workspace's links, newest first, revoked and expired ones included. Never tokens.
     *
     * @return list<array<string, mixed>>
     */
    public function listForWorkspace(WorkspaceMembership $membership): array
    {
        $selectStatement = $this->database->prepare(
            self::LINK_SELECT . '
             WHERE magic_links.workspace_id = :workspace_id
             ORDER BY magic_links.created_at DESC, magic_links.id
             LIMIT ' . self::LIST_LIMIT
        );
        $selectStatement->execute(['workspace_id' => $membership->workspaceId]);

        return array_map($this->toWorkspaceArray(...), $selectStatement->fetchAll());
    }

    /**
     * Returns one link of the membership's workspace.
     *
     * @return array<string, mixed>
     * @throws HttpException 404 when it is not a link of that workspace.
     */
    public function findInWorkspace(WorkspaceMembership $membership, string $linkId): array
    {
        $linkRow = $this->findRow('magic_links.id = :id AND magic_links.workspace_id = :workspace_id', [
            'id' => $linkId,
            'workspace_id' => $membership->workspaceId,
        ]);
        if ($linkRow === null) {
            throw HttpException::notFound();
        }

        return $this->toWorkspaceArray($linkRow);
    }

    /**
     * Finds which workspace a link belongs to, so the caller's role there can be checked before
     * it is revoked. Returns null for unknown or malformed IDs.
     */
    public function findWorkspaceIdOfLink(string $linkId): ?string
    {
        if (!UuidGenerator::isValid($linkId)) {
            return null;
        }
        $selectStatement = $this->database->prepare('SELECT workspace_id FROM magic_links WHERE id = :id');
        $selectStatement->execute(['id' => $linkId]);
        $workspaceId = $selectStatement->fetchColumn();

        return $workspaceId === false ? null : (string) $workspaceId;
    }

    /**
     * Revokes a link of the membership's workspace. Revoking twice is harmless.
     *
     * @return bool True when the link was revoked now, false when it already was.
     * @throws HttpException 404 when it is not a link of that workspace.
     */
    public function revoke(WorkspaceMembership $membership, string $linkId): bool
    {
        $this->findInWorkspace($membership, $linkId);

        return $this->markRevoked($linkId, $membership->userId);
    }

    /**
     * Every link in the installation for the system administrator's overview (PROJECT.md).
     * Like the workspace overview (D050) it shows no note data: no labels, folder names or note
     * titles, only who made which kind of link in which workspace and its state.
     *
     * @return list<array<string, mixed>>
     */
    public function listForAdministrator(): array
    {
        $selectStatement = $this->database->query(
            self::LINK_SELECT . '
             ORDER BY magic_links.created_at DESC, magic_links.id
             LIMIT 1000'
        );

        return array_map(function (array $linkRow): array {
            return [
                'id' => $linkRow['id'],
                'workspace_name' => $linkRow['workspace_name'],
                'workspace_kind' => $linkRow['workspace_kind'],
                'target_type' => $linkRow['target_type'],
                'permission' => $linkRow['permission'],
                'status' => $this->statusOf($linkRow, self::nowUtc()),
                'created_by_username' => $linkRow['creator_username'],
                'created_at' => UtcTimestamp::toIso((string) $linkRow['created_at']),
                'valid_until' => UtcTimestamp::toIso((string) $linkRow['valid_until']),
                'revoked_at' => UtcTimestamp::toIsoOrNull($linkRow['revoked_at']),
                'last_used_at' => UtcTimestamp::toIsoOrNull($linkRow['last_used_at']),
                'use_count' => (int) $linkRow['use_count'],
            ];
        }, $selectStatement->fetchAll());
    }

    /**
     * Revokes any link, for the system administrator. Returns the link's workspace ID for the
     * audit log.
     *
     * @throws HttpException 404 for unknown IDs.
     */
    public function revokeAsAdministrator(string $linkId, string $administratorUserId): string
    {
        $workspaceId = $this->findWorkspaceIdOfLink($linkId);
        if ($workspaceId === null) {
            throw HttpException::notFound();
        }
        $this->markRevoked($linkId, $administratorUserId);

        return $workspaceId;
    }

    /**
     * Opens a link from its raw token: when the link can be used right now, starts a visit
     * (a separate cookie session) and counts the use.
     *
     * @return array{outcome: string, link_id: string|null, raw_visit_token: string|null, daily_start_time: string|null, daily_end_time: string|null}
     *         outcome is 'opened', 'unknown' (no such token), 'unavailable' (revoked, expired,
     *         not yet valid, creator lost access, target gone) or 'outside_daily_window'.
     */
    public function open(string $rawToken): array
    {
        $result = ['outcome' => 'unknown', 'link_id' => null, 'raw_visit_token' => null, 'daily_start_time' => null, 'daily_end_time' => null];
        if (!SecretToken::looksValid($rawToken)) {
            return $result;
        }
        $linkRow = $this->findRow('magic_links.token_hash = :token_hash', ['token_hash' => SecretToken::hash($rawToken)]);
        if ($linkRow === null) {
            return $result;
        }
        $result['link_id'] = (string) $linkRow['id'];

        $momentUtc = self::nowUtc();
        if ($this->statusOf($linkRow, $momentUtc) !== self::STATUS_ACTIVE) {
            $result['outcome'] = 'unavailable';
            return $result;
        }
        if ($this->scheduleOf($linkRow)->check($momentUtc) === MagicLinkSchedule::OUTSIDE_DAILY_WINDOW) {
            $result['outcome'] = 'outside_daily_window';
            $result['daily_start_time'] = self::shortTime($linkRow['daily_start_time']);
            $result['daily_end_time'] = self::shortTime($linkRow['daily_end_time']);
            return $result;
        }

        // The visit never outlives the link itself.
        $visitEndUtc = $momentUtc->modify('+' . $this->visitHours . ' hours');
        $validUntilUtc = self::utcFromDatabase((string) $linkRow['valid_until']);
        if ($validUntilUtc < $visitEndUtc) {
            $visitEndUtc = $validUntilUtc;
        }

        $rawVisitToken = SecretToken::generate();
        $insertStatement = $this->database->prepare(
            'INSERT INTO magic_link_sessions (id, magic_link_id, token_hash, csrf_token, created_at, expires_at)
             VALUES (:id, :magic_link_id, :token_hash, :csrf_token, UTC_TIMESTAMP(), :expires_at)'
        );
        $insertStatement->execute([
            'id' => UuidGenerator::generate(),
            'magic_link_id' => $linkRow['id'],
            'token_hash' => SecretToken::hash($rawVisitToken),
            'csrf_token' => SecretToken::generate(),
            'expires_at' => $visitEndUtc->format('Y-m-d H:i:s'),
        ]);
        $useStatement = $this->database->prepare(
            'UPDATE magic_links SET last_used_at = UTC_TIMESTAMP(), use_count = use_count + 1 WHERE id = :id'
        );
        $useStatement->execute(['id' => $linkRow['id']]);

        $result['outcome'] = 'opened';
        $result['raw_visit_token'] = $rawVisitToken;

        return $result;
    }

    /**
     * Resolves a visit cookie to what it may reach right now, or null. Called on every visitor
     * request, so every rule of the link is applied again each time.
     */
    public function findActiveVisit(?string $rawVisitToken): ?MagicLinkAccess
    {
        if ($rawVisitToken === null || !SecretToken::looksValid($rawVisitToken)) {
            return null;
        }
        $visitStatement = $this->database->prepare(
            'SELECT id, magic_link_id, csrf_token FROM magic_link_sessions
             WHERE token_hash = :token_hash AND ended_at IS NULL AND expires_at > UTC_TIMESTAMP()'
        );
        $visitStatement->execute(['token_hash' => SecretToken::hash($rawVisitToken)]);
        $visitRow = $visitStatement->fetch();
        if ($visitRow === false) {
            return null;
        }

        $linkRow = $this->findRow('magic_links.id = :id', ['id' => $visitRow['magic_link_id']]);
        if ($linkRow === null) {
            return null;
        }
        $momentUtc = self::nowUtc();
        if ($this->statusOf($linkRow, $momentUtc) !== self::STATUS_ACTIVE
            || $this->scheduleOf($linkRow)->check($momentUtc) !== MagicLinkSchedule::USABLE) {
            return null;
        }

        return new MagicLinkAccess(
            visitId: (string) $visitRow['id'],
            csrfToken: (string) $visitRow['csrf_token'],
            linkId: (string) $linkRow['id'],
            label: (string) $linkRow['label'],
            workspaceId: (string) $linkRow['workspace_id'],
            workspaceName: (string) $linkRow['workspace_name'],
            workspaceKind: (string) $linkRow['workspace_kind'],
            targetType: (string) $linkRow['target_type'],
            targetId: $linkRow['target_id'] === null ? null : (string) $linkRow['target_id'],
            permission: (string) $linkRow['permission'],
            creatorUserId: (string) $linkRow['created_by_user_id'],
            validUntilIso: UtcTimestamp::toIso((string) $linkRow['valid_until']),
        );
    }

    /** Ends a visit ("Close link"); its cookie stops working at once. */
    public function endVisit(string $visitId): void
    {
        $endStatement = $this->database->prepare(
            'UPDATE magic_link_sessions SET ended_at = UTC_TIMESTAMP() WHERE id = :id AND ended_at IS NULL'
        );
        $endStatement->execute(['id' => $visitId]);
    }

    /** Ends the visit behind a raw cookie token, if any (used when the browser opens a link again). */
    public function endVisitByRawToken(?string $rawVisitToken): void
    {
        if ($rawVisitToken === null || !SecretToken::looksValid($rawVisitToken)) {
            return;
        }
        $endStatement = $this->database->prepare(
            'UPDATE magic_link_sessions SET ended_at = UTC_TIMESTAMP() WHERE token_hash = :token_hash AND ended_at IS NULL'
        );
        $endStatement->execute(['token_hash' => SecretToken::hash($rawVisitToken)]);
    }

    /**
     * The one place that decides whether a link is usable, apart from its daily window.
     *
     * @param array<string, mixed> $linkRow A row selected with LINK_SELECT.
     */
    private function statusOf(array $linkRow, DateTimeImmutable $momentUtc): string
    {
        if ($linkRow['revoked_at'] !== null) {
            return self::STATUS_REVOKED;
        }

        // The creator must still be an active, everyday account with Admin or Owner rights here.
        $creatorRole = $linkRow['creator_role'];
        $creatorStillEntitled = $linkRow['creator_status'] === 'active'
            && (int) $linkRow['creator_is_system_admin'] === 0
            && ($creatorRole === 'owner' || $creatorRole === 'admin');
        if (!$creatorStillEntitled) {
            return self::STATUS_CREATOR_LOST_ACCESS;
        }

        $targetStillThere = match ((string) $linkRow['target_type']) {
            MagicLinkAccess::TARGET_WORKSPACE => true,
            MagicLinkAccess::TARGET_FOLDER => $linkRow['target_folder_name'] !== null,
            MagicLinkAccess::TARGET_NOTE => $linkRow['target_note_title'] !== null,
            default => false,
        };
        if (!$targetStillThere) {
            return self::STATUS_TARGET_GONE;
        }

        return match ($this->scheduleOf($linkRow)->check($momentUtc)) {
            MagicLinkSchedule::NOT_YET_VALID => self::STATUS_SCHEDULED,
            MagicLinkSchedule::EXPIRED => self::STATUS_EXPIRED,
            // Outside the daily window the link is still active; it just waits for its hours.
            default => self::STATUS_ACTIVE,
        };
    }

    /** @param array<string, mixed> $linkRow */
    private function scheduleOf(array $linkRow): MagicLinkSchedule
    {
        return new MagicLinkSchedule(
            self::utcFromDatabase((string) $linkRow['valid_from']),
            self::utcFromDatabase((string) $linkRow['valid_until']),
            $linkRow['daily_start_time'] === null ? null : (string) $linkRow['daily_start_time'],
            $linkRow['daily_end_time'] === null ? null : (string) $linkRow['daily_end_time'],
            $this->windowTimeZone,
        );
    }

    /**
     * The shape the workspace's Admins and Owners see. Never contains the token or its hash.
     *
     * @param array<string, mixed> $linkRow
     * @return array<string, mixed>
     */
    private function toWorkspaceArray(array $linkRow): array
    {
        $targetName = match ((string) $linkRow['target_type']) {
            MagicLinkAccess::TARGET_WORKSPACE => $linkRow['workspace_name'],
            MagicLinkAccess::TARGET_FOLDER => $linkRow['target_folder_name'],
            default => $linkRow['target_note_title'],
        };

        return [
            'id' => $linkRow['id'],
            'label' => $linkRow['label'],
            'target_type' => $linkRow['target_type'],
            'target_id' => $linkRow['target_id'],
            // Null when the folder or note is gone (or in Trash).
            'target_name' => $targetName,
            'permission' => $linkRow['permission'],
            'status' => $this->statusOf($linkRow, self::nowUtc()),
            'valid_from' => UtcTimestamp::toIso((string) $linkRow['valid_from']),
            'valid_until' => UtcTimestamp::toIso((string) $linkRow['valid_until']),
            'daily_start_time' => self::shortTime($linkRow['daily_start_time']),
            'daily_end_time' => self::shortTime($linkRow['daily_end_time']),
            'timezone' => $this->windowTimeZone->getName(),
            'created_by' => $linkRow['creator_name'],
            'created_at' => UtcTimestamp::toIso((string) $linkRow['created_at']),
            'revoked_at' => UtcTimestamp::toIsoOrNull($linkRow['revoked_at']),
            'last_used_at' => UtcTimestamp::toIsoOrNull($linkRow['last_used_at']),
            'use_count' => (int) $linkRow['use_count'],
        ];
    }

    /**
     * @param array<string, mixed> $queryParameters
     * @return array<string, mixed>|null
     */
    private function findRow(string $whereClause, array $queryParameters): ?array
    {
        $selectStatement = $this->database->prepare(self::LINK_SELECT . ' WHERE ' . $whereClause);
        $selectStatement->execute($queryParameters);
        $linkRow = $selectStatement->fetch();

        return $linkRow === false ? null : $linkRow;
    }

    /** Sets revoked_at once; returns true when this call revoked the link. */
    private function markRevoked(string $linkId, string $revokedByUserId): bool
    {
        $revokeStatement = $this->database->prepare(
            'UPDATE magic_links SET revoked_at = UTC_TIMESTAMP(), revoked_by_user_id = :revoked_by
             WHERE id = :id AND revoked_at IS NULL'
        );
        $revokeStatement->execute(['id' => $linkId, 'revoked_by' => $revokedByUserId]);

        return $revokeStatement->rowCount() === 1;
    }

    /**
     * Checks that the link's folder or note is an active one of the workspace, so a link can never
     * point at (or reveal) another workspace's content.
     *
     * @throws HttpException 422 otherwise.
     */
    private function requireTargetInWorkspace(string $workspaceId, string $targetType, ?string $targetId): void
    {
        if ($targetType === MagicLinkAccess::TARGET_WORKSPACE) {
            return;
        }
        $targetQuery = $targetType === MagicLinkAccess::TARGET_FOLDER
            ? 'SELECT 1 FROM folders WHERE id = :id AND workspace_id = :workspace_id'
            : 'SELECT 1 FROM notes WHERE id = :id AND workspace_id = :workspace_id AND trashed_at IS NULL';
        $targetExists = false;
        if ($targetId !== null && UuidGenerator::isValid($targetId)) {
            $selectStatement = $this->database->prepare($targetQuery);
            $selectStatement->execute(['id' => $targetId, 'workspace_id' => $workspaceId]);
            $targetExists = $selectStatement->fetchColumn() !== false;
        }
        if (!$targetExists) {
            throw HttpException::validation(['target_id' => $targetType === MagicLinkAccess::TARGET_FOLDER
                ? 'This folder does not exist in this workspace.'
                : 'This note does not exist in this workspace.']);
        }
    }

    /** "07:00:00" from the database → "07:00"; null stays null. */
    private static function shortTime(mixed $databaseTime): ?string
    {
        return $databaseTime === null ? null : substr((string) $databaseTime, 0, 5);
    }

    private static function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function utcFromDatabase(string $databaseDateTime): DateTimeImmutable
    {
        return new DateTimeImmutable($databaseDateTime, new DateTimeZone('UTC'));
    }
}
