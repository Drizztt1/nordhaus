<?php

declare(strict_types=1);

namespace App\Tests\Functional\Product;

use Pimcore\Db;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Product;
use Pimcore\Model\Element\Recyclebin\Item;

class AvailabilityTest extends ProductTestCase
{
    public function testVariantAvailabilityFollowsItsOwnStock(): void
    {
        $sofa = $this->createParent('sofa', 'SOFA-01');
        $variant = $this->createVariant($sofa, 'velvet', 'SOFA-01-V', 0);
        self::assertSame('out_of_stock', $this->listedAvailability($variant));

        $this->reload($variant)->setStock(4)->save();
        self::assertSame('in_stock', $this->listedAvailability($variant));

        $this->reload($variant)->setStock(-1)->save(); // wyprzedane w ERP
        self::assertSame('out_of_stock', $this->listedAvailability($variant));
    }

    public function testParentWithoutVariantsIsOutOfStock(): void
    {
        $sofa = $this->createParent('sofa', 'SOFA-01');

        self::assertSame('out_of_stock', $this->listedAvailability($sofa));
    }

    public function testParentFollowsStockChangesOfItsVariants(): void
    {
        $sofa = $this->createParent('sofa', 'SOFA-01');
        $velvet = $this->createVariant($sofa, 'velvet', 'SOFA-01-V', 0);
        $linen = $this->createVariant($sofa, 'linen', 'SOFA-01-L', 0);
        self::assertSame('out_of_stock', $this->listedAvailability($sofa));

        $this->reload($velvet)->setStock(5)->save(); // jak import z ERP
        self::assertSame('in_stock', $this->listedAvailability($sofa));

        $this->reload($linen)->setStock(2)->save();
        $this->reload($velvet)->setStock(0)->save();
        self::assertSame('in_stock', $this->listedAvailability($sofa), 'another variant is still in stock');

        $this->reload($linen)->setStock(0)->save();
        self::assertSame('out_of_stock', $this->listedAvailability($sofa));
    }

    public function testOnlyPublishedVariantsCountForTheParent(): void
    {
        $sofa = $this->createParent('sofa', 'SOFA-01');
        $variant = $this->createVariant($sofa, 'velvet', 'SOFA-01-V', 10, published: false);
        self::assertSame('out_of_stock', $this->listedAvailability($sofa));
        self::assertSame('in_stock', $this->listedAvailability($variant), 'the variant keeps its own value');

        $this->reload($variant)->setPublished(true)->save();
        self::assertSame('in_stock', $this->listedAvailability($sofa));

        $this->reload($variant)->setPublished(false)->save();
        self::assertSame('out_of_stock', $this->listedAvailability($sofa));
    }

    public function testDeletingTheLastStockedVariantMakesTheParentUnavailable(): void
    {
        $sofa = $this->createParent('sofa', 'SOFA-01');
        $velvet = $this->createVariant($sofa, 'velvet', 'SOFA-01-V', 3);
        $linen = $this->createVariant($sofa, 'linen', 'SOFA-01-L', 1);

        $this->reload($velvet)->delete();
        self::assertSame('in_stock', $this->listedAvailability($sofa));

        $this->reload($linen)->delete();
        self::assertSame('out_of_stock', $this->listedAvailability($sofa));
    }

    public function testMovingAVariantUpdatesBothParents(): void
    {
        $sofa = $this->createParent('sofa', 'SOFA-01');
        $chair = $this->createParent('chair', 'CHAIR-01');
        $variant = $this->createVariant($sofa, 'velvet', 'SOFA-01-V', 2);

        $this->reload($variant)->setParentId($chair->getId())->save();

        self::assertSame('out_of_stock', $this->listedAvailability($sofa));
        self::assertSame('in_stock', $this->listedAvailability($chair));
    }

    public function testRestoringAVariantFromTheRecycleBinUpdatesTheParent(): void
    {
        $sofa = $this->createParent('sofa', 'SOFA-01');
        $variant = $this->createVariant($sofa, 'velvet', 'SOFA-01-V', 3);

        Item::create($variant);
        $variant->delete();
        self::assertSame('out_of_stock', $this->listedAvailability($sofa));

        $items = new Item\Listing();
        $items->setCondition('path = ?', [$variant->getRealFullPath()]);
        foreach ($items->load() as $item) {
            $item->restore();
            $item->delete();
        }

        self::assertSame('in_stock', $this->listedAvailability($sofa));
    }

    public function testFrontendCanFilterParentsByAvailabilityWithoutLoadingVariants(): void
    {
        $sofa = $this->createParent('sofa', 'SOFA-01');
        $chair = $this->createParent('chair', 'CHAIR-01');
        $this->createVariant($sofa, 'velvet', 'SOFA-01-V', 1);
        $this->createVariant($chair, 'oak', 'CHAIR-01-O', 0);

        $list = new Product\Listing();
        $list->setObjectTypes([AbstractObject::OBJECT_TYPE_OBJECT]); // listingi Pimcore 11 domyślnie zawierają warianty
        $list->setCondition('availability = ? AND path LIKE ?', ['in_stock', $this->folder->getRealFullPath() . '/%']);

        self::assertSame([$sofa->getId()], $list->loadIdList());
    }

    public function testAParentFlipIsADirectWriteWithoutNewParentVersions(): void
    {
        $sofa = $this->createParent('sofa', 'SOFA-01');
        $variant = $this->createVariant($sofa, 'velvet', 'SOFA-01-V', 0);
        $versionsBefore = $this->versionCount($sofa);

        $this->reload($variant)->setStock(7)->save();

        self::assertSame('in_stock', $this->listedAvailability($sofa));
        self::assertSame('in_stock', $this->reload($sofa)->getAvailability(), 'cache of the parent is evicted');
        self::assertSame($versionsBefore, $this->versionCount($sofa));
    }

    public function testSavingTheParentRepairsAWrongValue(): void
    {
        $sofa = $this->createParent('sofa', 'SOFA-01');
        $this->createVariant($sofa, 'velvet', 'SOFA-01-V', 1);
        Db::get()->executeStatement("UPDATE object_store_product SET availability = 'out_of_stock' WHERE oo_id = ?", [$sofa->getId()]);

        $this->reload($sofa)->save();

        self::assertSame('in_stock', $this->listedAvailability($sofa));
    }

    public function testDeletingAParentWithVariantsWorks(): void
    {
        $sofa = $this->createParent('sofa', 'SOFA-01');
        $this->createVariant($sofa, 'velvet', 'SOFA-01-V', 1);

        $this->reload($sofa)->delete();

        self::assertNull(Product::getById((int) $sofa->getId(), ['force' => true]));
    }

    private function versionCount(Product $product): int
    {
        return (int) Db::get()->fetchOne(
            'SELECT COUNT(*) FROM versions WHERE ctype = ? AND cid = ?',
            ['object', $product->getId()]
        );
    }
}
