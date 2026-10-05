<?php

declare(strict_types=1);

namespace App\Tests\Unit\Product;

use App\Product\Availability\Availability;
use App\Product\Sku\SkuGuard;
use App\Product\UrlKey\UrlKeyGenerator;
use Codeception\Test\Unit;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Tylko czyste reguły. Cykl życia w Pimcore (zdarzenia, baza, dziedziczenie) pokrywają testy
 * integracyjne w tests/Functional/Product, uruchamiane na prawdziwej bazie testowej.
 */
class ProductRulesTest extends Unit
{
    /**
     * @dataProvider skuProvider
     */
    public function testSkuIsTrimmedAndUppercased(?string $input, ?string $expected): void
    {
        self::assertSame($expected, SkuGuard::normalize($input));
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function skuProvider(): iterable
    {
        yield 'trim + uppercase' => ['  sofa-01 ', 'SOFA-01'];
        yield 'already normalised' => ['SOFA-01', 'SOFA-01'];
        yield 'multibyte' => ['sofa-łódź', 'SOFA-ŁÓDŹ'];
        yield 'whitespace only becomes null' => ["  \t ", null];
        yield 'non-breaking spaces (copy-paste from Excel)' => ["\u{00A0}nbsp-1\u{00A0}", 'NBSP-1'];
        yield 'zero-width no-break space' => ["\u{FEFF}bom-1", 'BOM-1'];
        yield 'null stays null' => [null, null];
    }

    /**
     * @dataProvider stockProvider
     */
    public function testVariantAvailabilityFromStock(?int $stock, Availability $expected): void
    {
        self::assertSame($expected, Availability::fromStock($stock));
    }

    /** @return iterable<string, array{?int, Availability}> */
    public static function stockProvider(): iterable
    {
        yield 'positive' => [3, Availability::InStock];
        yield 'zero' => [0, Availability::OutOfStock];
        yield 'negative (oversold in ERP)' => [-2, Availability::OutOfStock];
        yield 'not set' => [null, Availability::OutOfStock];
    }

    /**
     * @dataProvider slugProvider
     */
    public function testUrlKeySlug(string $name, string $language, string $expected): void
    {
        $generator = new UrlKeyGenerator(new AsciiSlugger());

        self::assertSame($expected, $generator->slugify($name, $language));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function slugProvider(): iterable
    {
        yield 'en' => ['Comfort Sofa 3-Seater', 'en', 'comfort-sofa-3-seater'];
        yield 'pl diacritics' => ['Sofa Łódź Żółta', 'pl', 'sofa-lodz-zolta'];
        yield 'punctuation, locale-aware symbols' => ['Sofa "Nordic" (oak) & linen!', 'en', 'sofa-nordic-oak-and-linen'];
    }
}
