<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Console\Command;

use Muon\DevProfiler\Model\Store\RunStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Empties the run ring.
 */
class ProfileClearCommand extends Command
{
    /**
     * @param \Muon\DevProfiler\Model\Store\RunStore $store
     * @param string|null $name
     */
    public function __construct(
        private readonly RunStore $store,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('muon:profile:clear')
            ->setDescription('Delete every stored profiler run');

        parent::configure();
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) `$input` is fixed by the Command signature;
     * this command takes no argument or option.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('Removed %d stored run(s).', $this->store->clear()));

        return Command::SUCCESS;
    }
}
