<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Thanks\Command;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\Util\RemoteFilesystem;
use Composer\Factory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 */
class ThanksCommand extends BaseCommand
{
    protected function configure()
    {
        $this->setName('thanks')
            ->setDescription('Give thanks (in the form of github ★) to your fellow PHP package maintainers.')
            ->setDefinition([
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Don\'t send the stars actually'),
            ])
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();
        $repo = $composer->getRepositoryManager()->getLocalRepository();

        $urls = [];
        foreach ($repo->getPackages() as $package) {
            switch ($package->getType()) {
                case 'composer-plugin':
                case 'metapackage':
                case 'symfony-pack':
                    // Skip non-code depencencies
                    continue 2;
            }
            if ($url = $package->getSourceUrl()) {
                $urls[$package->getName()] = $url;
            }
        }
        ksort($urls);

        $rfs = Factory::createRemoteFilesystem($this->getIo(), $composer->getConfig());

        $i = 0;
        $template ='_%d: repository(owner:"%s",name:"%s"){id,viewerHasStarred}'."\n";
        $graphql = sprintf($template, ++$i, 'symfony', 'symfony');
        $aliases = ['_1' => ['symfony/symfony', 'https://github.com/symfony/symfony']];

        foreach ($urls as $package => $url) {
            if (preg_match('#^https://github.com/([^/]++)/([^./]++)#', $url, $url)) {
                $graphql .= sprintf($template, ++$i, $url[1], $url[2]);
                $aliases['_'.$i] = [$package, $url[0]];
            }
        }

        $repos = $this->callGithub($rfs, sprintf("query{\n%s}", $graphql));

        $template ='%1$s: addStar(input:{clientMutationId:"%s",starrableId:"%s"}){clientMutationId}'."\n";
        $graphql = '';
        $notStarred = [];

        foreach ($repos as $alias => $repo) {
            if (!$repo['viewerHasStarred']) {
                $graphql .= sprintf($template, $alias, $repo['id']);
                $notStarred[$alias] = $repo;
            }
        }

        if (!$notStarred) {
            $output->writeln('You already starred all your GitHub dependencies.');
        } else {
            if (!$input->getOption('dry-run')) {
                $repos = $this->callGithub($rfs, sprintf("mutation{\n%s}", $graphql));
            } else {
                $repos = $notStarred;
            }

            $output->writeln('Stars sent to:');
            foreach ($repos as $alias => $mutation) {
                $output->writeln(sprintf('  ★ <comment>%s</> - %s', $aliases[$alias][0], $aliases[$alias][1]));
            }
        }

        $output->writeln("\nThanks to you! 💖");

        return 0;
    }

    private function callGitHub(RemoteFilesystem $rfs, $graphql)
    {
        $result = $rfs->getContents('github.com', 'https://api.github.com/graphql', false, [
            'http' => [
                'method' => 'POST',
                'content' => json_encode(['query' => $graphql]),
            ],
        ]);
        $result = json_decode($result, true);

        return $result['data'];
    }
}
