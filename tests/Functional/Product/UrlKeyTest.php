<?php

declare(strict_types=1);

namespace App\Tests\Functional\Product;

class UrlKeyTest extends ProductTestCase
{
    public function testUrlKeyIsGeneratedFromTheNameInEveryLanguage(): void
    {
        $bed = $this->createParent('bed', 'BED-01', false, ['en' => 'Yellow Bed', 'pl' => 'Łóżko Żółte']);

        $bed = $this->reload($bed);
        self::assertSame('yellow-bed', $bed->getUrlKey('en'));
        self::assertSame('lozko-zolte', $bed->getUrlKey('pl'));
    }

    public function testBeforePublishingTheKeyFollowsTheName(): void
    {
        $bed = $this->createParent('bed', 'BED-01', false, ['en' => 'Yellow Bed']);

        $this->reload($bed)->setName('Yellow Bed XL', 'en')->save();

        self::assertSame('yellow-bed-xl', $this->reload($bed)->getUrlKey('en'));
    }

    public function testAManualKeyIsNeverOverwritten(): void
    {
        $bed = $this->createParent('bed', 'BED-01', false, ['en' => 'Yellow Bed', 'pl' => 'Łóżko']);

        $this->reload($bed)->setUrlKey('custom-bed', 'pl')->setName('Łóżko Duże', 'pl')->setName('Big Bed', 'en')->save();

        $bed = $this->reload($bed);
        self::assertSame('custom-bed', $bed->getUrlKey('pl'));
        self::assertSame('big-bed', $bed->getUrlKey('en'), 'the other language still follows its name');
    }

    public function testTheFirstPublishingSaveStillUsesTheNewNameThenTheKeyIsFrozen(): void
    {
        $bed = $this->createParent('bed', 'BED-01', false, ['en' => 'Yellow Bed']);

        $this->reload($bed)->setName('Yellow Bed King', 'en')->setPublished(true)->save();
        self::assertSame('yellow-bed-king', $this->reload($bed)->getUrlKey('en'));

        $this->reload($bed)->setName('Renamed Bed', 'en')->save();
        self::assertSame('yellow-bed-king', $this->reload($bed)->getUrlKey('en'));
    }

    public function testTheKeyStaysFrozenAfterUnpublishing(): void
    {
        $bed = $this->createParent('bed', 'BED-01', true, ['en' => 'Yellow Bed']);

        $this->reload($bed)->setPublished(false)->setName('Other Name', 'en')->save();

        self::assertSame('yellow-bed', $this->reload($bed)->getUrlKey('en'));
    }

    public function testEditorsCanStillChangeAPublishedKeyManually(): void
    {
        $bed = $this->createParent('bed', 'BED-01', true, ['en' => 'Yellow Bed']);

        $this->reload($bed)->setUrlKey('editor-choice', 'en')->save();

        self::assertSame('editor-choice', $this->reload($bed)->getUrlKey('en'));
    }

    public function testAnEmptyKeyIsStillFilledAfterPublishing(): void
    {
        $bed = $this->createParent('bed', 'BED-01', true, ['en' => 'Yellow Bed']);

        $this->reload($bed)->setName('Łóżko', 'pl')->save(); // język dodany po publikacji
        self::assertSame('lozko', $this->reload($bed)->getUrlKey('pl'));

        $this->reload($bed)->setName('Łóżko Nowe', 'pl')->save();
        self::assertSame('lozko', $this->reload($bed)->getUrlKey('pl'), 'frozen from now on');
    }

    public function testClearingTheNameOfADraftDoesNotFreezeTheKey(): void
    {
        $bed = $this->createParent('bed', 'BED-01', false, ['en' => 'Sofa']);

        $this->reload($bed)->setName(null, 'en')->save();
        $this->reload($bed)->setName('Chair', 'en')->save();

        self::assertSame('chair', $this->reload($bed)->getUrlKey('en'));
    }

    public function testANameWithoutSluggableCharactersGivesNoKey(): void
    {
        $bed = $this->createParent('bed', 'BED-01', false, ['en' => '!!!']);

        self::assertNull($this->reload($bed)->getUrlKey('en'));
    }

    public function testVariantsInheritTheKeyOfTheirParent(): void
    {
        $bed = $this->createParent('bed', 'BED-01', true, ['en' => 'Yellow Bed']);
        $variant = $this->createVariant($bed, 'oak', 'BED-01-OAK', 1);

        $inherited = \Pimcore\Model\DataObject\Service::useInheritedValues(true, fn () => $this->reload($variant)->getUrlKey('en'));

        self::assertSame('yellow-bed', $inherited);
    }
}
