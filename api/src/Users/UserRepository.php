<?php

declare(strict_types=1);

namespace Muninn\Api\Users;

use Muninn\Api\Chat\ChatAccessLevel;
use Muninn\Api\Security\UuidGenerator;
use PDO;

/**
 * Database access for users. All queries are parameterised.
 */
final class UserRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function findById(string $userId): ?User
    {
        $selectStatement = $this->database->prepare('SELECT * FROM users WHERE id = :id');
        $selectStatement->execute(['id' => $userId]);
        $userRow = $selectStatement->fetch();

        return $userRow === false ? null : User::fromRow($userRow);
    }

    /** Looks up a user by username (case-insensitive, usernames are stored lowercase). */
    public function findByUsername(string $username): ?User
    {
        $selectStatement = $this->database->prepare('SELECT * FROM users WHERE username = :username');
        $selectStatement->execute(['username' => UserInputRules::normaliseUsername($username)]);
        $userRow = $selectStatement->fetch();

        return $userRow === false ? null : User::fromRow($userRow);
    }

    public function usernameExists(string $username): bool
    {
        return $this->findByUsername($username) !== null;
    }

    /**
     * Inserts a new active user and returns its ID.
     *
     * @throws \PDOException with SQLSTATE 23000 when the username is already taken.
     */
    public function create(string $username, string $displayName, string $passwordHash, bool $isSystemAdmin): string
    {
        $newUserId = UuidGenerator::generate();
        $insertStatement = $this->database->prepare(
            'INSERT INTO users (id, username, display_name, password_hash, is_system_admin, status, created_at, updated_at)
             VALUES (:id, :username, :display_name, :password_hash, :is_system_admin, \'active\', UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $insertStatement->execute([
            'id' => $newUserId,
            'username' => UserInputRules::normaliseUsername($username),
            'display_name' => $displayName,
            'password_hash' => $passwordHash,
            'is_system_admin' => $isSystemAdmin ? 1 : 0,
        ]);

        return $newUserId;
    }

    public function updatePasswordHash(string $userId, string $passwordHash): void
    {
        $updateStatement = $this->database->prepare(
            'UPDATE users SET password_hash = :password_hash, updated_at = UTC_TIMESTAMP() WHERE id = :id'
        );
        $updateStatement->execute(['password_hash' => $passwordHash, 'id' => $userId]);
    }

    public function recordLogin(string $userId): void
    {
        $updateStatement = $this->database->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id');
        $updateStatement->execute(['id' => $userId]);
    }

    /**
     * Lists every account for the admin overview, newest first. Never returns password hashes.
     *
     * @return list<array<string, mixed>>
     */
    public function listForAdmin(): array
    {
        return $this->database->query(
            'SELECT id, username, display_name, is_system_admin, status, chat_access, created_at, last_login_at
             FROM users
             ORDER BY created_at DESC, id
             LIMIT 500'
        )->fetchAll();
    }

    /** Sets how much chat an account may use (D062). The value is a validated ChatAccessLevel. */
    public function setChatAccess(string $userId, ChatAccessLevel $chatAccess): void
    {
        $updateStatement = $this->database->prepare(
            'UPDATE users SET chat_access = :chat_access, updated_at = UTC_TIMESTAMP() WHERE id = :id'
        );
        $updateStatement->execute(['chat_access' => $chatAccess->value, 'id' => $userId]);
    }

    /** Sets an account to 'active' or 'disabled' (decision D031: accounts are disabled, never deleted). */
    public function setStatus(string $userId, string $newStatus): void
    {
        $updateStatement = $this->database->prepare(
            'UPDATE users SET status = :status, updated_at = UTC_TIMESTAMP() WHERE id = :id'
        );
        $updateStatement->execute(['status' => $newStatus, 'id' => $userId]);
    }
}
