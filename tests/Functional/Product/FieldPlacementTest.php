<?php

declare(strict_types=1);

namespace App\Tests\Functional\Product;

use App\Product\EventSubscriber\AdminLayoutSubscriber;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\ClassDefinition\Layout;
use Pimcore\Model\DataObject\Product;
use Pimcore\Model\Element\ValidationException;
use Pimcore\Model\User;
use Pimcore\Security\User\User as SecurityUser;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Kolumna "Where it is filled" z briefu: backend odrzuca wartości w złym miejscu (także import/API),
 * a w panelu admina rodzic i wariant dostają własne custom layouty.
 */
class FieldPlacementTest extends ProductTestCase
{
    public function testVariantCannotOverrideInheritedLocalizedFields(): void
    {
        $sofa = $this->createParent('sofa', 'SOFA-01', true, ['en' => 'Comfort Sofa']);
        $variant = $this->createVariant($sofa, 'velvet', 'SOFA-01-V', 1);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Fields "name (pl)", "description (en)" are inherited from the parent product');

        $this->reload($variant)->setName('Sofa welurowa', 'pl')->setDescription('<p>Velvet</p>', 'en')->save();
    }

    public function testVariantInheritsNameAndUrlKeyFromParent(): void
    {
        $sofa = $this->createParent('sofa', 'SOFA-01', true, ['en' => 'Comfort Sofa']);
        $variant = $this->createVariant($sofa, 'velvet', 'SOFA-01-V', 1);

        $variant = $this->reload($variant);
        self::assertSame('Comfort Sofa', \Pimcore\Model\DataObject\Service::useInheritedValues(true, fn () => $variant->getName('en')));
        self::assertSame('comfort-sofa', \Pimcore\Model\DataObject\Service::useInheritedValues(true, fn () => $variant->getUrlKey('en')));
    }

    public function testParentCannotHaveVariantOnlyFields(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Fields "stock", "fabric" can only be set on variants');

        Product::create([
            'key' => 'sofa',
            'parentId' => $this->folder->getId(),
            'sku' => 'SOFA-01',
            'stock' => 5,
            'fabric' => 'velvet',
            'published' => true,
        ])->save();
    }

    public function testRejectedParentSaveLeavesStoredDataUnchanged(): void
    {
        $sofa = $this->createParent('sofa', 'SOFA-01');

        try {
            $this->reload($sofa)->setLeg('oak')->save();
            self::fail('Parent with a variant-only field was saved.');
        } catch (ValidationException) {
        }

        self::assertNull($this->reload($sofa)->getLeg());
    }

    public function testEmptyValuesAreAllowedEverywhere(): void
    {
        $sofa = $this->createParent('sofa', 'SOFA-01');
        $variant = $this->createVariant($sofa, 'velvet', 'SOFA-01-V', 0);

        $this->reload($variant)->setName('', 'en')->setDescription('<p></p>', 'en')->save();
        $this->reload($sofa)->setStock(null)->save();

        self::assertNull($this->reload($sofa)->getStock());
    }

    public function testAdminOpensParentAndVariantInTheirOwnLayouts(): void
    {
        $sofa = $this->createParent('sofa', 'SOFA-01', true, ['en' => 'Comfort Sofa']);
        $variant = $this->createVariant($sofa, 'velvet', 'SOFA-01-V', 1);

        $parentData = $this->openInAdmin($sofa);
        self::assertSame(AdminLayoutSubscriber::PARENT_LAYOUT, $parentData['currentLayoutId']);
        self::assertNull($this->findField($parentData['layout'], 'stock'), 'no variant-only fields on the parent');
        self::assertFalse($this->findField($parentData['layout'], 'name')?->getNoteditable());

        $variantData = $this->openInAdmin($variant);
        self::assertSame(AdminLayoutSubscriber::VARIANT_LAYOUT, $variantData['currentLayoutId']);
        foreach (['name', 'description', 'urlKey'] as $inherited) {
            self::assertTrue($this->findField($variantData['layout'], $inherited)?->getNoteditable(), "$inherited is read-only on a variant");
        }
        self::assertFalse($this->findField($variantData['layout'], 'stock')?->getNoteditable());
    }

    public function testManuallyChosenLayoutIsRespected(): void
    {
        $sofa = $this->createParent('sofa', 'SOFA-01');

        $data = $this->openInAdmin($sofa, requestedLayoutId: '0');

        self::assertSame('0', $data['currentLayoutId']);
    }

    /**
     * Symuluje DataObjectController::getAction: dane z layoutem głównym i listą dostępnych layoutów.
     *
     * @return array<string, mixed>
     */
    private function openInAdmin(Product $product, string $requestedLayoutId = ''): array
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request(['layoutId' => $requestedLayoutId]));

        // Panel admina zawsze ma zalogowanego użytkownika (enrichLayoutDefinition sprawdza jego uprawnienia).
        $admins = (new User\Listing())->setCondition("admin = 1 AND type = 'user' AND name != 'system'")->load();
        $admin = $admins[0] ?? self::fail('No admin user in the test DB.');
        $tokenStorage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);
        $tokenStorage->setToken(new UsernamePasswordToken(new SecurityUser($admin), 'pimcore_admin', ['ROLE_PIMCORE_ADMIN']));

        $event = new GenericEvent(null, [
            'object' => $product,
            'data' => [
                'layout' => $product->getClass()->getLayoutDefinitions(),
                'currentLayoutId' => '0',
                'validLayouts' => [
                    ['id' => '0', 'name' => 'Main'],
                    ['id' => AdminLayoutSubscriber::PARENT_LAYOUT, 'name' => 'Product – parent'],
                    ['id' => AdminLayoutSubscriber::VARIANT_LAYOUT, 'name' => 'Product – variant'],
                ],
            ],
        ]);
        (new AdminLayoutSubscriber($requestStack))->selectLayout($event);

        return $event->getArgument('data');
    }

    private function findField(mixed $node, string $name): ?Data
    {
        if ($node instanceof Data && $node->getName() === $name) {
            return $node;
        }
        if ($node instanceof Layout || $node instanceof Data\Localizedfields) {
            foreach ($node->getChildren() as $child) {
                if ($found = $this->findField($child, $name)) {
                    return $found;
                }
            }
        }

        return null;
    }
}
