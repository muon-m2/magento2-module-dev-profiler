<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Console\Command;

use Muon\DevProfiler\Model\Analysis\CacheVerdict;
use Muon\DevProfiler\Model\Store\RunStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists recent profiled runs, newest first.
 */
class ProfileListCommand extends Command
{
    private const OPT_LIMIT = 'limit';

    /**
     * @param \Muon\DevProfiler\Model\Store\RunStore $store
     * @param \Muon\DevProfiler\Model\Analysis\CacheVerdict $verdicts
     * @param string|null $name
     */
    public function __construct(
        private readonly RunStore $store,
        private readonly CacheVerdict $verdicts,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('muon:profile:list')
            ->setDescription('List recent profiled storefront runs')
            ->addOption(self::OPT_LIMIT, null, InputOption::VALUE_REQUIRED, 'How many to show', '20');

        parent::configure();
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runs = $this->store->list((int)$input->getOption(self::OPT_LIMIT));

        if ($runs === []) {
            $output->writeln('No runs recorded yet. Load a storefront page in developer mode first.');

            return Command::SUCCESS;
        }

        $output->writeln('');

        foreach ($runs as $run) {
            $request = is_array($run['request'] ?? null) ? $run['request'] : [];
            $context = is_array($run['context'] ?? null) ? $run['context'] : [];
            $layout = is_array($run['layout'] ?? null) ? $run['layout'] : [];
            $verdict = $this->verdicts->verdict($layout, (string)($request['kind'] ?? 'page'));

            $output->writeln(sprintf(
                '  %-14s %-5s %-11s %-6s %-46s %s',
                (string)($run['token'] ?? '?'),
                (string)($request['method'] ?? '?'),
                (string)$verdict['status'],
                (string)($context['store_code'] ?? '?'),
                $this->truncate((string)($request['url'] ?? '?'), 46),
                !empty($request['is_ajax']) ? '[ajax]' : ''
            ));
        }

        $output->writeln('');

        return Command::SUCCESS;
    }

    /**
     * @param string $value
     * @param int $length
     * @return string
     */
    private function truncate(string $value, int $length): string
    {
        return strlen($value) > $length ? substr($value, 0, $length - 1) . '…' : $value;
    }
}
