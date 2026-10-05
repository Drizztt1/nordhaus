<?php

declare(strict_types=1);

namespace App\Product\Availability;

/**
 * Wartości muszą odpowiadać opcjom pola select "availability" w klasie Product.
 */
enum Availability: string
{
    case InStock = 'in_stock';
    case OutOfStock = 'out_of_stock';

    public static function fromStock(?int $stock): self
    {
        return self::fromBool(($stock ?? 0) > 0);
    }

    public static function fromBool(bool $inStock): self
    {
        return $inStock ? self::InStock : self::OutOfStock;
    }
}
