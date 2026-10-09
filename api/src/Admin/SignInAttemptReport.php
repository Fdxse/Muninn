<?php

declare(strict_types=1);

namespace Muninn\Api\Admin;

use Muninn\Api\Database\UtcTimestamp;
use Muninn\Api\Logging\AuditLog;
use PDO;

/**
 * The "Sign-in attempts" part of the administrator's overview (D060), read from the audit log.
 *
 * Failed sign-ins are stored with the username exactly as it was typed, which is sometimes a
 * password typed into the wrong field. The report therefore masks those usernames ("A•••n")
 * unless the administrator explicitly asks to reveal them; the controller audits every reveal.
 */
final class SignInAttemptReport
{
    /** How many recent failures and top usernames/IP addresses are listed. */
    private const RECENT_FAILURE_LIMIT = 20;
    private const TOP_LIST_LIMIT = 10;

    /** Days covered by the "top usernames" and "top IP addresses" lists. */
    private const TOP_LIST_DAYS = 30;

    /** The character that replaces hidden letters. */
    private const MASK_CHARACTER = '•';

    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(bool $revealUsernames): array
    {
        $recentFailures = $this->recentFailures();
        $topUsernames = $this->topUsernames();

        // One lookup tells which typed names belong to a real account: an attack on "admin" looks
        // different from random names, and the administrator sees that even while names are masked.
        $typedUsernames = array_merge(
            array_column($recentFailures, 'username'),
            array_column($topUsernames, 'username'),
        );
        $existingUsernames = $this->existingUsernames($typedUsernames);

        $presentUsername = function (string $typedUsername) use ($revealUsernames, $existingUsernames): array {
            return [
                'username' => $revealUsernames ? $typedUsername : self::maskUsername($typedUsername),
                'account_exists' => isset($existingUsernames[mb_strtolower($typedUsername)]),
            ];
        };

        return [
            'usernames_revealed' => $revealUsernames,
            'totals' => [
                'last_24_hours' => $this->totalsSince('1 DAY'),
                'last_7_days' => $this->totalsSince('7 DAY'),
                'last_30_days' => $this->totalsSince('30 DAY'),
            ],
            'top_usernames' => array_map(static fn (array $usernameRow): array => $presentUsername($usernameRow['username']) + [
                'attempts' => $usernameRow['attempts'],
            ], $topUsernames),
            'top_ip_addresses' => $this->topIpAddresses(),
            'recent_failures' => array_map(static fn (array $failureRow): array => [
                'at' => $failureRow['at'],
                'ip_address' => $failureRow['ip_address'],
                'blocked' => $failureRow['blocked'],
            ] + $presentUsername($failureRow['username']), $recentFailures),
        ];
    }

    /**
     * Hides all but the first and last character: "Admin" → "A•••n", "bob" → "b•••", "x" → "•••".
     * The mask always has three dots, so it does not reveal the length of what was typed.
     */
    public static function maskUsername(string $typedUsername): string
    {
        $characterCount = mb_strlen($typedUsername);
        $hiddenPart = str_repeat(self::MASK_CHARACTER, 3);
        if ($characterCount <= 2) {
            return $hiddenPart;
        }
        if ($characterCount === 3) {
            return mb_substr($typedUsername, 0, 1) . $hiddenPart;
        }

        return mb_substr($typedUsername, 0, 1) . $hiddenPart . mb_substr($typedUsername, -1);
    }

    /**
     * Successful, failed and blocked sign-ins since the given interval ago.
     *
     * @param string $intervalExpression A fixed SQL interval such as '7 DAY' (never user input).
     * @return array{succeeded: int, failed: int, blocked: int}
     */
    private function totalsSince(string $intervalExpression): array
    {
        $totalsStatement = $this->database->prepare(
            'SELECT event_type, COUNT(*) AS event_count FROM audit_log
             WHERE event_type IN (:succeeded, :failed, :blocked)
               AND created_at >= UTC_TIMESTAMP() - INTERVAL ' . $intervalExpression . '
             GROUP BY event_type'
        );
        $totalsStatement->execute([
            'succeeded' => AuditLog::LOGIN_SUCCEEDED,
            'failed' => AuditLog::LOGIN_FAILED,
            'blocked' => AuditLog::LOGIN_RATE_LIMITED,
        ]);
        $countsByEventType = array_map('intval', $totalsStatement->fetchAll(PDO::FETCH_KEY_PAIR));

        return [
            'succeeded' => $countsByEventType[AuditLog::LOGIN_SUCCEEDED] ?? 0,
            'failed' => $countsByEventType[AuditLog::LOGIN_FAILED] ?? 0,
            'blocked' => $countsByEventType[AuditLog::LOGIN_RATE_LIMITED] ?? 0,
        ];
    }

    /**
     * The latest failed and blocked sign-ins, newest first.
     *
     * @return list<array{at: string, username: string, ip_address: string|null, blocked: bool}>
     */
    private function recentFailures(): array
    {
        $failuresStatement = $this->database->prepare(
            'SELECT event_type, details, ip_address, created_at FROM audit_log
             WHERE event_type IN (:failed, :blocked)
             ORDER BY created_at DESC, id DESC
             LIMIT ' . self::RECENT_FAILURE_LIMIT
        );
        $failuresStatement->execute(['failed' => AuditLog::LOGIN_FAILED, 'blocked' => AuditLog::LOGIN_RATE_LIMITED]);

        return array_map(static fn (array $failureRow): array => [
            'at' => UtcTimestamp::toIso((string) $failureRow['created_at']),
            'username' => self::usernameFromDetails((string) $failureRow['details']),
            'ip_address' => $failureRow['ip_address'],
            'blocked' => $failureRow['event_type'] === AuditLog::LOGIN_RATE_LIMITED,
        ], $failuresStatement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The usernames with the most failed or blocked sign-ins in the last 30 days.
     *
     * @return list<array{username: string, attempts: int}>
     */
    private function topUsernames(): array
    {
        // JSON_VALUE reads the username the audit entry stored in its details (AuthController).
        $usernamesStatement = $this->database->prepare(
            "SELECT JSON_VALUE(details, '$.username') AS typed_username, COUNT(*) AS attempt_count
             FROM audit_log
             WHERE event_type IN (:failed, :blocked)
               AND created_at >= UTC_TIMESTAMP() - INTERVAL " . self::TOP_LIST_DAYS . ' DAY
             GROUP BY typed_username
             ORDER BY attempt_count DESC, typed_username
             LIMIT ' . self::TOP_LIST_LIMIT
        );
        $usernamesStatement->execute(['failed' => AuditLog::LOGIN_FAILED, 'blocked' => AuditLog::LOGIN_RATE_LIMITED]);

        return array_map(static fn (array $usernameRow): array => [
            'username' => (string) ($usernameRow['typed_username'] ?? ''),
            'attempts' => (int) $usernameRow['attempt_count'],
        ], $usernamesStatement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The IP addresses with the most failed or blocked sign-ins in the last 30 days.
     *
     * @return list<array{ip_address: string, attempts: int}>
     */
    private function topIpAddresses(): array
    {
        $addressesStatement = $this->database->prepare(
            'SELECT ip_address, COUNT(*) AS attempt_count FROM audit_log
             WHERE event_type IN (:failed, :blocked) AND ip_address IS NOT NULL
               AND created_at >= UTC_TIMESTAMP() - INTERVAL ' . self::TOP_LIST_DAYS . ' DAY
             GROUP BY ip_address
             ORDER BY attempt_count DESC, ip_address
             LIMIT ' . self::TOP_LIST_LIMIT
        );
        $addressesStatement->execute(['failed' => AuditLog::LOGIN_FAILED, 'blocked' => AuditLog::LOGIN_RATE_LIMITED]);

        return array_map(static fn (array $addressRow): array => [
            'ip_address' => (string) $addressRow['ip_address'],
            'attempts' => (int) $addressRow['attempt_count'],
        ], $addressesStatement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Which of the typed usernames belong to an account, as a set keyed by lower-case username.
     *
     * @param list<string> $typedUsernames
     * @return array<string, true>
     */
    private function existingUsernames(array $typedUsernames): array
    {
        $distinctUsernames = array_values(array_unique(array_filter($typedUsernames, static fn (string $typedUsername): bool => $typedUsername !== '')));
        if ($distinctUsernames === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($distinctUsernames), '?'));
        $lookupStatement = $this->database->prepare('SELECT username FROM users WHERE username IN (' . $placeholders . ')');
        $lookupStatement->execute($distinctUsernames);

        $existingUsernames = [];
        foreach ($lookupStatement->fetchAll(PDO::FETCH_COLUMN) as $existingUsername) {
            $existingUsernames[mb_strtolower((string) $existingUsername)] = true;
        }

        return $existingUsernames;
    }

    /** The username an audit entry stored in its JSON details, or '' when there is none. */
    private static function usernameFromDetails(string $detailsJson): string
    {
        $details = json_decode($detailsJson, true);

        return is_array($details) && is_string($details['username'] ?? null) ? $details['username'] : '';
    }
}
