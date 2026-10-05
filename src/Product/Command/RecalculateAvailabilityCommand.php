<?php

declare(strict_types=1);

namespace App\Product\Command;

use App\Product\Availability\AvailabilityUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Początkowe wypełnienie po wdrożeniu pola "availability" oraz siatka bezpieczeństwa (np. nocny cron)
 * na rozjazdy wynikające z równoległych zapisów.
 *
 * Zbiorowy SQL zamiast 350k zapisów obiektów (minuty zamiast godzin); zapisywane i usuwane z cache są
 * tylko wiersze, których wartość faktycznie się zmienia, w paczkach.
 */
#[AsCommand(
    name: 'app:product:recalculate-availability',
    description: 'Recalculates the availability of all products and variants from stock'
)]
final class RecalculateAvailabilityCommand extends Command
{
    public function __construct(private readonly AvailabilityUpdater $availabilityUpdater)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Najpierw warianty: reguła rodzica czyta stock opublikowanych wariantów, a nie ich availability.
        $variants = $this->availabilityUpdater->refreshAllVariants();
        $parents = $this->availabilityUpdater->refreshParents();

        (new SymfonyStyle($input, $output))->success(
            sprintf('Availability changed for %d variants and %d parent products.', $variants, $parents)
        );

        return Command::SUCCESS;
    }
}
