<?php
declare(strict_types=1);

namespace App\Test\TestCase\Worker\Dev;

use App\Application;
use App\Worker\Dev\DevWorkerRuntime;
use App\Worker\Dev\LeakDetector;
use App\Worker\RequestCycle;
use App\Worker\WorkerRuntime;
use Cake\Http\ServerRequest;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;

class DevWorkerRuntimeTest extends TestCase
{
    /**
     * @var list<string>
     */
    protected array $fixtures = ['app.Tenants', 'app.Flags'];

    protected function setUp(): void
    {
        parent::setUp();
        Router::reload();
    }

    protected function tearDown(): void
    {
        Log::drop('leaks');
        parent::tearDown();
    }

    public function testIsAWorkerRuntime(): void
    {
        $this->assertInstanceOf(WorkerRuntime::class, new DevWorkerRuntime(new Application(CONFIG)));
    }

    public function testWorkerIdHeaderWhenEnabled(): void
    {
        $app = new Application(CONFIG);
        $runtime = new DevWorkerRuntime($app, cycle: new RequestCycle($app), exposeWorkerId: true);
        $runtime->boot();

        $sent = '';
        $first = $runtime->handle($this->get('/api/flags/acme'), function ($response) use (&$sent): void {
            $sent = $response->getHeaderLine('X-Worker-Id');
        })->getHeaderLine('X-Worker-Id');
        $second = $runtime->handle($this->get('/api/flags/globex'))->getHeaderLine('X-Worker-Id');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $first);
        $this->assertSame($first, $sent, 'the sent response carries the header');
        $this->assertSame($first, $second);
    }

    public function testTheProductionRuntimeSendsNoWorkerId(): void
    {
        $app = new Application(CONFIG);
        $runtime = new WorkerRuntime($app, cycle: new RequestCycle($app));
        $runtime->boot();
        $this->assertSame('', $runtime->handle($this->get('/api/flags/acme'))->getHeaderLine('X-Worker-Id'));
    }

    public function testLeakDetectorLogsStateThatOutlivedTheRequest(): void
    {
        // Without the reset layer, the Table instances loaded by the request stay in the locator.
        Log::setConfig('leaks', ['className' => ArrayLog::class, 'levels' => ['warning']]);
        $runtime = new DevWorkerRuntime(new Application(CONFIG), detector: new LeakDetector());
        $runtime->boot();
        $runtime->handle($this->get('/api/flags/acme'));

        $logged = implode("\n", Log::engine('leaks')->read());
        $this->assertStringContainsString('Worker leak: TableLocator tables changed and outlived the request', $logged);
    }

    private function get(string $url): ServerRequest
    {
        return new ServerRequest(['url' => $url, 'environment' => ['REQUEST_METHOD' => 'GET']]);
    }
}
