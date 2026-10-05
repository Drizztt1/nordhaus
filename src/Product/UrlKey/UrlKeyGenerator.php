<?php

declare(strict_types=1);

namespace App\Product\UrlKey;

use Pimcore\Model\DataObject\Product;
use Pimcore\Model\DataObject\Service;
use Pimcore\Tool;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Dla każdego języka:
 * - pusty klucz URL               -> generowany z nazwy
 * - jeszcze nigdy nieopublikowany -> podąża za nazwą, chyba że edytor zmienił go ręcznie
 * - opublikowany choć raz         -> nigdy więcej nie zmienia się automatycznie (uzupełniany jest tylko pusty klucz)
 *
 * Stan "nadal automatyczny" jest trzymany w ukrytym lokalizowanym polu urlKeyAuto (ostatnia wygenerowana
 * wartość). Jest czyszczony, gdy tylko produkt zostanie zapisany jako opublikowany, co zamraża klucz
 * na stałe, nawet jeśli produkt zostanie później odpublikowany.
 */
final class UrlKeyGenerator
{
    public function __construct(private readonly SluggerInterface $slugger)
    {
    }

    public function apply(Product $product): void
    {
        Service::useInheritedValues(false, function () use ($product): void {
            foreach (Tool::getValidLanguages() as $language) {
                $this->applyForLanguage($product, $language);
            }
        });
    }

    private function applyForLanguage(Product $product, string $language): void
    {
        $generated = $this->slugify((string) $product->getName($language), $language);
        if ($generated === '') {
            // Brak nazwy (dającej slug) w tym języku: nic do wygenerowania, stan "automatyczny" bez zmian.
            if ($product->isPublished()) {
                $product->setUrlKeyAuto(null, $language);
            }

            return;
        }

        $current = $product->getUrlKey($language);
        $lastAuto = $product->getUrlKeyAuto($language);

        $isEmpty = $current === null || trim($current) === '';
        $isStillAutomatic = $lastAuto !== null && $current === $lastAuto;

        if ($isEmpty || $isStillAutomatic) {
            $product->setUrlKey($generated, $language);
            $current = $generated;
        }

        // Zapamiętuj wygenerowaną wartość tylko dopóki produkt nigdy nie był opublikowany.
        $product->setUrlKeyAuto(
            !$product->isPublished() && $current === $generated ? $generated : null,
            $language
        );
    }

    public function slugify(string $name, string $language): string
    {
        return $this->slugger->slug($name, '-', $language)->lower()->toString();
    }
}
