<?php
/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Unit\ViewModel;

use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\TridentClient;
use Qoliber\TridentCache\ViewModel\Stats;

/**
 * The Cache Management status block: which Trident is connected, and whether
 * it is on this module's release line (lockstep versioning).
 */
class StatsTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $status
     */
    private function stats(?array $status): Stats
    {
        $client = $this->createMock(TridentClient::class);
        $client->method('getStatus')->willReturn($status);

        return new Stats($client, $this->createMock(Config::class));
    }

    /**
     * GET /admin/health reports no version on Trident 1.8, so the version (and
     * the compatibility check) must come from GET /admin/status.
     */
    public function testTheVersionComesFromTheStatusEndpoint(): void
    {
        $this->assertSame('1.8.0', $this->stats(['version' => '1.8.0', 'mode' => 'licensed'])->getTridentVersion());
        $this->assertNull($this->stats(null)->getTridentVersion(), 'unreachable: no version, no warning');
        $this->assertNull($this->stats(['mode' => 'licensed'])->getTridentVersion());
    }

    public function testTheSameReleaseLineIsNoWarning(): void
    {
        $stats = $this->stats(['version' => '1.8.3']);
        $this->assertNull($stats->compatibilityWarning($stats->getTridentVersion()));
        $this->assertNull($stats->compatibilityWarning(null));
    }

    public function testAnotherReleaseLineIsAWarningNamingBothSides(): void
    {
        $stats = $this->stats(['version' => '1.9.0']);
        $warning = (string) $stats->compatibilityWarning($stats->getTridentVersion());
        $this->assertStringContainsString('Trident 1.9.0 is connected', $warning);
        $this->assertStringContainsString('built for Trident 1.8.x', $warning);
    }
}
