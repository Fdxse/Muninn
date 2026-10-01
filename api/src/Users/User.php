<?php

declare(strict_types=1);

namespace Muninn\Api\Users;

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
        ];
    }
}
