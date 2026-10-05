<?php

declare(strict_types=1);

namespace App\Tests\Functional\Product;

use Pimcore\Db;
use Pimcore\Model\DataObject\Product;
use Pimcore\Model\DataObject\Service;
use Pimcore\Model\Element\ValidationException;

class SkuTest extends ProductTestCase
{
    public function testSkuIsTrimmedAndUppercasedOnSave(): void
    {
        $product = $this->createParent('sofa', "  sofa-01\u{00A0}");

        self::assertSame('SOFA-01', $this->reload($product)->getSku());
    }

    public function testDuplicateIsRejectedWithClearMessageRegardlessOfCaseAndPublishedState(): void
    {
        $this->createParent('sofa', 'SOFA-01', published: false);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/SKU "SOFA-01" is already used by product ".*\/sofa"/');

        $this->createParent('copy', ' sofa-01 ');
    }

    public function testDuplicateBetweenVariantAndParentIsRejected(): void
    {
        $sofa = $this->createParent('sofa', 'SOFA-01');
        $this->createVariant($sofa, 'velvet', 'SOFA-01-VELVET', 0);

        try {
            $this->createVariant($sofa, 'linen', 'sofa-01', 0);
            self::fail('Variant with the parent\'s SKU was saved.');
        } catch (ValidationException) {
        }

        $this->expectException(ValidationException::class);
        $this->createParent('chair', 'sofa-01-velvet');
    }

    public function testRejectedUpdateLeavesStoredSkuUnchanged(): void
    {
        $this->createParent('sofa', 'SOFA-01');
        $chair = $this->createParent('chair', 'CHAIR-01');

        try {
            $this->reload($chair)->setSku('SOFA-01')->save();
            self::fail('Duplicate SKU was saved.');
        } catch (ValidationException) {
        }

        self::assertSame('CHAIR-01', $this->reload($chair)->getSku());
    }

    public function testResavingAProductWithItsOwnSkuIsAllowed(): void
    {
        $sofa = $this->createParent('sofa', 'SOFA-01');

        $this->reload($sofa)->setName('Sofa', 'en')->save();

        self::assertSame('SOFA-01', $this->reload($sofa)->getSku());
    }

    public function testUnpublishedVariantWithoutOwnSkuIsNotADuplicateOfItsParent(): void
    {
        $sofa = $this->createParent('sofa', 'SOFA-01');
        $variant = $this->createVariant($sofa, 'draft', null, 0, published: false);

        self::assertNull(Service::useInheritedValues(false, fn () => $this->reload($variant)->getSku()));
    }

    public function testPublishedVariantNeedsItsOwnSku(): void
    {
        $sofa = $this->createParent('sofa', 'SOFA-01');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('needs its own SKU');

        $this->createVariant($sofa, 'velvet', null, 1);
    }

    public function testUniqueIndexIsTheBackstopForConcurrentWrites(): void
    {
        $this->createParent('sofa', 'SOFA-01');
        $chair = $this->createParent('chair', 'CHAIR-01');

        // Symulacja równoczesnych zapisów (race condition): drugi zapis omija sprawdzenie w PHP, zostaje indeks UNIQUE.
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        Db::get()->executeStatement('UPDATE object_store_product SET sku = ? WHERE oo_id = ?', ['SOFA-01', $chair->getId()]);
    }

    public function testAutosaveDoesNotShowDuplicateErrors(): void
    {
        $this->createParent('sofa', 'SOFA-01');
        $chair = $this->createParent('chair', 'CHAIR-01');

        // Autosave zapisuje tylko wersję roboczą (bez tabel store/query) — bez błędów co kilka sekund.
        $draft = $this->reload($chair)->setSku(' sofa-01 ');
        $draft->saveVersion(true, true, null, true);

        self::assertSame('SOFA-01', $draft->getSku(), 'normalised even on autosave');
        self::assertSame('CHAIR-01', Product::getById((int) $chair->getId(), ['force' => true])?->getSku());
    }
}
