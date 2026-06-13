<?php
declare(strict_types=1);

namespace Tests\Integration;

use Chat\DB\Connection;
use Chat\Moderation\StopWordDetector;
use Chat\Moderation\StopWordRules;
use Chat\Moderation\ViolationReporter;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDb;

/**
 * S5b: настройки движка (sanction_rules) и стоп-слова.
 */
final class SanctionConfigTest extends TestCase
{
    private array $owner;
    private int $roomId;

    protected function setUp(): void
    {
        TestDb::reset();
        ViolationReporter::flushRules();
        StopWordDetector::flushCache();
        $this->owner  = TestDb::user();
        $this->roomId = TestDb::room((int) $this->owner['id']);
    }

    // ── конфигурация движка ──────────────────────────────────────────────────

    public function testSetRuleRoundTrip(): void
    {
        ViolationReporter::setRule('mode', 'live');
        ViolationReporter::setRule('circuit_breaker', ['max_system_sanctions_per_min' => 7]);
        ViolationReporter::flushRules();

        $rules = ViolationReporter::rules();
        $this->assertSame('live', $rules['mode']);
        $this->assertSame(7, $rules['circuit_breaker']['max_system_sanctions_per_min']);
    }

    public function testAutonomyReflectsConfig(): void
    {
        ViolationReporter::setRule('mode', 'live');
        ViolationReporter::setRule('autonomy_state', 'paused');
        ViolationReporter::flushRules();

        $autonomy = \Chat\Moderation\SanctionStats::autonomy();
        $this->assertSame('live', $autonomy['mode']);
        $this->assertSame('paused', $autonomy['autonomy_state']);
    }

    // ── стоп-слова ───────────────────────────────────────────────────────────

    public function testAddGlobalStopWordAndDetect(): void
    {
        $r = StopWordRules::add('global', null, 'badword', '1h', (int) $this->owner['id']);
        $this->assertTrue($r['added'] ?? false);

        $list = StopWordRules::listGlobal();
        $this->assertCount(1, $list);
        $this->assertSame('badword', $list[0]['pattern']);

        // детектор подхватывает новое слово (кэш сброшен внутри add)
        $member = TestDb::user();
        TestDb::addMember($this->roomId, (int) $member['id']);
        StopWordDetector::scan($this->roomId, (int) $member['id'], 'это badword тут');
        $hit = Connection::getInstance()->fetchOne('SELECT COUNT(*) AS c FROM moderation_shadow_log');
        $this->assertSame(1, (int) $hit['c']);
    }

    public function testDuplicateStopWordRejected(): void
    {
        StopWordRules::add('global', null, 'dup', '1h', (int) $this->owner['id']);
        $second = StopWordRules::add('global', null, 'dup', '1h', (int) $this->owner['id']);
        $this->assertSame(409, $second['code'] ?? 0);
    }

    public function testShortStopWordRejected(): void
    {
        $r = StopWordRules::add('global', null, 'x', '1h', (int) $this->owner['id']);
        $this->assertSame(400, $r['code'] ?? 0);
    }

    public function testGlobalAndRoomStopWordsAreSeparate(): void
    {
        StopWordRules::add('global', null, 'samewords', '1h', (int) $this->owner['id']);
        // то же слово, но в комнате — отдельная область, дубля нет
        $r = StopWordRules::add('room', $this->roomId, 'samewords', '24h', (int) $this->owner['id']);
        $this->assertTrue($r['added'] ?? false);

        $this->assertCount(1, StopWordRules::listGlobal());
        $this->assertCount(1, StopWordRules::listRoom($this->roomId));
    }

    public function testRoomStopWordOnlyAffectsItsRoom(): void
    {
        $otherRoom = TestDb::room((int) $this->owner['id']);
        StopWordRules::add('room', $this->roomId, 'roomonly', '1h', (int) $this->owner['id']);

        StopWordDetector::scan($otherRoom, (int) $this->owner['id'], 'roomonly в другой комнате');
        $this->assertSame(0, (int) Connection::getInstance()->fetchOne('SELECT COUNT(*) AS c FROM moderation_shadow_log')['c']);

        StopWordDetector::scan($this->roomId, (int) $this->owner['id'], 'roomonly в своей комнате');
        $this->assertSame(1, (int) Connection::getInstance()->fetchOne('SELECT COUNT(*) AS c FROM moderation_shadow_log')['c']);
    }

    public function testFindAndRemove(): void
    {
        StopWordRules::add('room', $this->roomId, 'removeme', '1h', (int) $this->owner['id']);
        $id = (int) StopWordRules::listRoom($this->roomId)[0]['id'];

        $found = StopWordRules::find($id);
        $this->assertSame('room', $found['scope']);
        $this->assertSame($this->roomId, $found['room_id']);

        StopWordRules::remove($id);
        $this->assertSame([], StopWordRules::listRoom($this->roomId));
        $this->assertNull(StopWordRules::find($id));
    }
}
