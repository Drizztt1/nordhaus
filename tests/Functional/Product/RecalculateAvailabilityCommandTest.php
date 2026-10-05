<?php

declare(strict_types=1);

namespace App\Tests\Functional\Product;

use Pimcore\Db;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class RecalculateAvailabilityCommandTest extends ProductTestCase
{
    public function testRepairsDriftInBothTablesAndIsIdempotent(): void
    {
        $sofa = $this->createParent('sofa', 'SOFA-01');
        $inStock = $this->createVariant($sofa, 'velvet', 'SOFA-01-V', 4);
        $empty = $this->createVariant($sofa, 'linen', 'SOFA-01-L', 0);
        $chair = $this->createParent('chair', 'CHAIR-01');
        $this->createVariant($chair, 'oak', 'CHAIR-01-O', 9, published: false);

        // Rozjazd jak po wdrożeniu nowego pola albo ręcznej edycji bazy.
        $db = Db::get();
        $db->executeStatement('UPDATE object_store_product SET availability = NULL WHERE oo_id IN (?, ?)', [$sofa->getId(), $inStock->getId()]);
        $db->executeStatement("UPDATE object_query_product SET availability = 'garbage' WHERE oo_id = ?", [$empty->getId()]);
        $db->executeStatement("UPDATE object_query_product SET availability = 'in_stock' WHERE oo_id = ?", [$chair->getId()]);

        self::assertStringContainsString('2 variants and 2 parent', $this->runCommand());

        self::assertSame('in_stock', $this->listedAvailability($sofa));
        self::assertSame('in_stock', $this->listedAvailability($inStock));
        self::assertSame('out_of_stock', $this->listedAvailability($empty));
        self::assertSame('out_of_stock', $this->listedAvailability($chair), 'only an unpublished variant has stock');
        self::assertSame('in_stock', $this->reload($sofa)->getAvailability(), 'cache of changed objects is evicted');

        self::assertStringContainsString('0 variants and 0 parent', $this->runCommand());
    }

    private function runCommand(): string
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:product:recalculate-availability'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        return $tester->getDisplay();
    }
}
