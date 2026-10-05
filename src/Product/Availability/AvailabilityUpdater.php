<?php

declare(strict_types=1);

namespace App\Product\Availability;

use App\Product\ProductTables;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Pimcore\Cache;
use Pimcore\Cache\RuntimeCache;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Product;
use Pimcore\Model\DataObject\Service;

/**
 * Utrzymuje poprawną wartość zdenormalizowanego pola "availability" produktów.
 *
 * - wariant: in_stock, gdy jego własny stock > 0
 * - rodzic:  in_stock, gdy co najmniej jeden *opublikowany* wariant ma stock > 0
 *
 * Wartość jest zapisana na każdym obiekcie, więc frontend może filtrować zwykły listing Product
 * (np. `availability = 'in_stock'`) po zindeksowanej kolumnie, bez dotykania wariantów.
 */
final class AvailabilityUpdater
{
    private const CHUNK_SIZE = 1000;

    public function __construct(private readonly Connection $db)
    {
    }

    /** Pre-save wariantu. */
    public function applyToVariant(Product $variant): void
    {
        $stock = Service::useInheritedValues(false, static fn () => $variant->getStock());
        $variant->setAvailability(Availability::fromStock($stock)->value);
    }

    /** Pre-save rodzica (zapis edytora/API): zawsze przeliczane, więc zapis rodzica sam naprawia wartość. */
    public function applyToParent(Product $parent): void
    {
        $inStock = $parent->getId() !== null && (bool) $this->db->fetchOne(
            'SELECT ' . $this->parentInStockSql() . ' FROM objects o WHERE o.id = ?',
            [$parent->getId()]
        );
        $parent->setAvailability(Availability::fromBool($inStock)->value);
    }

    /**
     * Uzgadnia zapisane wartości rodziców z ich wariantami i zwraca liczbę zmienionych.
     * Używane po zmianie wariantu (jedno id) i przez komendę naprawczą (null = wszyscy rodzice).
     *
     * Zapisuje kolumnę bezpośrednio zamiast wołać $parent->save(): availability to dane wyliczane, a pełny
     * zapis utworzyłby wersję, ponownie zwalidował rodzica i uruchomił inheritance helper dla wszystkich
     * jego wariantów, tylko po to, by zmienić jedną wartość. Wpis rodzica w cache jest unieważniany jawnie.
     *
     * @param int[]|null $parentIds
     */
    public function refreshParents(?array $parentIds = null): int
    {
        if ($parentIds === []) {
            return 0;
        }

        // Tabela pochodna: EXISTS liczy się raz na rodzica, potem porównanie z zapisanymi wartościami.
        // Sprawdzane są obie tabele: store to źródło prawdy, query to tabela, po której filtrują listingi.
        $sql = 'SELECT id, in_stock FROM (
                    SELECT o.id, s.availability, q.availability AS listed, ' . $this->parentInStockSql() . ' AS in_stock
                    FROM objects o
                    JOIN ' . ProductTables::store() . ' s ON s.oo_id = o.id
                    JOIN ' . ProductTables::query() . " q ON q.oo_id = o.id
                    WHERE o.type = :object%s
                ) parents
                WHERE NOT (availability <=> IF(in_stock, :in, :out)) OR NOT (listed <=> IF(in_stock, :in, :out))";
        $params = $this->valueParams() + ['object' => AbstractObject::OBJECT_TYPE_OBJECT];
        $types = [];

        if ($parentIds !== null) {
            $params['ids'] = $parentIds;
            $types['ids'] = ArrayParameterType::INTEGER;
        }
        $sql = sprintf($sql, $parentIds === null ? '' : ' AND o.id IN (:ids)');

        $changed = $this->toAvailabilityById($this->db->fetchAllKeyValue($sql, $params, $types));
        $this->persist($changed);

        return count($changed);
    }

    /**
     * Tylko dla komendy naprawczej; w trakcie normalnej pracy warianty obsługuje ich własny pre-save.
     */
    public function refreshAllVariants(): int
    {
        // Odpowiednik Availability::fromStock() w SQL.
        $rows = $this->db->fetchAllKeyValue(
            'SELECT id, in_stock FROM (
                 SELECT o.id, s.availability, q.availability AS listed, COALESCE(s.stock, 0) > 0 AS in_stock
                 FROM objects o
                 JOIN ' . ProductTables::store() . ' s ON s.oo_id = o.id
                 JOIN ' . ProductTables::query() . ' q ON q.oo_id = o.id
                 WHERE o.type = :variant
             ) variants
             WHERE NOT (availability <=> IF(in_stock, :in, :out)) OR NOT (listed <=> IF(in_stock, :in, :out))',
            $this->valueParams() + ['variant' => AbstractObject::OBJECT_TYPE_VARIANT]
        );

        $changed = $this->toAvailabilityById($rows);
        $this->persist($changed);

        return count($changed);
    }

    /**
     * Jedyne miejsce, gdzie reguła rodzica jest zapisana w SQL. Wymaga aliasu "o" dla wiersza rodzica.
     */
    private function parentInStockSql(): string
    {
        return 'EXISTS (SELECT 1 FROM objects v JOIN ' . ProductTables::store() . " vs ON vs.oo_id = v.id
                WHERE v.parentId = o.id AND v.type = 'variant' AND v.published = 1 AND vs.stock > 0)";
    }

    /**
     * @param array<int|string, mixed> $inStockById wiersze id => 0/1 z bazy
     *
     * @return array<int, Availability>
     */
    private function toAvailabilityById(array $inStockById): array
    {
        $result = [];
        foreach ($inStockById as $id => $inStock) {
            $result[(int) $id] = Availability::fromBool((bool) $inStock);
        }

        return $result;
    }

    /**
     * @param array<int, Availability> $availabilityById
     */
    private function persist(array $availabilityById): void
    {
        foreach (Availability::cases() as $availability) {
            $ids = array_keys(array_filter($availabilityById, static fn (Availability $a) => $a === $availability));

            foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
                // availability nigdy nie jest dziedziczone (każdy obiekt ma własną wartość): ta sama wartość w obu tabelach.
                $this->db->transactional(function (Connection $db) use ($availability, $chunk): void {
                    foreach ([ProductTables::store(), ProductTables::query()] as $table) {
                        $db->executeStatement(
                            "UPDATE $table SET availability = ? WHERE oo_id IN (?)",
                            [$availability->value, $chunk],
                            [1 => ArrayParameterType::INTEGER]
                        );
                    }
                });

                $tags = array_map(static fn (int $id) => 'object_' . $id, $chunk);
                Cache::clearTags($tags);
                foreach ($tags as $tag) {
                    RuntimeCache::set($tag, null); // długo działający import: usuń nieaktualnego rodzica z pamięci
                }
            }
        }
    }

    /** @return array{in: string, out: string} */
    private function valueParams(): array
    {
        return ['in' => Availability::InStock->value, 'out' => Availability::OutOfStock->value];
    }
}
