<?php
declare(strict_types=1);

namespace App\Test\TestCase\Worker\Dev;

/**
 * A static memo of the kind the leak detector looks for, for LeakDetectorTest.
 */
class LeakyStatic
{
    public static ?string $cache = null;
}
