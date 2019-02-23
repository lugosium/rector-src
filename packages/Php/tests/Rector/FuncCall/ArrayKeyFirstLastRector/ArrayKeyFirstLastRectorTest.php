<?php declare(strict_types=1);

namespace Rector\Php\Tests\Rector\FuncCall\ArrayKeyFirstLastRector;

use Rector\Php\Rector\FuncCall\ArrayKeyFirstLastRector;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

final class ArrayKeyFirstLastRectorTest extends AbstractRectorTestCase
{
    public function test(): void
    {
        $this->doTestFiles([
            __DIR__ . '/Fixture/array_key_first.php.inc',
            __DIR__ . '/Fixture/array_key_last.php.inc',
            __DIR__ . '/Fixture/both.php.inc',
        ]);
    }

    public function getRectorClass(): string
    {
        return ArrayKeyFirstLastRector::class;
    }
}
