<?php

declare(strict_types=1);

namespace Muninn\Api\Users;

use Muninn\Api\Chat\ChatAccessLevel;

/**
 * A user account as loaded from the database.
 */
final class User
{
    public function __construct(
        public readonly string $id,
        public readonly string $username,
        public readonly string $displayName,
        public readonly string $passwordHash,
        public readonly bool $isSystemAdmin,
        public readonly string $status,
        /** How much chat the account may use (D062). Set by the system administrator. */
        public readonly ChatAccessLevel $chatAccess = ChatAccessLevel::MemberWorkspaces,
    ) {
    }

    /** @param array<string, mixed> $databaseRow */
    public static function fromRow(array $databaseRow): self
    {
        return new self(
            id: (string) $databaseRow['id'],
            username: (string) $databaseRow['username'],
            displayName: (string) $databaseRow['display_name'],
            passwordHash: (string) $databaseRow['password_hash'],
            isSystemAdmin: (bool) $databaseRow['is_system_admin'],
            status: (string) $databaseRow['status'],
            // An unknown or missing value (e.g. before migration 0009) means no chat at all.
            chatAccess: ChatAccessLevel::tryFrom((string) ($databaseRow['chat_access'] ?? '')) ?? ChatAccessLevel::Off,
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * The public representation returned by the API. Never includes the password hash.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'display_name' => $this->displayName,
            'is_system_admin' => $this->isSystemAdmin,
            // Lets the frontend show or hide the Chat link; the API checks every chat request itself.
            'chat_access' => $this->isSystemAdmin ? ChatAccessLevel::Off->value : $this->chatAccess->value,
        ];
    }
}
