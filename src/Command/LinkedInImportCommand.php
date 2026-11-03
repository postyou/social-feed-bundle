<?php

declare(strict_types=1);

/*
 * social feed bundle for Contao Open Source CMS
 *
 * Copyright (c) 2024 pdir / digital agentur // pdir GmbH
 *
 * @package    social-feed-bundle
 * @link       https://github.com/pdir/social-feed-bundle
 * @license    http://www.gnu.org/licences/lgpl-3.0.html LGPL
 * @author     Mathias Arzberger <develop@pdir.de>
 * @author     Philipp Seibt <develop@pdir.de>
 * @author     pdir GmbH <https://pdir.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Pdir\SocialFeedBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Pdir\SocialFeedBundle\Importer\LinkedIn;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
#[AsCommand(
    name: 'social-feed:linkedin:import',
    description: 'Import LinkedIn posts from API.',
    aliases: ['sf:linkedin']
)]
/**
 * Import LinkedIn posts.
 */
class LinkedInImportCommand extends Command
{
    public function __construct(private ContaoFramework $framework)
    {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->addOption('max-posts', 'm', InputOption::VALUE_REQUIRED, 'The maximum number of posts to execute (default 100)', '100');
        $this->addOption('enable-debug', 'd', InputArgument::OPTIONAL, 'Log debug information to console');
        $this->addOption('no-documents', null, InputOption::VALUE_NONE, 'Do not import PDF documents of document posts');
        $this->addOption('backfill-documents', 'b', InputOption::VALUE_NONE, 'Import PDF documents for already imported posts');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->framework->initialize();

        $output->writeln('Social Feed: Run LinkedIn import ...');

        try {
            $importer = new LinkedIn();
            $importer->setIgnoreInterval(true);
            $importer->setDebugMode((bool) $input->getOption('enable-debug'));
            $importer->setMaxPosts((int) $input->getOption('max-posts') ?: 100);
            $importer->setImportDocuments(!$input->getOption('no-documents'));
            $importer->setBackfillDocuments((bool) $input->getOption('backfill-documents'));
            $importer->import();
        } catch (InvalidArgumentException $e) {
            $output->writeln(sprintf('%s (see help social-feed:linkedin:import).', $e->getMessage()));

            return Command::FAILURE;
        }

        if (0 < $importer->counter) {
            $output->writeln('... imported '.$importer->counter.' items.');
        }

        if (0 < $importer->documentCounter) {
            $output->writeln('... imported '.$importer->documentCounter.' documents.');
        }

        if (0 === $importer->counter && 0 === $importer->documentCounter) {
            $output->writeln('... nothing to import');
        }

        return Command::SUCCESS;
    }
}
