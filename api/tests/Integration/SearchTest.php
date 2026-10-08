<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Search\SearchService;
use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * Global search (D011, D038). "User A cannot discover User B's note through search" is a
 * CLAUDE.md minimum test; search must also skip Trash and, unless asked, the Archive.
 */
final class SearchTest extends WorkspaceTestCase
{
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $alice;
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $bob;
    private string $alicePersonalWorkspaceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = $this->signedInUser('alice');
        $this->bob = $this->signedInUser('bob');
        $this->alicePersonalWorkspaceId = $this->personalWorkspaceId($this->alice);
    }

    public function testFindsWordPartsInTitlesTextAndTagsIgnoringCase(): void
    {
        $this->createNote($this->alice, $this->alicePersonalWorkspaceId, 'Mötesanteckningar', 'Vi pratade om budget.');
        $this->createNote($this->alice, $this->alicePersonalWorkspaceId, 'Inköp', 'Köp mjölk inför MÖTET på fredag.');
        $taggedNote = $this->createNote($this->alice, $this->alicePersonalWorkspaceId, 'Untagged words', 'nothing');
        $this->assertOkData($this->updateNote($this->alice, $taggedNote['id'], 1, ['tags' => ['möten']]));
        $this->createNote($this->alice, $this->alicePersonalWorkspaceId, 'Unrelated', 'nothing here');

        $searchData = $this->search($this->alice, 'möte');
        self::assertSame(['möte'], $searchData['terms']);
        self::assertFalse($searchData['limited']);
        self::assertSame('Mötesanteckningar', $searchData['results'][0]['title'], 'Title hits come first.');
        self::assertEqualsCanonicalizing(['Mötesanteckningar', 'Inköp', 'Untagged words'], array_column($searchData['results'], 'title'));

        $textHit = $this->resultTitled($searchData['results'], 'Inköp');
        self::assertStringContainsString('MÖTET', $textHit['snippet']);
        self::assertSame($this->alicePersonalWorkspaceId, $textHit['workspace_id']);
        self::assertSame('personal', $textHit['workspace_kind']);
        self::assertSame(['möten'], $this->resultTitled($searchData['results'], 'Untagged words')['tags']);
    }

    public function testEveryWordMustMatch(): void
    {
        $this->createNote($this->alice, $this->alicePersonalWorkspaceId, 'Garden', 'tomatoes and basil');
        $this->createNote($this->alice, $this->alicePersonalWorkspaceId, 'Kitchen', 'tomatoes only');

        self::assertSame(['Garden'], array_column($this->search($this->alice, '  basil   TOMATO ')['results'], 'title'));
    }

    public function testWildcardCharactersAreMatchedLiterally(): void
    {
        $this->createNote($this->alice, $this->alicePersonalWorkspaceId, 'Discount', 'save 100% now');
        $this->createNote($this->alice, $this->alicePersonalWorkspaceId, 'Variable', 'snake_case and kebab!case');
        $this->createNote($this->alice, $this->alicePersonalWorkspaceId, 'Other', 'save 100 dollars, snakeXcase');

        self::assertSame(['Discount'], array_column($this->search($this->alice, '100%')['results'], 'title'));
        self::assertSame(['Variable'], array_column($this->search($this->alice, 'snake_case')['results'], 'title'));
        self::assertSame(['Variable'], array_column($this->search($this->alice, 'kebab!case')['results'], 'title'));
        // A lone % finds only notes that contain a percent sign, not every note.
        self::assertSame(['Discount'], array_column($this->search($this->alice, '%')['results'], 'title'));
    }

    public function testSearchNeverShowsOtherPeoplesNotes(): void
    {
        $this->createNote($this->bob, $this->personalWorkspaceId($this->bob), 'Bob secret', 'the launch code is swordfish');
        $bobsSharedWorkspaceId = $this->createSharedWorkspace($this->bob, 'Bob team');
        $this->createNote($this->bob, $bobsSharedWorkspaceId, 'Team swordfish', 'shared with nobody yet');
        $this->createNote($this->alice, $this->alicePersonalWorkspaceId, 'Alice swordfish', 'mine');

        self::assertSame(['Alice swordfish'], array_column($this->search($this->alice, 'swordfish')['results'], 'title'));

        // Becoming a Reader of Bob's team makes exactly that workspace searchable.
        self::assertSame(201, $this->addMember($this->bob, $bobsSharedWorkspaceId, 'alice', 'reader')->statusCode());
        $sharedResults = $this->search($this->alice, 'swordfish')['results'];
        self::assertEqualsCanonicalizing(['Alice swordfish', 'Team swordfish'], array_column($sharedResults, 'title'));
        self::assertSame('Bob team', $this->resultTitled($sharedResults, 'Team swordfish')['workspace_name']);

        // ...and leaving it makes it unsearchable again.
        self::assertSame(204, $this->sendAs($this->bob, 'DELETE', '/api/v1/workspaces/' . $bobsSharedWorkspaceId . '/members/' . $this->alice['user_id'])->statusCode());
        self::assertSame(['Alice swordfish'], array_column($this->search($this->alice, 'swordfish')['results'], 'title'));
    }

    public function testAdministratorAccountsFindNothing(): void
    {
        $this->createNote($this->alice, $this->alicePersonalWorkspaceId, 'Payroll', 'salaries');
        $administrator = $this->signedInUser('sysadmin', true);

        self::assertSame([], $this->search($administrator, 'payroll')['results']);
    }

    public function testTrashIsNeverSearchedAndArchiveOnlyOnRequest(): void
    {
        $trashedNote = $this->createNote($this->alice, $this->alicePersonalWorkspaceId, 'Trashed zebra');
        $archivedNote = $this->createNote($this->alice, $this->alicePersonalWorkspaceId, 'Archived zebra');
        $this->createNote($this->alice, $this->alicePersonalWorkspaceId, 'Active zebra');
        self::assertSame(204, $this->sendAs($this->alice, 'DELETE', '/api/v1/notes/' . $trashedNote['id'])->statusCode());
        $this->assertOkData($this->sendAs($this->alice, 'POST', '/api/v1/notes/' . $archivedNote['id'] . '/archive'));

        self::assertSame(['Active zebra'], array_column($this->search($this->alice, 'zebra')['results'], 'title'));

        $withArchive = $this->search($this->alice, 'zebra', ['archived' => '1'])['results'];
        self::assertEqualsCanonicalizing(['Active zebra', 'Archived zebra'], array_column($withArchive, 'title'));
        self::assertNotNull($this->resultTitled($withArchive, 'Archived zebra')['archived_at']);
    }

    public function testResultsAreLimitedAndSaySo(): void
    {
        for ($noteNumber = 1; $noteNumber <= SearchService::RESULT_LIMIT + 1; $noteNumber++) {
            $this->database->prepare(
                'INSERT INTO notes (id, workspace_id, title, content, created_by_user_id, updated_by_user_id, created_at, updated_at)
                 VALUES (UUID(), :workspace_id, :title, \'\', :user_id, :user_id_again, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            )->execute([
                'workspace_id' => $this->alicePersonalWorkspaceId,
                'title' => 'Many ' . $noteNumber,
                'user_id' => $this->alice['user_id'],
                'user_id_again' => $this->alice['user_id'],
            ]);
        }

        $searchData = $this->search($this->alice, 'many');
        self::assertCount(SearchService::RESULT_LIMIT, $searchData['results']);
        self::assertTrue($searchData['limited']);
    }

    public function testInvalidQueriesAreRejected(): void
    {
        $this->assertError($this->getAs($this->alice, '/api/v1/search', []), 422, 'validation_failed');
        $this->assertError($this->getAs($this->alice, '/api/v1/search', ['q' => "   \t "]), 422, 'validation_failed');
        $this->assertError($this->getAs($this->alice, '/api/v1/search', ['q' => str_repeat('x', 201)]), 422, 'validation_failed');
        $this->assertError($this->getAs($this->alice, '/api/v1/search', ['q' => "nul\0byte"]), 422, 'validation_failed');
        $this->assertError($this->send('GET', '/api/v1/search'), 401, 'unauthenticated');
    }

    /**
     * Runs a search as $credentials and returns the response data.
     *
     * @param array<string, string> $extraParameters
     * @return array<string, mixed>
     */
    private function search(array $credentials, string $queryText, array $extraParameters = []): array
    {
        return $this->assertOkData($this->getAs($credentials, '/api/v1/search', ['q' => $queryText] + $extraParameters));
    }

    /**
     * @param list<array<string, mixed>> $results
     * @return array<string, mixed>
     */
    private function resultTitled(array $results, string $title): array
    {
        foreach ($results as $result) {
            if ($result['title'] === $title) {
                return $result;
            }
        }
        self::fail('No result titled "' . $title . '".');
    }
}
