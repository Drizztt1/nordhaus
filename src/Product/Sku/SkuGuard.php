<?php

declare(strict_types=1);

namespace App\Product\Sku;

use App\Product\ProductTables;
use Doctrine\DBAL\Connection;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Product;
use Pimcore\Model\DataObject\Service;
use Pimcore\Model\Element\ValidationException;

/**
 * Normalizacja SKU i unikalność wśród wszystkich obiektów Product (rodzice, warianty, nieopublikowane).
 *
 * To sprawdzenie istnieje po to, by edytor dostał czytelny komunikat. Faktyczną gwarancją jest indeks UNIQUE
 * na object_store_product.sku (opcja pola "unique"), który obejmuje też równoległe zapisy.
 */
final class SkuGuard
{
    public function __construct(private readonly Connection $db)
    {
    }

    public static function normalize(?string $sku): ?string
    {
        if ($sku === null) {
            return null;
        }

        // Trim świadomy Unicode: usuwa też NBSP / zero-width no-break space z kopiuj-wklej (Excel, web).
        $sku = mb_strtoupper((string) preg_replace('/^[\s\p{Z}\x{FEFF}]+|[\s\p{Z}\x{FEFF}]+$/u', '', $sku));

        // "Brak SKU" zapisujemy jako NULL: indeks unikalny dopuszcza wiele NULL, ale tylko jeden pusty string.
        return $sku === '' ? null : $sku;
    }

    public function applyNormalization(Product $product): void
    {
        // Tylko własna wartość: przy dziedziczeniu wariant bez SKU "widziałby" SKU rodzica.
        $product->setSku(self::normalize(Service::useInheritedValues(false, static fn () => $product->getSku())));
    }

    /**
     * Własne SKU obecne (warianty) i unikalne. Oczekuje już znormalizowanego SKU (zob. applyNormalization()).
     */
    public function assertValid(Product $product): void
    {
        $sku = Service::useInheritedValues(false, static fn () => $product->getSku());
        if ($sku === null) {
            // "mandatory" w klasie obejmuje opublikowanych rodziców, ale dla wariantu spełnia je odziedziczone
            // SKU rodzica -> dwa produkty odpowiadałyby na to samo SKU w listingach/getBySku().
            if ($product->isPublished() && $product->getType() === AbstractObject::OBJECT_TYPE_VARIANT) {
                throw new ValidationException('A published variant needs its own SKU (it must not reuse the parent\'s SKU).');
            }

            return;
        }

        // Tabela store = tylko własne wartości. Tabela query zawierałaby SKU odziedziczone po rodzicu.
        $duplicate = $this->db->fetchAssociative(
            'SELECT o.id, CONCAT(o.path, o.`key`) AS fullPath FROM ' . ProductTables::store() . ' s
             JOIN objects o ON o.id = s.oo_id
             WHERE s.sku = ? AND s.oo_id <> ?
             LIMIT 1',
            [$sku, $product->getId() ?? 0]
        );

        if ($duplicate !== false) {
            throw new ValidationException(sprintf(
                'SKU "%s" is already used by product "%s" (ID %d). Please choose a different SKU.',
                $sku,
                $duplicate['fullPath'],
                $duplicate['id']
            ));
        }
    }
}
