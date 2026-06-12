<?php
declare(strict_types=1);

namespace Tests\Integration;

use Chat\Chat\RoomController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDb;

/**
 * Матрица прав модерации комнаты через RoomController::manage().
 * Переносит и расширяет сценарии RBAC_VALIDATION_AUDIT.md (Claims 1–4, BUG-1..BUG-4)
 * и политику мьюта (decision 2026-06-07): причина+срок обязательны, запрет повторного
 * кляпа, room-level unmute.
 *
 * Состав комнаты в каждом тесте:
 *   roomOwner(owner), localAdmin, localAdmin2, localModerator, localModerator2,
 *   memberA, memberB, poMember(platform_owner как member), bannedUser(banned)
 * Вне комнаты: outsider. Глобальные: platformOwner, admin, moderator.
 */
final class RoomModerationRbacTest extends TestCase
{
    private int $roomId;

    /** @var array<string, array> */
    private array $cast = [];

    protected function setUp(): void
    {
        TestDb::reset();

        $this->cast['platformOwner']   = TestDb::user('platform_owner');
        $this->cast['admin']           = TestDb::user('admin');
        $this->cast['moderator']       = TestDb::user('moderator');
        $this->cast['roomOwner']       = TestDb::user();
        $this->cast['localAdmin']      = TestDb::user();
        $this->cast['localAdmin2']     = TestDb::user();
        $this->cast['localModerator']  = TestDb::user();
        $this->cast['localModerator2'] = TestDb::user();
        $this->cast['memberA']         = TestDb::user();
        $this->cast['memberB']         = TestDb::user();
        $this->cast['poMember']        = TestDb::user('platform_owner');
        $this->cast['bannedUser']      = TestDb::user();
        $this->cast['outsider']        = TestDb::user();

        $this->roomId = TestDb::room((int) $this->cast['roomOwner']['id']);
        foreach ([
            'localAdmin' => 'local_admin', 'localAdmin2' => 'local_admin',
            'localModerator' => 'local_moderator', 'localModerator2' => 'local_moderator',
            'memberA' => 'member', 'memberB' => 'member',
            'poMember' => 'member', 'bannedUser' => 'banned',
        ] as $key => $role) {
            TestDb::addMember($this->roomId, (int) $this->cast[$key]['id'], $role);
        }
    }

    private function manage(string $actorKey, array $data): array
    {
        $actor = $this->cast[$actorKey];
        return RoomController::manage($this->roomId, (int) $actor['id'], $actor, $data);
    }

    private function act(string $actorKey, string $action, string $targetKey, array $extra = []): array
    {
        return $this->manage($actorKey, array_merge(
            ['action' => $action, 'target_user_id' => (int) $this->cast[$targetKey]['id']],
            $extra
        ));
    }

    private function roomRole(string $userKey): ?string
    {
        $row = TestDb::fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$this->roomId, (int) $this->cast[$userKey]['id']]
        );
        return $row['room_role'] ?? null;
    }

    // ── Входной контроль manage() ───────────────────────────────────────────

    public function testOutsiderHasNoAccessAtAll(): void
    {
        $result = $this->act('outsider', 'kick', 'memberA');
        $this->assertSame('Нет прав.', $result['error'] ?? null);
    }

    public function testUnknownActionIsRejected(): void
    {
        $result = $this->manage('roomOwner', ['action' => 'self_destruct']);
        $this->assertSame('Неизвестное действие.', $result['error'] ?? null);
    }

    /** I-1: запрет действий над самим собой — для всех модерационных действий. */
    #[DataProvider('selfActions')]
    public function testSelfActionIsBlocked(string $action, array $extra): void
    {
        $result = $this->act('localAdmin', $action, 'localAdmin', $extra);
        $this->assertSame('Нельзя применить это действие к самому себе.', $result['error'] ?? null);
    }

    public static function selfActions(): array
    {
        return [
            'kick'     => ['kick', []],
            'ban'      => ['ban', []],
            'mute'     => ['mute', ['minutes' => 30, 'reason' => 'x']],
            'unmute'   => ['unmute', []],
            'set_role' => ['set_role', ['role' => 'member']],
        ];
    }

    // ── kick ────────────────────────────────────────────────────────────────

    #[DataProvider('kickMatrix')]
    public function testKickPermissionMatrix(string $actor, string $target, bool $allowed): void
    {
        $result = $this->act($actor, 'kick', $target);
        if ($allowed) {
            $this->assertTrue($result['kicked'] ?? false, 'Ожидался kick: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
            $this->assertNull($this->roomRole($target), 'Цель должна быть удалена из комнаты');
        } else {
            $this->assertArrayHasKey('error', $result);
            $this->assertNotNull($this->roomRole($target), 'Цель должна остаться в комнате');
        }
    }

    public static function kickMatrix(): array
    {
        return [
            'owner → member: да'            => ['roomOwner', 'memberA', true],
            'local_admin → member: да'      => ['localAdmin', 'memberA', true],
            'local_moderator → member: нет' => ['localModerator', 'memberA', false],
            'member → member: нет'          => ['memberA', 'memberB', false],
            'global admin → member: да'     => ['admin', 'memberA', true],
            'global moderator → member: да' => ['moderator', 'memberA', true],
            'platform_owner → member: да'   => ['platformOwner', 'memberA', true],
            'admin → владелец комнаты: нет' => ['admin', 'roomOwner', false],
            'I-3 admin → platform_owner-участник: нет' => ['admin', 'poMember', false],
            'owner → local_admin: да'       => ['roomOwner', 'localAdmin', true],
        ];
    }

    public function testKickTargetOutsideRoomFails(): void
    {
        $result = $this->act('roomOwner', 'kick', 'outsider');
        $this->assertArrayHasKey('error', $result);
    }

    // ── ban ─────────────────────────────────────────────────────────────────

    #[DataProvider('banMatrix')]
    public function testBanPermissionMatrix(string $actor, string $target, bool $allowed): void
    {
        $result = $this->act($actor, 'ban', $target);
        if ($allowed) {
            $this->assertTrue($result['banned'] ?? false, 'Ожидался ban: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
            $this->assertSame('banned', $this->roomRole($target));
        } else {
            $this->assertArrayHasKey('error', $result);
            $this->assertNotSame('banned', $this->roomRole($target));
        }
    }

    public static function banMatrix(): array
    {
        return [
            'owner → member: да'            => ['roomOwner', 'memberA', true],
            'local_admin → member: да'      => ['localAdmin', 'memberA', true],
            'local_moderator → member: нет' => ['localModerator', 'memberA', false],
            'member → member: нет'          => ['memberA', 'memberB', false],
            'global admin → member: да'     => ['admin', 'memberA', true],
            'global moderator → member: да' => ['moderator', 'memberA', true],
            'admin → владелец комнаты: нет' => ['admin', 'roomOwner', false],
            'I-3 admin → platform_owner-участник: нет' => ['admin', 'poMember', false],
        ];
    }

    public function testBanRecordsActorAndTimestamp(): void
    {
        $this->act('localAdmin', 'ban', 'memberA');
        $row = TestDb::fetchOne(
            'SELECT banned_by, banned_at FROM room_members WHERE room_id = ? AND user_id = ?',
            [$this->roomId, (int) $this->cast['memberA']['id']]
        );
        $this->assertSame((int) $this->cast['localAdmin']['id'], (int) $row['banned_by']);
        $this->assertNotNull($row['banned_at']);
    }

    // ── mute (политика владельца 2026-06-07) ────────────────────────────────

    #[DataProvider('mutePermissionMatrix')]
    public function testMutePermissionMatrix(string $actor, bool $allowed): void
    {
        $result = $this->act($actor, 'mute', 'memberA', ['minutes' => 30, 'reason' => 'флуд']);
        if ($allowed) {
            $this->assertTrue($result['muted'] ?? false, json_encode($result, JSON_UNESCAPED_UNICODE));
        } else {
            $this->assertArrayHasKey('error', $result);
        }
    }

    public static function mutePermissionMatrix(): array
    {
        return [
            'owner: да'            => ['roomOwner', true],
            'local_admin: да'      => ['localAdmin', true],
            'local_moderator: нет' => ['localModerator', false],
            'member: нет'          => ['memberA', false],
            'global admin: да'     => ['admin', true],
            'global moderator: да' => ['moderator', true],
        ];
    }

    public function testMuteRequiresDuration(): void
    {
        $result = $this->act('roomOwner', 'mute', 'memberA', ['reason' => 'флуд']);
        $this->assertSame('Выберите срок кляпа.', $result['error'] ?? null);
    }

    public function testMuteRequiresReason(): void
    {
        $result = $this->act('roomOwner', 'mute', 'memberA', ['minutes' => 30, 'reason' => '   ']);
        $this->assertSame('Укажите причину кляпа.', $result['error'] ?? null);
    }

    public function testRemuteWhileActiveIsRejected(): void
    {
        $first = $this->act('roomOwner', 'mute', 'memberA', ['minutes' => 30, 'reason' => 'флуд']);
        $this->assertTrue($first['muted'] ?? false);

        $second = $this->act('roomOwner', 'mute', 'memberA', ['minutes' => 60, 'reason' => 'ещё раз']);
        $this->assertStringStartsWith('Пользователь уже в кляпе', $second['error'] ?? '');
    }

    public function testMuteDurationIsCappedAt24h(): void
    {
        $this->act('roomOwner', 'mute', 'memberA', ['minutes' => 99999, 'reason' => 'потолок']);
        $row = TestDb::fetchOne(
            'SELECT TIMESTAMPDIFF(MINUTE, NOW(), muted_until) AS m FROM room_members WHERE room_id = ? AND user_id = ?',
            [$this->roomId, (int) $this->cast['memberA']['id']]
        );
        $this->assertLessThanOrEqual(1440, (int) $row['m']);
        $this->assertGreaterThan(1380, (int) $row['m']);
    }

    public function testMuteRoomOwnerIsRejected(): void
    {
        $result = $this->act('admin', 'mute', 'roomOwner', ['minutes' => 30, 'reason' => 'x']);
        $this->assertSame('Нельзя выдать кляп этому пользователю.', $result['error'] ?? null);
    }

    public function testMuteBannedUserIsRejected(): void
    {
        $result = $this->act('roomOwner', 'mute', 'bannedUser', ['minutes' => 30, 'reason' => 'x']);
        $this->assertSame('Нельзя выдать кляп этому пользователю.', $result['error'] ?? null);
    }

    // ── unmute ──────────────────────────────────────────────────────────────

    #[DataProvider('unmutePermissionMatrix')]
    public function testUnmutePermissionMatrix(string $actor, bool $allowed): void
    {
        TestDb::muteMember($this->roomId, (int) $this->cast['memberA']['id'], 60);

        $result = $this->act($actor, 'unmute', 'memberA');
        $row = TestDb::fetchOne(
            'SELECT muted_until FROM room_members WHERE room_id = ? AND user_id = ?',
            [$this->roomId, (int) $this->cast['memberA']['id']]
        );

        if ($allowed) {
            $this->assertTrue($result['unmuted'] ?? false, json_encode($result, JSON_UNESCAPED_UNICODE));
            $this->assertNull($row['muted_until']);
        } else {
            $this->assertArrayHasKey('error', $result);
            $this->assertNotNull($row['muted_until']);
        }
    }

    public static function unmutePermissionMatrix(): array
    {
        return [
            'owner: да'            => ['roomOwner', true],
            'local_admin: да'      => ['localAdmin', true],
            'local_moderator: нет' => ['localModerator', false],
            'member: нет'          => ['memberA', false],
            'global admin: да'     => ['admin', true],
            'global moderator: да' => ['moderator', true],
        ];
    }

    public function testUnmuteTargetOutsideRoomFails(): void
    {
        $result = $this->act('roomOwner', 'unmute', 'outsider');
        $this->assertSame('Пользователь не состоит в комнате.', $result['error'] ?? null);
    }

    // ── set_role (BUG-4: явная политика назначения/снятия) ──────────────────

    #[DataProvider('assignRoleMatrix')]
    public function testAssignRoleMatrix(string $actor, string $role, bool $allowed): void
    {
        $result = $this->act($actor, 'set_role', 'memberA', ['role' => $role]);
        if ($allowed) {
            $this->assertTrue($result['updated'] ?? false, json_encode($result, JSON_UNESCAPED_UNICODE));
            $this->assertSame($role, $this->roomRole('memberA'));
        } else {
            $this->assertArrayHasKey('error', $result);
            $this->assertSame('member', $this->roomRole('memberA'));
        }
    }

    public static function assignRoleMatrix(): array
    {
        return [
            // local_admin: только owner комнаты, глобальный admin, platform_owner
            'owner назначает local_admin: да'             => ['roomOwner', 'local_admin', true],
            'global admin назначает local_admin: да'      => ['admin', 'local_admin', true],
            'platform_owner назначает local_admin: да'    => ['platformOwner', 'local_admin', true],
            'local_admin назначает local_admin: нет'      => ['localAdmin', 'local_admin', false],
            'local_moderator назначает local_admin: нет'  => ['localModerator', 'local_admin', false],
            'member назначает local_admin: нет'           => ['memberA', 'local_admin', false],
            // local_moderator: owner, local_admin, глобальный admin, platform_owner; НЕ local_moderator
            'owner назначает local_moderator: да'         => ['roomOwner', 'local_moderator', true],
            'local_admin назначает local_moderator: да'   => ['localAdmin', 'local_moderator', true],
            'global admin назначает local_moderator: да'  => ['admin', 'local_moderator', true],
            'local_moderator назначает local_moderator: нет' => ['localModerator', 'local_moderator', false],
            'member назначает local_moderator: нет (Claim 4)' => ['memberB', 'local_moderator', false],
        ];
    }

    #[DataProvider('demoteMatrix')]
    public function testDemoteToMemberMatrix(string $actor, string $target, bool $allowed): void
    {
        $result = $this->act($actor, 'set_role', $target, ['role' => 'member']);
        if ($allowed) {
            $this->assertTrue($result['updated'] ?? false, json_encode($result, JSON_UNESCAPED_UNICODE));
            $this->assertSame('member', $this->roomRole($target));
        } else {
            $this->assertArrayHasKey('error', $result);
            $this->assertNotSame('member', $this->roomRole($target));
        }
    }

    public static function demoteMatrix(): array
    {
        return [
            'owner снимает local_admin: да'                 => ['roomOwner', 'localAdmin', true],
            'global admin снимает local_admin: да'          => ['admin', 'localAdmin', true],
            'local_admin снимает local_admin: нет'          => ['localAdmin', 'localAdmin2', false],
            'owner снимает local_moderator: да'             => ['roomOwner', 'localModerator', true],
            'local_admin снимает local_moderator: да'       => ['localAdmin', 'localModerator', true],
            'local_moderator снимает local_moderator: нет'  => ['localModerator', 'localModerator2', false],
        ];
    }

    public function testInvalidRoleIsRejected(): void
    {
        $result = $this->act('roomOwner', 'set_role', 'memberA', ['role' => 'owner']);
        $this->assertSame('Недопустимая роль.', $result['error'] ?? null);
    }

    public function testCannotChangeRoomOwnerRole(): void
    {
        $result = $this->act('platformOwner', 'set_role', 'roomOwner', ['role' => 'member']);
        $this->assertSame('Нельзя изменить роль владельца.', $result['error'] ?? null);
    }

    public function testSameRoleIsNoChange(): void
    {
        $result = $this->act('roomOwner', 'set_role', 'memberA', ['role' => 'member']);
        $this->assertTrue($result['no_change'] ?? false);
        $this->assertFalse($result['updated']);
    }

    public function testSetRoleTargetOutsideRoomFails(): void
    {
        $result = $this->act('roomOwner', 'set_role', 'outsider', ['role' => 'local_moderator']);
        $this->assertSame('Пользователь не состоит в комнате.', $result['error'] ?? null);
    }

    // ── rename (уровень ≥ 3) ────────────────────────────────────────────────

    #[DataProvider('renameMatrix')]
    public function testRenameMatrix(string $actor, bool $allowed): void
    {
        $result = $this->manage($actor, ['action' => 'rename', 'name' => 'Новое имя']);
        if ($allowed) {
            $this->assertTrue($result['updated'] ?? false, json_encode($result, JSON_UNESCAPED_UNICODE));
        } else {
            $this->assertArrayHasKey('error', $result);
        }
    }

    public static function renameMatrix(): array
    {
        return [
            'owner: да'            => ['roomOwner', true],
            'local_admin: нет'     => ['localAdmin', false],
            'global moderator: да' => ['moderator', true],
            'global admin: да'     => ['admin', true],
            'member: нет'          => ['memberA', false],
        ];
    }
}
