<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Unit;

use Muninn\Api\Workspaces\WorkspacePermission;
use Muninn\Api\Workspaces\WorkspaceRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the role permission matrix of decisions D029 and D039, so a change to it is always deliberate.
 */
final class WorkspaceRoleTest extends TestCase
{
    /** @return iterable<string, array{WorkspaceRole, WorkspacePermission, bool}> */
    public static function permissionMatrix(): iterable
    {
        $expectedMatrix = [
            'reader' => ['ReadNotes' => true, 'WriteNotes' => false, 'PurgeNotes' => false, 'ManageMembers' => false, 'ManageWorkspace' => false],
            'editor' => ['ReadNotes' => true, 'WriteNotes' => true, 'PurgeNotes' => false, 'ManageMembers' => false, 'ManageWorkspace' => false],
            'admin' => ['ReadNotes' => true, 'WriteNotes' => true, 'PurgeNotes' => true, 'ManageMembers' => true, 'ManageWorkspace' => false],
            'owner' => ['ReadNotes' => true, 'WriteNotes' => true, 'PurgeNotes' => true, 'ManageMembers' => true, 'ManageWorkspace' => true],
        ];

        foreach ($expectedMatrix as $roleValue => $permissionExpectations) {
            foreach ($permissionExpectations as $permissionName => $isAllowed) {
                yield $roleValue . ' ' . $permissionName => [
                    WorkspaceRole::from($roleValue),
                    constant(WorkspacePermission::class . '::' . $permissionName),
                    $isAllowed,
                ];
            }
        }
    }

    #[DataProvider('permissionMatrix')]
    public function testRolePermissionMatrix(WorkspaceRole $role, WorkspacePermission $permission, bool $isAllowed): void
    {
        self::assertSame($isAllowed, $role->allows($permission));
    }

    public function testOwnersMayAssignAndManageEveryRole(): void
    {
        foreach (WorkspaceRole::cases() as $targetRole) {
            self::assertTrue(WorkspaceRole::Owner->canAssign($targetRole));
            self::assertTrue(WorkspaceRole::Owner->canManageMember($targetRole));
        }
    }

    public function testAdminsMayOnlyAssignAndManageEditorsAndReaders(): void
    {
        self::assertTrue(WorkspaceRole::Admin->canAssign(WorkspaceRole::Editor));
        self::assertTrue(WorkspaceRole::Admin->canAssign(WorkspaceRole::Reader));
        self::assertFalse(WorkspaceRole::Admin->canAssign(WorkspaceRole::Admin));
        self::assertFalse(WorkspaceRole::Admin->canAssign(WorkspaceRole::Owner));
        self::assertFalse(WorkspaceRole::Admin->canManageMember(WorkspaceRole::Owner));
        self::assertFalse(WorkspaceRole::Admin->canManageMember(WorkspaceRole::Admin));
    }

    public function testEditorsAndReadersMayNotAssignAnything(): void
    {
        foreach ([WorkspaceRole::Editor, WorkspaceRole::Reader] as $weakRole) {
            foreach (WorkspaceRole::cases() as $targetRole) {
                self::assertFalse($weakRole->canAssign($targetRole));
            }
        }
    }

    public function testUnknownRoleInputIsRejected(): void
    {
        self::assertNull(WorkspaceRole::tryFromInput('superuser'));
        self::assertNull(WorkspaceRole::tryFromInput(4));
        self::assertNull(WorkspaceRole::tryFromInput('OWNER'));
        self::assertSame(WorkspaceRole::Reader, WorkspaceRole::tryFromInput('reader'));
    }
}
