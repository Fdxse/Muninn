<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * bin/seed-demo.php (D052): the demo data it creates follows the same isolation rules as real
 * data, and a second run never touches what is already there.
 */
final class DemoDataTest extends WorkspaceTestCase
{
    /** Temporary config file the script is pointed at (the test database). */
    private string $configFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configFilePath = sys_get_temp_dir() . '/muninn-demo-config-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($this->configFilePath, '<?php return ' . var_export($this->configValues(), true) . ';');
    }

    protected function tearDown(): void
    {
        if (is_file($this->configFilePath)) {
            unlink($this->configFilePath);
        }
        parent::tearDown();
    }

    public function testDemoAccountsShareOneWorkspaceButNotTheirPersonalNotes(): void
    {
        [$exitCode, $scriptOutput] = $this->runSeedScript();
        self::assertSame(0, $exitCode, $scriptOutput);

        // The passwords are printed once; sign in with them like a real demo would.
        preg_match_all('/^\s+(demo\.\w+)\s+password: (\S+)$/m', $scriptOutput, $passwordMatches, PREG_SET_ORDER);
        self::assertCount(2, $passwordMatches, $scriptOutput);
        $passwordsByUsername = array_column($passwordMatches, 2, 1);
        $anna = $this->login('demo.anna', $passwordsByUsername['demo.anna']);
        $erik = $this->login('demo.erik', $passwordsByUsername['demo.erik']);

        // Anna's private note is hers alone, also in search.
        self::assertCount(1, $this->assertOkData($this->getAs($anna, '/api/v1/search', ['q' => 'gift ideas']))['results']);
        self::assertSame([], $this->assertOkData($this->getAs($erik, '/api/v1/search', ['q' => 'gift ideas']))['results']);

        // Both see the shared workspace, and its meeting note has history from both of them.
        $meetingResults = $this->assertOkData($this->getAs($erik, '/api/v1/search', ['q' => 'Planning meeting']))['results'];
        self::assertCount(1, $meetingResults);
        self::assertSame('Demo: Team handbook', $meetingResults[0]['workspace_name']);
        $versions = $this->assertOkData($this->getAs($anna, '/api/v1/notes/' . $meetingResults[0]['id'] . '/versions'))['versions'];
        self::assertCount(2, $versions);

        // The archived note only shows up when archived notes are included.
        self::assertSame([], $this->assertOkData($this->getAs($anna, '/api/v1/search', ['q' => 'canoe']))['results']);
        self::assertCount(1, $this->assertOkData($this->getAs($anna, '/api/v1/search', ['q' => 'canoe', 'archived' => '1']))['results']);
    }

    public function testSecondRunChangesNothing(): void
    {
        self::assertSame(0, $this->runSeedScript()[0]);
        $userCountAfterFirstRun = (int) $this->scalar('SELECT COUNT(*) FROM users');
        $noteCountAfterFirstRun = (int) $this->scalar('SELECT COUNT(*) FROM notes');

        [$secondExitCode, $secondOutput] = $this->runSeedScript();

        self::assertSame(1, $secondExitCode);
        self::assertStringContainsString('Nothing was changed', $secondOutput);
        self::assertSame($userCountAfterFirstRun, (int) $this->scalar('SELECT COUNT(*) FROM users'));
        self::assertSame($noteCountAfterFirstRun, (int) $this->scalar('SELECT COUNT(*) FROM notes'));
    }

    /**
     * Runs bin/seed-demo.php against the test database.
     *
     * @return array{0: int, 1: string} Exit code and combined output.
     */
    private function runSeedScript(): array
    {
        $commandLine = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/seed-demo.php')
            . ' ' . escapeshellarg($this->configFilePath) . ' 2>&1';
        exec($commandLine, $outputLines, $exitCode);

        return [$exitCode, implode("\n", $outputLines)];
    }
}
