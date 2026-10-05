<?php

declare(strict_types=1);

namespace App\Tests\Functional\Product;

use App\Kernel;
use Pimcore\Cache\RuntimeCache;
use Pimcore\Db;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Folder;
use Pimcore\Model\DataObject\Product;
use Pimcore\Model\Version;
use Pimcore\Test\KernelTestCase;

/**
 * Testy integracyjne na prawdziwym Pimcore i bazie testowej (kontener test-php + test-db).
 * Każdy test pracuje we własnym folderze, usuwanym w tearDown().
 */
abstract class ProductTestCase extends KernelTestCase
{
    protected Folder $folder;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        RuntimeCache::clear();

        $folder = DataObject\Service::createFolderByPath('/product-test-' . uniqid());
        self::assertInstanceOf(Folder::class, $folder);
        $this->folder = $folder;
    }

    protected function tearDown(): void
    {
        RuntimeCache::clear();
        $ids = Db::get()->fetchFirstColumn(
            'SELECT id FROM objects WHERE id = ? OR path LIKE ?',
            [$this->folder->getId(), $this->folder->getRealFullPath() . '/%']
        );
        DataObject::getById((int) $this->folder->getId(), ['force' => true])?->delete();

        // Zwykłe wersje kasuje Pimcore (VersionDeleteMessage, w teście synchronicznie), ale handler pomija wersje
        // z autosave (Version\Listing domyślnie je odfiltrowuje) - bez tego baza testowa zbierałaby sieroty.
        $versions = (new Version\Listing())->setLoadAutoSave(true);
        $versions->setCondition('ctype = ? AND cid IN (' . implode(',', array_map('intval', $ids)) . ')', ['object']);
        foreach ($versions->load() as $version) {
            $version->delete();
        }

        parent::tearDown();
    }

    /**
     * @param array<string, string> $names język => nazwa
     */
    protected function createParent(string $key, ?string $sku, bool $published = true, array $names = []): Product
    {
        $parent = Product::create([
            'key' => $key,
            'parentId' => $this->folder->getId(),
            'sku' => $sku,
            'published' => $published,
        ]);
        foreach ($names as $language => $name) {
            $parent->setName($name, $language);
        }

        return $parent->save();
    }

    protected function createVariant(Product $parent, string $key, ?string $sku, ?int $stock, bool $published = true): Product
    {
        return Product::create([
            'key' => $key,
            'parentId' => $parent->getId(),
            'type' => AbstractObject::OBJECT_TYPE_VARIANT,
            'sku' => $sku,
            'stock' => $stock,
            'published' => $published,
        ])->save();
    }

    /** Świeża kopia z bazy, jak w osobnym żądaniu albo kolejnym przebiegu importu. */
    protected function reload(Product $product): Product
    {
        RuntimeCache::clear();

        return Product::getById((int) $product->getId(), ['force' => true]) ?? self::fail('Product was not found.');
    }

    /** Wartość z tabeli query, czyli ta, po której filtrują listingi. */
    protected function listedAvailability(Product $product): ?string
    {
        $value = Db::get()->fetchOne('SELECT availability FROM object_query_product WHERE oo_id = ?', [$product->getId()]);

        return $value === false ? null : $value;
    }
}
