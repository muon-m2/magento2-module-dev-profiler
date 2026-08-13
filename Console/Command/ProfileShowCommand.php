<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Console\Command;

use Magento\Framework\Serialize\Serializer\Json;
use Muon\DevProfiler\Model\Report\RunRenderer;
use Muon\DevProfiler\Model\Store\RunStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shows one profiled run.
 *
 * With no token it shows the last full document request rather than the last request outright. A
 * storefront page fires customer-section XHRs immediately behind it, so the newest run is almost
 * never the page you just loaded — and "the page I just loaded" is what somebody means when they
 * do not say.
 */
class ProfileShowCommand extends Command
{
    private const ARG_TOKEN = 'token';
    private const OPT_JSON = 'json';
    private const OPT_SHADOWED = 'shadowed-only';
    private const OPT_FILTER = 'fallback';
    private const OPT_ANY = 'any';
    private const OPT_SQL = 'sql';
    private const OPT_SLOW = 'slow-query';
    private const OPT_NPLUS1 = 'nplus1';
    private const OPT_DUPLICATE = 'duplicate';

    /**
     * @param \Muon\DevProfiler\Model\Store\RunStore $store
     * @param \Muon\DevProfiler\Model\Report\RunRenderer $renderer
     * @param \Magento\Framework\Serialize\Serializer\Json $json
     * @param string|null $name
     */
    public function __construct(
        private readonly RunStore $store,
        private readonly RunRenderer $renderer,
        private readonly Json $json,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('muon:profile:show')
            ->setDescription('Show a profiled storefront run (defaults to the last full page request)')
            ->addArgument(self::ARG_TOKEN, InputArgument::OPTIONAL, 'Run token; omit for the most recent')
            ->addOption(self::OPT_JSON, null, InputOption::VALUE_NONE, 'Emit the stored document as JSON')
            ->addOption(self::OPT_SHADOWED, null, InputOption::VALUE_NONE, 'Only show files with shadowed copies')
            ->addOption(self::OPT_FILTER, null, InputOption::VALUE_REQUIRED, 'Only show files matching this substring')
            ->addOption(self::OPT_ANY, null, InputOption::VALUE_NONE, 'Include AJAX runs when picking the latest')
            ->addOption(self::OPT_SQL, null, InputOption::VALUE_NONE, 'Show the SQL section')
            ->addOption(self::OPT_SLOW, null, InputOption::VALUE_REQUIRED, 'Slow-statement threshold in ms', '50')
            ->addOption(self::OPT_NPLUS1, null, InputOption::VALUE_REQUIRED, 'Executions before a shape is called an N+1', '5')
            ->addOption(self::OPT_DUPLICATE, null, InputOption::VALUE_REQUIRED, 'Executions before a shape is called a duplicate', '3');

        parent::configure();
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token = $input->getArgument(self::ARG_TOKEN);
        $run = $this->resolve(is_string($token) ? $token : null, (bool)$input->getOption(self::OPT_ANY));

        if ($run === null) {
            // An unknown token is a mistake worth an exit code; an empty ring is just a state.
            if (is_string($token) && $token !== '') {
                $output->writeln(sprintf('<error>No run found for token "%s".</error>', $token));

                return Command::FAILURE;
            }

            $output->writeln('No runs recorded yet. Load a storefront page in developer mode first.');

            return Command::SUCCESS;
        }

        if ($input->getOption(self::OPT_JSON)) {
            $output->writeln((string)$this->json->serialize($run));

            return Command::SUCCESS;
        }

        $filter = $input->getOption(self::OPT_FILTER);

        $output->writeln('');

        foreach ($this->renderer->render(
            $run,
            (bool)$input->getOption(self::OPT_SHADOWED),
            is_string($filter) && $filter !== '' ? $filter : null,
            (bool)$input->getOption(self::OPT_SQL),
            $this->thresholds($input)
        ) as $line) {
            $output->writeln($line);
        }

        $output->writeln('');

        return Command::SUCCESS;
    }

    /**
     * Read-time SQL thresholds.
     *
     * Deliberately flags rather than configuration: a capture taken hours ago can be re-examined
     * at a different sensitivity without re-running the page.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @return array<string, mixed>
     */
    private function thresholds(InputInterface $input): array
    {
        return [
            'slow_ms' => (float)$this->numeric($input, self::OPT_SLOW, 50),
            'nplus1' => (int)$this->numeric($input, self::OPT_NPLUS1, 5),
            'duplicate' => (int)$this->numeric($input, self::OPT_DUPLICATE, 3),
        ];
    }

    /**
     * A numeric option, falling back to its default rather than throwing on nonsense.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param string $name
     * @param float|int $default
     * @return float
     */
    private function numeric(InputInterface $input, string $name, float|int $default): float
    {
        $value = $input->getOption($name);

        return is_numeric($value) ? (float)$value : (float)$default;
    }

    /**
     * @param string|null $token
     * @param bool $any
     * @return array<string, mixed>|null
     */
    private function resolve(?string $token, bool $any): ?array
    {
        if ($token !== null && $token !== '') {
            return $this->store->load($token);
        }

        return $any ? $this->store->loadLast() : $this->store->loadLastDocument();
    }
}
