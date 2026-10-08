<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Tests\Support\IntegrationTestCase;
use Muninn\Api\Tests\Support\TestDatabase;
use PDO;

/**
 * Runs the full authentication and invitation flow, then proves that every security event
 * was audited and that no password or raw token was written anywhere: not to the log file,
 * not to the audit table, and not to any other table.
 */
final class AuditAndSecretsTest extends IntegrationTestCase
{
    public function testFullFlowIsAuditedAndLeaksNoSecrets(): void
    {
        $adminPassword = 'admin password that must never leak';
        $newUserPassword = 'new user password that must never leak';
        $this->createUser('admin', $adminPassword, true);

        // Failed login, successful login.
        $this->send('POST', '/api/v1/auth/login', ['username' => 'admin', 'password' => 'wrong but also secret']);
        $adminCredentials = $this->login('admin', $adminPassword);

        // Create, revoke, create again, accept.
        $revokedInvitation = $this->sendAs($adminCredentials, 'POST', '/api/v1/admin/invitations', [])->json()['data'];
        $this->sendAs($adminCredentials, 'DELETE', '/api/v1/admin/invitations/' . $revokedInvitation['invitation']['id']);
        $acceptedInvitationUrl = (string) $this->sendAs($adminCredentials, 'POST', '/api/v1/admin/invitations', [])->json()['data']['invitation_url'];
        $acceptedToken = substr($acceptedInvitationUrl, strpos($acceptedInvitationUrl, '#token=') + 7);
        $revokedToken = substr($revokedInvitation['invitation_url'], strpos($revokedInvitation['invitation_url'], '#token=') + 7);

        $acceptResponse = $this->send('POST', '/api/v1/invitations/accept', [
            'token' => $acceptedToken,
            'username' => 'newbie',
            'display_name' => 'Newbie',
            'password' => $newUserPassword,
        ]);
        $newUserSessionToken = $this->sessionTokenFrom($acceptResponse);

        // Logout, plus an internal error so the log file is certainly written to.
        $this->sendAs($adminCredentials, 'POST', '/api/v1/auth/logout');
        $this->application->router()->add('GET', '/api/v1/test/explode', function (): never {
            throw new \RuntimeException('forced failure');
        }, 'public');
        $this->send('GET', '/api/v1/test/explode');

        // 1. Every expected event type was recorded exactly as often as it happened.
        $recordedEventCounts = $this->database->query('SELECT event_type, COUNT(*) FROM audit_log GROUP BY event_type')->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame(1, (int) $recordedEventCounts[AuditLog::LOGIN_FAILED]);
        self::assertSame(1, (int) $recordedEventCounts[AuditLog::LOGIN_SUCCEEDED]);
        self::assertSame(2, (int) $recordedEventCounts[AuditLog::INVITATION_CREATED]);
        self::assertSame(1, (int) $recordedEventCounts[AuditLog::INVITATION_REVOKED]);
        self::assertSame(1, (int) $recordedEventCounts[AuditLog::INVITATION_ACCEPTED]);
        self::assertSame(1, (int) $recordedEventCounts[AuditLog::LOGOUT]);

        // 2. No secret appears in the log file or in any column of any table.
        $secretValues = [
            $adminPassword,
            $newUserPassword,
            'wrong but also secret',
            $adminCredentials['session_token'],
            $newUserSessionToken,
            $acceptedToken,
            $revokedToken,
        ];
        $logContents = (string) file_get_contents($this->logFilePath);
        self::assertNotSame('', $logContents);
        $databaseDump = $this->dumpAllTables();

        foreach ($secretValues as $secretValue) {
            self::assertStringNotContainsString($secretValue, $logContents, 'Secret found in log file.');
            self::assertStringNotContainsString($secretValue, $databaseDump, 'Secret found in database.');
        }
    }

    /**
     * Week 5 flows: password reset links (D040), changing one's own password, and links created
     * through invitation requests (D049). Same proof: audited, and no secret stored or logged.
     */
    public function testPasswordResetAndRequestedInvitationLeakNoSecrets(): void
    {
        $this->createUser('admin', self::DEFAULT_PASSWORD, true);
        $aliceId = $this->createUser('alice');
        $adminCredentials = $this->login('admin');
        $aliceCredentials = $this->login('alice');

        // Alice changes her own password.
        $changedPassword = 'changed password that must never leak';
        $this->sendAs($aliceCredentials, 'POST', '/api/v1/auth/password', [
            'current_password' => self::DEFAULT_PASSWORD,
            'new_password' => $changedPassword,
        ]);

        // The administrator creates a reset link and Alice uses it.
        $resetUrl = (string) $this->sendAs($adminCredentials, 'POST', '/api/v1/admin/users/' . $aliceId . '/password-reset', [])->json()['data']['reset_url'];
        $resetToken = substr($resetUrl, strpos($resetUrl, '#token=') + 7);
        $resetPassword = 'reset password that must never leak';
        $this->send('POST', '/api/v1/password-resets/complete', ['token' => $resetToken, 'password' => $resetPassword]);

        // Alice asks for an invitation, it is approved, and she creates the link.
        $aliceCredentials = $this->login('alice', $resetPassword);
        $requestId = (string) $this->sendAs($aliceCredentials, 'POST', '/api/v1/invitation-requests', ['note' => 'Carl'])->json()['data']['invitation_request']['id'];
        $this->sendAs($adminCredentials, 'POST', '/api/v1/admin/invitation-requests/' . $requestId . '/approve');
        $requestedUrl = (string) $this->sendAs($aliceCredentials, 'POST', '/api/v1/invitation-requests/' . $requestId . '/link')->json()['data']['invitation_url'];
        $requestedToken = substr($requestedUrl, strpos($requestedUrl, '#token=') + 7);

        $recordedEventCounts = $this->database->query('SELECT event_type, COUNT(*) FROM audit_log GROUP BY event_type')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ([
            AuditLog::PASSWORD_CHANGED,
            AuditLog::PASSWORD_RESET_CREATED,
            AuditLog::PASSWORD_RESET_COMPLETED,
            AuditLog::INVITATION_REQUEST_CREATED,
            AuditLog::INVITATION_REQUEST_APPROVED,
            AuditLog::INVITATION_REQUEST_LINK_CREATED,
        ] as $expectedEventType) {
            self::assertSame(1, (int) ($recordedEventCounts[$expectedEventType] ?? 0), $expectedEventType);
        }

        $logContents = is_file($this->logFilePath) ? (string) file_get_contents($this->logFilePath) : '';
        $databaseDump = $this->dumpAllTables();
        foreach ([$changedPassword, $resetPassword, $resetToken, $requestedToken, $aliceCredentials['session_token']] as $secretValue) {
            self::assertStringNotContainsString($secretValue, $logContents, 'Secret found in log file.');
            self::assertStringNotContainsString($secretValue, $databaseDump, 'Secret found in database.');
        }
    }

    /** Concatenates every value of every table into one string. */
    private function dumpAllTables(): string
    {
        $dumpParts = [];
        foreach (TestDatabase::tableNames() as $tableName) {
            foreach ($this->database->query('SELECT * FROM `' . $tableName . '`')->fetchAll(PDO::FETCH_NUM) as $tableRow) {
                $dumpParts[] = implode('|', array_map('strval', $tableRow));
            }
        }

        return implode("\n", $dumpParts);
    }
}
