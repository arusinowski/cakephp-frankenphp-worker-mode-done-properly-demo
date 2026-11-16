<?php
declare(strict_types=1);

namespace App\Test\TestCase\Flags;

use App\Flags\RuleCompiler;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;

class RuleCompilerTest extends TestCase
{
    private RuleCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compiler = new RuleCompiler();
    }

    public function testNullRulesEnabledIsOnForEveryone(): void
    {
        $this->assertTrue($this->compiler->compile('f', true, null)([]));
    }

    public function testDisabledIsAlwaysOff(): void
    {
        $this->assertFalse($this->compiler->compile('f', false, null)(['user' => 'u1']));
    }

    public function testEqAndNeq(): void
    {
        $eq = $this->compiler->compile('f', true, ['segments' => [['attr' => 'plan', 'op' => 'eq', 'value' => 'pro']]]);
        $this->assertTrue($eq(['plan' => 'pro']));
        $this->assertFalse($eq(['plan' => 'free']));
        $this->assertFalse($eq([]));

        $neq = $this->compiler->compile('f', true, ['segments' => [['attr' => 'plan', 'op' => 'neq', 'value' => 'pro']]]);
        $this->assertTrue($neq([]));
        $this->assertTrue($neq(['plan' => 'free']));
        $this->assertFalse($neq(['plan' => 'pro']));
    }

    public function testInAndNotIn(): void
    {
        $in = $this->compiler->compile('f', true, [
            'segments' => [['attr' => 'country', 'op' => 'in', 'values' => ['PL', 'DE']]],
        ]);
        $this->assertTrue($in(['country' => 'PL']));
        $this->assertFalse($in(['country' => 'FR']));
        $this->assertFalse($in([]));

        $notIn = $this->compiler->compile('f', true, [
            'segments' => [['attr' => 'country', 'op' => 'not_in', 'values' => ['PL', 'DE']]],
        ]);
        $this->assertTrue($notIn([]));
        $this->assertTrue($notIn(['country' => 'FR']));
        $this->assertFalse($notIn(['country' => 'PL']));
    }

    public function testNonStringAttributeCountsAsMissing(): void
    {
        $eq = $this->compiler->compile('f', true, ['segments' => [['attr' => 'plan', 'op' => 'eq', 'value' => 'pro']]]);
        $this->assertFalse($eq(['plan' => ['pro']]));
    }

    public function testAllSegmentsMustMatch(): void
    {
        $compiled = $this->compiler->compile('f', true, [
            'segments' => [
                ['attr' => 'country', 'op' => 'in', 'values' => ['PL']],
                ['attr' => 'plan', 'op' => 'eq', 'value' => 'pro'],
            ],
        ]);
        $this->assertTrue($compiled(['country' => 'PL', 'plan' => 'pro']));
        $this->assertFalse($compiled(['country' => 'PL', 'plan' => 'free']));
    }

    public function testRolloutZeroIsOff(): void
    {
        $this->assertFalse($this->compiler->compile('f', true, ['rollout' => 0])(['user' => 'u1']));
    }

    public function testRolloutHundredNeedsNoUser(): void
    {
        $this->assertTrue($this->compiler->compile('f', true, ['rollout' => 100])([]));
    }

    public function testPartialRolloutWithoutUserIsOff(): void
    {
        $this->assertFalse($this->compiler->compile('f', true, ['rollout' => 50])([]));
    }

    public function testPartialRolloutUsesCrc32Bucket(): void
    {
        $compiled = $this->compiler->compile('new-checkout', true, ['rollout' => 50]);
        foreach (['u1', 'u2', 'u3', 'u4', 'u5', 'u6'] as $user) {
            $this->assertSame(crc32('new-checkout:' . $user) % 100 < 50, $compiled(['user' => $user]));
            $this->assertSame($compiled(['user' => $user]), $compiled(['user' => $user]));
        }
    }

    public function testValidateAcceptsValidRules(): void
    {
        $this->assertSame([], $this->compiler->validate(null));
        $this->assertSame([], $this->compiler->validate([
            'rollout' => 25,
            'segments' => [
                ['attr' => 'plan', 'op' => 'eq', 'value' => 'pro'],
                ['attr' => 'country', 'op' => 'not_in', 'values' => ['PL']],
            ],
        ]));
    }

    public function testValidateRejectsInvalidRules(): void
    {
        $invalid = [
            ['rollout' => 101],
            ['rollout' => -1],
            ['rollout' => '25'],
            ['segments' => [['attr' => 'email', 'op' => 'eq', 'value' => 'x']]],
            ['segments' => [['attr' => 'plan', 'op' => 'gt', 'value' => 'x']]],
            ['segments' => [['attr' => 'plan', 'op' => 'in', 'values' => []]]],
            ['segments' => [['attr' => 'plan', 'op' => 'eq']]],
            ['segments' => 'x'],
            ['unknown' => 1],
            'string',
            [1, 2],
        ];
        foreach ($invalid as $bad) {
            $this->assertNotEmpty($this->compiler->validate($bad), (string)json_encode($bad));
        }
    }

    public function testCompileThrowsOnInvalidRules(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->compiler->compile('f', true, ['rollout' => 101]);
    }
}
