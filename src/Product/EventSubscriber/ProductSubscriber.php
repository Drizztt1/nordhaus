<?php

declare(strict_types=1);

namespace App\Product\EventSubscriber;

use App\Product\Availability\Availability;
use App\Product\Availability\AvailabilityUpdater;
use App\Product\FieldPlacement\FieldPlacementGuard;
use App\Product\ProductTables;
use App\Product\Sku\SkuGuard;
use App\Product\UrlKey\UrlKeyGenerator;
use Doctrine\DBAL\Connection;
use Pimcore\Event\DataObjectEvents;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Product;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Podpina reguły Product pod cykl zapisu Pimcore. Działa tak samo dla zapisów z admina,
 * PHP API (import z ERP), Data Hub itd., bo wszystkie przechodzą przez DataObject::save().
 */
final class ProductSubscriber implements EventSubscriberInterface
{
    /**
     * Id rodziców do odświeżenia po zapisie wariantu, kluczowane spl_object_id wariantu (pre-save -> post-save).
     *
     * @var array<int, int[]>
     */
    private array $parentsToRefresh = [];

    public function __construct(
        private readonly SkuGuard $skuGuard,
        private readonly FieldPlacementGuard $fieldPlacementGuard,
        private readonly AvailabilityUpdater $availabilityUpdater,
        private readonly UrlKeyGenerator $urlKeyGenerator,
        private readonly Connection $db,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            DataObjectEvents::PRE_ADD => 'onPreSave',
            DataObjectEvents::PRE_UPDATE => 'onPreSave',
            DataObjectEvents::POST_ADD => 'onPostSave',
            DataObjectEvents::POST_UPDATE => 'onPostSave',
            DataObjectEvents::POST_ADD_FAILURE => 'onSaveFailure',
            DataObjectEvents::POST_UPDATE_FAILURE => 'onSaveFailure',
            DataObjectEvents::POST_DELETE => 'onPostDelete',
        ];
    }

    public function onPreSave(DataObjectEvent $event): void
    {
        $product = $event->getObject();
        if (!$product instanceof Product) {
            return;
        }

        $this->skuGuard->applyNormalization($product);
        // Autosave szkicu w adminie działa co kilka sekund: bez wyskakujących tam błędów.
        if (!($event->getArguments()['isAutoSave'] ?? false)) {
            $this->skuGuard->assertValid($product);
            $this->fieldPlacementGuard->assertValid($product);
        }

        if ($product->getType() !== AbstractObject::OBJECT_TYPE_VARIANT) {
            $this->availabilityUpdater->applyToParent($product);
            $this->urlKeyGenerator->apply($product);

            return;
        }

        $this->availabilityUpdater->applyToVariant($product);
        $this->parentsToRefresh[spl_object_id($product)] = $this->affectedParents($product);
    }

    public function onPostSave(DataObjectEvent $event): void
    {
        $variant = $event->getObject();
        if (!$variant instanceof Product || $variant->getType() !== AbstractObject::OBJECT_TYPE_VARIANT) {
            return;
        }

        $parentIds = $this->parentsToRefresh[spl_object_id($variant)] ?? [];
        unset($this->parentsToRefresh[spl_object_id($variant)]);

        if ($event->getArguments()['saveVersionOnly'] ?? false) {
            return; // tylko szkic, opublikowane dane się nie zmieniają
        }

        $this->availabilityUpdater->refreshParents($parentIds);
    }

    public function onSaveFailure(DataObjectEvent $event): void
    {
        unset($this->parentsToRefresh[spl_object_id($event->getObject())]);
    }

    /**
     * Wariant wpływa na rodzica tylko przez "opublikowany ORAZ stock > 0". Porównuje to ze stanem zapisanym
     * w bazie (jedno zapytanie po PK) zamiast z flagami dirty: setPublished() oznacza pole jako zmienione nawet
     * przy tej samej wartości, a zapisy z wyłączoną detekcją zmian (np. przywrócenie z kosza) nie oznaczają nic.
     * Godzinny import zwykle zapisuje niezmieniony stock, więc zwykle zwraca [] i rodzic nie jest ruszany.
     *
     * @return int[]
     */
    private function affectedParents(Product $variant): array
    {
        $stored = $variant->getId() === null ? false : $this->db->fetchAssociative(
            'SELECT o.parentId, o.published, s.stock FROM objects o
             LEFT JOIN ' . ProductTables::store() . ' s ON s.oo_id = o.id
             WHERE o.id = ?',
            [$variant->getId()]
        );

        $parentId = (int) $variant->getParentId(); // wariant zawsze ma rodzica
        if ($stored === false) {
            return [$parentId]; // nowy lub przywrócony wariant
        }

        $oldParentId = (int) $stored['parentId'];
        if ($oldParentId !== $parentId) {
            return [$oldParentId, $parentId]; // przeniesiony do innego produktu
        }

        $countedBefore = (bool) $stored['published'] && (int) $stored['stock'] > 0;
        $countsNow = $variant->isPublished() && $variant->getAvailability() === Availability::InStock->value;

        return $countedBefore !== $countsNow ? [$parentId] : [];
    }

    /**
     * Przy usuwaniu całego rodzica Pimcore najpierw usuwa jego warianty (w transakcji rodzica);
     * rodzic może wtedy dostać jedną zbędną aktualizację availability tuż przed usunięciem swojego wiersza.
     */
    public function onPostDelete(DataObjectEvent $event): void
    {
        $variant = $event->getObject();
        if ($variant instanceof Product && $variant->getType() === AbstractObject::OBJECT_TYPE_VARIANT) {
            $this->availabilityUpdater->refreshParents([(int) $variant->getParentId()]);
        }
    }
}
