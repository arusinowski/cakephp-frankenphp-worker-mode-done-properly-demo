<?php
declare(strict_types=1);

namespace App\Test\TestCase\Worker\Dev;

use App\Worker\Dev\LeakDetector;
use Cake\Core\Configure;
use Cake\Event\EventManager;
use Cake\TestSuite\TestCase;

class LeakDetectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        LeakyStatic::$cache = null;
    }

    protected function tearDown(): void
    {
        LeakyStatic::$cache = null;
        parent::tearDown();
    }

    public function testNoChangeNoReport(): void
    {
        $detector = new LeakDetector();
        $this->assertSame([], $detector->diff($detector->snapshot(), $detector->snapshot()));
    }

    public function testReportsStaticPropertyWrittenDuringRequest(): void
    {
        $detector = new LeakDetector(['App\\']);
        $before = $detector->snapshot();
        LeakyStatic::$cache = 'acme-secret';
        $this->assertSame(
            ['static App\Test\TestCase\Worker\Dev\LeakyStatic::$cache'],
            $detector->diff($before, $detector->snapshot()),
        );
    }

    public function testAllowListSilencesKnownState(): void
    {
        $detector = new LeakDetector(['App\\'], ['static App\Test\TestCase\Worker\Dev\LeakyStatic::$cache']);
        $before = $detector->snapshot();
        LeakyStatic::$cache = 'acme-secret';
        $this->assertSame([], $detector->diff($before, $detector->snapshot()));
    }

    public function testReportsConfigureAndGlobalListeners(): void
    {
        $detector = new LeakDetector();
        $before = $detector->snapshot();
        Configure::write('Tenant.current', 'acme');
        EventManager::instance()->on('Some.event', fn() => null);
        $changes = $detector->diff($before, $detector->snapshot());
        EventManager::instance()->off('Some.event');
        $this->assertContains('Configure', $changes);
        $this->assertContains('EventManager::instance() listeners', $changes);
    }
}
