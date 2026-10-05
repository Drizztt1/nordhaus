<?php

declare(strict_types=1);

namespace App\Product\FieldPlacement;

use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Product;
use Pimcore\Model\DataObject\Service;
use Pimcore\Model\Element\ValidationException;
use Pimcore\Tool;

/**
 * Pilnuje kolumny "Where it is filled" z briefu również poza panelem admina (import z ERP, API, Data Hub):
 * - name, description (i wyprowadzony z name urlKey) są wypełniane na rodzicu i dziedziczone przez warianty,
 * - stock, fabric, leg istnieją tylko na wariantach.
 * W panelu to samo wymuszają custom layouty (zob. AdminLayoutSubscriber); tu zapis jest odrzucany.
 */
final class FieldPlacementGuard
{
    /** Pola lokalizowane dziedziczone z rodzica: wariant nie może mieć własnej wartości. */
    public const INHERITED_FROM_PARENT = ['name', 'description', 'urlKey'];

    /** Pola tylko dla wariantów: rodzic nie może mieć wartości. */
    public const VARIANT_ONLY = ['stock', 'fabric', 'leg'];

    public function assertValid(Product $product): void
    {
        $misplaced = Service::useInheritedValues(false, fn (): array => $product->getType() === AbstractObject::OBJECT_TYPE_VARIANT
            ? $this->ownInheritedValues($product)
            : $this->variantOnlyValues($product));

        if ($misplaced === []) {
            return;
        }

        $fields = (count($misplaced) === 1 ? 'Field "' : 'Fields "') . implode('", "', $misplaced) . '"';
        throw new ValidationException($product->getType() === AbstractObject::OBJECT_TYPE_VARIANT
            ? sprintf('%s %s inherited from the parent product and cannot be set on a variant.', $fields, count($misplaced) === 1 ? 'is' : 'are')
            : sprintf('%s can only be set on variants, not on the parent product.', $fields));
    }

    /** @return string[] np. ["name (pl)"] */
    private function ownInheritedValues(Product $variant): array
    {
        $misplaced = [];
        foreach (self::INHERITED_FROM_PARENT as $field) {
            foreach (Tool::getValidLanguages() as $language) {
                if (!$this->isEmpty($variant->get($field, $language))) {
                    $misplaced[] = sprintf('%s (%s)', $field, $language);
                }
            }
        }

        return $misplaced;
    }

    /** @return string[] */
    private function variantOnlyValues(Product $parent): array
    {
        return array_values(array_filter(
            self::VARIANT_ONLY,
            fn (string $field): bool => !$this->isEmpty($parent->get($field))
        ));
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim(strip_tags($value)) === '');
    }
}
