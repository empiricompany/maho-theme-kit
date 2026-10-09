<?php

/**
 * `dev:frontend:theme:build`, extended with `--pack`.
 *
 * It re-registers the core command with the same name (the package command is
 * discovered after the core ones, so Symfony replaces it) and adds the ability to
 * copy the theme assets back into the package: once after a one-shot build, or
 * after every rebuild together with `--watch`.
 *
 * The core build (and its watch loop) is not reimplemented nor subclassed: the
 * core command instance is run through Symfony, which works whether the core
 * entry point is `__invoke()` or `execute()`. The copy logic lives in
 * ThemeAssetsTrait.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package MahoCLI
 */

declare(strict_types=1);

namespace MahoCLI\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'dev:frontend:theme:build',
    description: 'Compile the CSS sources (src/*.css) of every frontend theme into their css/ bundles (with --pack, copy the assets back into the package)',
)]
class ThemeBuildPack extends BaseMahoCommand
{
    use ThemeAssetsTrait;

    public function __invoke(
        OutputInterface $output,
        SymfonyStyle $io,
        #[Option(description: 'Limit the build to one theme instead of all, as package/theme (e.g. --theme base/pharmacy)', name: 'theme', shortcut: 't')]
        ?string $themeFilter = null,
        #[Option(description: 'Rebuild on change; output is unminified, run a plain build before committing', shortcut: 'w')]
        bool $watch = false,
        #[Option(description: 'Add an inline source map, so devtools show the src/ partials instead of the bundle', name: 'map')]
        bool $sourceMap = false,
        #[Option(description: 'Copy the assets back into the theme package: after the build, or after every rebuild together with --watch')]
        bool $pack = false,
    ): int {
        if (!$pack) {
            return $this->runCoreBuild($output, $themeFilter, $watch, $sourceMap);
        }

        if ($watch) {
            return $this->watchAndPack($output, $io, $themeFilter, $sourceMap);
        }

        $status = $this->runCoreBuild($output, $themeFilter, false, $sourceMap);
        if ($status !== Command::SUCCESS) {
            return $status;
        }

        $io->section('Packaging the theme assets into the package');
        return $this->packThemes($io, $themeFilter);
    }

    /**
     * Delegate to the core dev:frontend:theme:build by running its command
     * instance through Symfony, so the exact same build/watch behaviour is kept
     * without depending on how the core exposes its entry point.
     *
     * The helper set (for prompts) is shared, but the application is deliberately
     * not attached: attaching it would reset the invokable command's definition
     * and drop its options.
     */
    private function runCoreBuild(OutputInterface $output, ?string $themeFilter, bool $watch, bool $sourceMap): int
    {
        $params = [];
        if ($themeFilter !== null) {
            $params['--theme'] = $themeFilter;
        }
        if ($watch) {
            $params['--watch'] = true;
        }
        if ($sourceMap) {
            $params['--map'] = true;
        }

        $core = new FrontendThemeBuild();
        $helperSet = $this->getHelperSet();
        if ($helperSet !== null) {
            $core->setHelperSet($helperSet);
        }
        return $core->run(new ArrayInput($params), $output);
    }

    /**
     * The core watch loop blocks until Ctrl+C, so it cannot be followed by an
     * in-process copy. Run it as a child process and pack on every rebuild
     * (Tailwind prints `Done in …`), with no filesystem polling.
     */
    private function watchAndPack(OutputInterface $output, SymfonyStyle $io, ?string $themeFilter, bool $sourceMap): int
    {
        $root = \Maho::getBasePath();
        $build = [PHP_BINARY, $root . '/maho', 'dev:frontend:theme:build', '--watch'];
        if ($themeFilter !== null) {
            $build[] = '--theme';
            $build[] = $themeFilter;
        }
        if ($sourceMap) {
            $build[] = '--map';
        }

        $io->text([
            'Watching the theme build; copying the assets into the package on every rebuild.',
            'The watch output is unminified: run a plain build --pack before committing.',
            'Press Ctrl+C to stop.',
            '',
        ]);

        // No timeout: the watcher runs until the user stops it.
        $process = new Process($build, $root, null, null, null);
        $process->run(function (string $type, string $buffer) use ($output, $io, $themeFilter): void {
            foreach (preg_split('/\R/', trim($buffer)) ?: [] as $line) {
                if ($line !== '') {
                    $output->writeln($line);
                }
            }
            if (str_contains($buffer, 'Done in')) {
                $this->packThemes($io, $themeFilter);
            }
        });

        return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }
}
