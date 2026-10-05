<?php

declare(strict_types=1);

namespace App\Product\EventSubscriber;

use Pimcore\Event\BundleManager\PathsEvent;
use Pimcore\Event\BundleManagerEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Rejestruje skrypty panelu admina dla reguł produktu.
 */
final class AdminAssetsSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [BundleManagerEvents::JS_PATHS => 'addJsPaths'];
    }

    public function addJsPaths(PathsEvent $event): void
    {
        $event->addPaths(['/static/product/admin/reloadAfterSave.js']);
    }
}
