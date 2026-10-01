<?php

declare(strict_types=1);

namespace Muninn\Api\Database;

use PDO;

/**
 * Creates PDO connections with Muninn's required settings:
 * exceptions on error, real prepared statements, utf8mb4, and the session time zone
 * pinned to UTC so that UTC_TIMESTAMP() and stored DATETIME values always agree.
 */
final class ConnectionFactory
{
    public static function create(string $host, int $port, string $databaseName, string $username, string $password): PDO
    {
        $dataSourceName = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $databaseName);

        $connection = new PDO($dataSourceName, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real server-side prepared statements, never emulated string building.
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $connection->exec("SET time_zone = '+00:00'");

        return $connection;
    }
}
