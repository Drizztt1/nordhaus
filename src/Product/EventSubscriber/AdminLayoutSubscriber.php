<?php

declare(strict_types=1);

namespace App\Product\EventSubscriber;

use Pimcore\Bundle\AdminBundle\Event\AdminEvents;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\ClassDefinition\CustomLayout;
use Pimcore\Model\DataObject\Product;
use Pimcore\Model\DataObject\Service;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Pimcore przypisuje custom layouty do użytkowników/ról, ale nie do typu obiektu. Ten subscriber otwiera
 * rodzica w layoucie "product_parent" (bez stock/fabric/leg), a wariant w "product_variant" (name, description
 * i urlKey tylko do odczytu, bo są dziedziczone). Definicje layoutów: config/pimcore/custom-layouts/.
 */
final class AdminLayoutSubscriber implements EventSubscriberInterface
{
    public const PARENT_LAYOUT = 'product_parent';
    public const VARIANT_LAYOUT = 'product_variant';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [AdminEvents::OBJECT_GET_PRE_SEND_DATA => 'selectLayout'];
    }

    public function selectLayout(GenericEvent $event): void
    {
        $object = $event->getArgument('object');
        if (!$object instanceof Product) {
            return;
        }

        // Edytor wybrał layout ręcznie (przełącznik layoutów w adminie): szanujemy to.
        $requested = (string) $this->requestStack->getCurrentRequest()?->get('layoutId');
        if ($requested !== '' && !in_array($requested, [self::PARENT_LAYOUT, self::VARIANT_LAYOUT], true)) {
            return;
        }

        $layoutId = $object->getType() === AbstractObject::OBJECT_TYPE_VARIANT ? self::VARIANT_LAYOUT : self::PARENT_LAYOUT;
        $data = $event->getArgument('data');

        // Tylko layouty, do których użytkownik ma uprawnienia (workspace "layouts").
        if (!in_array($layoutId, array_column($data['validLayouts'] ?? [], 'id'), true)) {
            return;
        }

        $customLayout = CustomLayout::getById($layoutId);
        if ($customLayout === null) {
            return;
        }

        // Kopia: enrichLayoutDefinition modyfikuje drzewo (wartości, opcje), a definicja jest współdzielona.
        $layout = unserialize(serialize($customLayout->getLayoutDefinitions()));
        Service::enrichLayoutDefinition($layout, $object);

        $data['layout'] = $layout;
        $data['currentLayoutId'] = $layoutId;
        $event->setArgument('data', $data);
    }
}
