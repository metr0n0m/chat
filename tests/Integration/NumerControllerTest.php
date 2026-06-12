<?php
declare(strict_types=1);

namespace Tests\Integration;

use Chat\Chat\NumerController;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDb;

/**
 * Жизненный цикл нумера: приглашение, принятие, выход с передачей владельца.
 * Закрывает регрессии: BUG-5 (кляп в чужой комнате не должен блокировать инвайт)
 * и RISK-4 (rooms.owner_id и room_role='owner' не должны расходиться).
 */
final class NumerControllerTest extends TestCase
{
    private array $alice;
    private array $bob;

    protected function setUp(): void
    {
        TestDb::reset();
        $this->alice = TestDb::user();
        $this->bob   = TestDb::user();
    }

    private function aliceId(): int
    {
        return (int) $this->alice['id'];
    }

    private function bobId(): int
    {
        return (int) $this->bob['id'];
    }

    /** BUG-5: кляп в публичной комнате не блокирует приглашение в нумер. */
    public function testMuteInAnotherRoomDoesNotBlockInvite(): void
    {
        $owner  = TestDb::user();
        $roomId = TestDb::room((int) $owner['id']);
        TestDb::addMember($roomId, $this->aliceId());
        TestDb::muteMember($roomId, $this->aliceId(), 60);

        $result = NumerController::invite($this->aliceId(), $this->alice, $this->bobId());

        $this->assertArrayNotHasKey('error', $result, json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertArrayHasKey('invitation_id', $result);
    }

    public function testInviteRespectsPendingLimit(): void
    {
        for ($i = 0; $i < INVITE_PENDING_MAX; $i++) {
            $target = TestDb::user();
            $result = NumerController::invite($this->aliceId(), $this->alice, (int) $target['id']);
            $this->assertArrayHasKey('invitation_id', $result);
        }

        $oneMore = NumerController::invite($this->aliceId(), $this->alice, $this->bobId());
        $this->assertArrayHasKey('error', $oneMore);
    }

    public function testInviteToBannedUserFails(): void
    {
        TestDb::fetchOne('SELECT 1'); // прогрев соединения
        \Chat\DB\Connection::getInstance()->execute(
            'UPDATE users SET is_banned = 1 WHERE id = ?',
            [$this->bobId()]
        );

        $result = NumerController::invite($this->aliceId(), $this->alice, $this->bobId());
        $this->assertArrayHasKey('error', $result);
    }

    public function testAcceptCreatesNumerWithConsistentOwnership(): void
    {
        $invite = NumerController::invite($this->aliceId(), $this->alice, $this->bobId());
        $result = NumerController::respond((int) $invite['invitation_id'], $this->bobId(), 'accept');

        $this->assertTrue($result['accepted'] ?? false, json_encode($result, JSON_UNESCAPED_UNICODE));
        $roomId = (int) $result['room_id'];

        $room = TestDb::fetchOne('SELECT type, owner_id, is_closed FROM rooms WHERE id = ?', [$roomId]);
        $this->assertSame('numer', $room['type']);
        $this->assertSame($this->aliceId(), (int) $room['owner_id']);

        $ownerRow = TestDb::fetchOne(
            "SELECT user_id FROM room_members WHERE room_id = ? AND room_role = 'owner'",
            [$roomId]
        );
        $this->assertSame($this->aliceId(), (int) $ownerRow['user_id'], 'owner_id и room_role=owner должны совпадать');
        $this->assertCount(2, $result['members']);
    }

    public function testDeclineDoesNotCreateRoom(): void
    {
        $invite = NumerController::invite($this->aliceId(), $this->alice, $this->bobId());
        $result = NumerController::respond((int) $invite['invitation_id'], $this->bobId(), 'decline');

        $this->assertTrue($result['declined'] ?? false);
        $row = TestDb::fetchOne('SELECT room_id, status FROM invitations WHERE id = ?', [(int) $invite['invitation_id']]);
        $this->assertSame('declined', $row['status']);
        $this->assertNull($row['room_id']);
    }

    /** RISK-4: после выхода владельца обе записи о владении должны указывать на нового владельца. */
    public function testOwnerLeaveTransfersOwnershipAtomically(): void
    {
        $roomId = TestDb::room($this->aliceId(), 'numer');
        TestDb::addMember($roomId, $this->bobId(), 'member', 10);

        $result = NumerController::leave($roomId, $this->aliceId());

        $this->assertTrue($result['left'] ?? false, json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($result['owner_transferred']);
        $this->assertSame($this->bobId(), (int) $result['new_owner_id']);

        $room = TestDb::fetchOne('SELECT owner_id FROM rooms WHERE id = ?', [$roomId]);
        $this->assertSame($this->bobId(), (int) $room['owner_id']);

        $ownerRow = TestDb::fetchOne(
            "SELECT user_id FROM room_members WHERE room_id = ? AND room_role = 'owner'",
            [$roomId]
        );
        $this->assertSame($this->bobId(), (int) $ownerRow['user_id'], 'rooms.owner_id и room_role=owner разошлись');
    }

    public function testLastMemberLeaveClosesRoom(): void
    {
        $roomId = TestDb::room($this->aliceId(), 'numer');

        $result = NumerController::leave($roomId, $this->aliceId());

        $this->assertTrue($result['destroyed'] ?? false, json_encode($result, JSON_UNESCAPED_UNICODE));
        $room = TestDb::fetchOne('SELECT is_closed, close_reason FROM rooms WHERE id = ?', [$roomId]);
        $this->assertSame(1, (int) $room['is_closed']);
        $this->assertSame('last_left', $room['close_reason']);
    }
}
