<?php

declare(strict_types=1);

namespace App\Product;

use Pimcore\Model\DataObject\Product;

/**
 * tabela store = tylko własne wartości obiektu (bez dziedziczenia) -> źródło prawdy dla reguł
 * tabela query = wartości łącznie z odziedziczonymi -> po niej filtrują listingi Product
 */
final class ProductTables
{
    public static function store(): string
    {
        return 'object_store_' . Product::classId();
    }

    public static function query(): string
    {
        return 'object_query_' . Product::classId();
    }
}
