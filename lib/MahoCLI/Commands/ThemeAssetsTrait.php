<?php

/**
 * Locates the themes shipped by installed packages and the asset leaves the Maho
 * composer plugin publishes for them, and mirrors them from the project's public/
 * tree back into the package (used by `dev:frontend:theme:build --pack`).
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package MahoCLI
 */

declare(strict_types=1);

namespace MahoCLI\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

trait ThemeAssetsTrait
{
    /**
     * The asset leaves a theme can ship, as [package source, project destination]
     * pairs, relative to their own root.
     *
     * It mirrors what Maho\ComposerPlugin\FileCopyPlugin publishes for a
     * maho-module package:
     *
     *     public/**  -> public/**
     *     skin/**    -> public/skin/**
     *     js/**      -> public/js/**
     *
     * restricted to the theme leaf frontend/<designPackage>/<theme>. The first
     * form (under public/) is the one this kit uses; the second is the legacy
     * top-level form the plugin also supports. Both map to the same project
     * destination, so callers must de-duplicate by destination.
     *
     * @return list<array{0: string, 1: string}>
     */
    protected function themeAssetPairs(string $designPackage, string $theme): array
    {
        $leaf = "frontend/{$designPackage}/{$theme}";

        return [
            ["public/skin/{$leaf}", "public/skin/{$leaf}"],
            ["skin/{$leaf}", "public/skin/{$leaf}"],
            ["public/js/{$leaf}", "public/js/{$leaf}"],
            ["js/{$leaf}", "public/js/{$leaf}"],
        ];
    }

    /**
     * Every theme shipped by an installed maho-module (or magento-module)
     * package, keyed as "<designPackage>/<theme>".
     *
     * The maho-source packages and the root are skipped: they are not theme
     * distributions and their frontend trees are the core ones.
     *
     * @return array<string, array{name: string, path: string, design: string, theme: string}>
     */
    protected function installedThemes(?string $packageFilter = null): array
    {
        $bases = [
            'public/skin/frontend',
            'skin/frontend',
            'public/js/frontend',
            'js/frontend',
        ];

        $themes = [];
        foreach (\Maho::getInstalledPackages() as $name => $info) {
            if ($name === 'root' || ($info['type'] ?? '') === 'maho-source') {
                continue;
            }
            if ($packageFilter !== null && $name !== $packageFilter) {
                continue;
            }

            $path = rtrim($info['path'], '/');
            foreach ($bases as $base) {
                foreach (glob("{$path}/{$base}/*/*", GLOB_ONLYDIR) ?: [] as $dir) {
                    $theme = basename($dir);
                    $design = basename(dirname($dir));
                    $themes["{$design}/{$theme}"] ??= [
                        'name' => $name,
                        'path' => $path,
                        'design' => $design,
                        'theme' => $theme,
                    ];
                }
            }
        }

        return $themes;
    }

    /**
     * Validate a "<designPackage>/<theme>" filter.
     */
    protected function isValidThemeFilter(string $theme): bool
    {
        return (bool) preg_match('#^[a-z0-9_-]+/[a-z0-9_-]+$#i', $theme);
    }

    /**
     * Copy the published theme assets back into their packages (project ->
     * package), pruning the files removed from the project. Every copied and
     * removed file is printed with its real destination; packages installed as
     * vendor copies (a distribution, not a development checkout) are skipped.
     */
    protected function packThemes(SymfonyStyle $io, ?string $themeFilter = null): int
    {
        if ($themeFilter !== null && !$this->isValidThemeFilter($themeFilter)) {
            $io->error('Pass --theme as package/theme, for example --theme lightclean/default.');
            return Command::FAILURE;
        }

        $themes = $this->installedThemes();
        if ($themeFilter !== null) {
            $themes = isset($themes[$themeFilter]) ? [$themeFilter => $themes[$themeFilter]] : [];
        }
        if ($themes === []) {
            $io->error($themeFilter !== null
                ? "No installed package ships the theme '{$themeFilter}'."
                : 'No installed package ships a frontend theme.');
            return Command::FAILURE;
        }

        $copied = 0;
        $deleted = 0;
        /** @var array<string, bool> $seen */
        $seen = [];

        foreach ($themes as $info) {
            if ($this->isVendorCopy($info['path'])) {
                $io->warning("{$info['name']}: installed as a vendor copy ({$this->displayPath($info['path'])}); --pack applies to a development checkout only, skipping.");
                continue;
            }

            foreach ($this->themeAssetPairs($info['design'], $info['theme']) as [$srcRel, $destRel]) {
                $pkgAbs = "{$info['path']}/{$srcRel}";
                if (!is_dir($pkgAbs)) {
                    continue;
                }

                $rootAbs = \Maho::getBasePath() . '/' . $destRel;
                if (isset($seen[$destRel])) {
                    continue;
                }
                $seen[$destRel] = true;

                if (!file_exists($rootAbs)) {
                    $io->warning("{$destRel} is not published in the project: nothing to pack.");
                    continue;
                }

                if (is_link($rootAbs)) {
                    $io->warning("{$destRel} is a symlink: pack expects a real directory, skipping it.");
                    continue;
                }

                if (realpath($rootAbs) === realpath($pkgAbs)) {
                    $io->text("skip     {$destRel} (source and destination are the same)");
                    continue;
                }

                $stats = $this->mirrorTree($rootAbs, $pkgAbs, true);
                foreach ($stats['copied'] as $file) {
                    $io->text("<info>copied</info>   {$this->displayPath("{$pkgAbs}/{$file}")}");
                }
                foreach ($stats['deleted'] as $file) {
                    $io->text("<comment>removed</comment>  {$this->displayPath("{$pkgAbs}/{$file}")}");
                }
                if ($stats['copied'] === [] && $stats['deleted'] === []) {
                    $io->text("up-to-date {$this->displayPath($pkgAbs)}");
                }

                $copied += count($stats['copied']);
                $deleted += count($stats['deleted']);
            }
        }

        $io->success("Packed: {$copied} file(s) copied, {$deleted} removed.");
        return Command::SUCCESS;
    }

    /**
     * Whether the package directory is a Composer vendor copy (installed from a
     * distribution) rather than a development checkout. getInstalledPackages()
     * resolves symlinks, so a path-repository checkout lands in the project
     * (e.g. localdev/<pkg>) while a dist install lands under vendor/: packing
     * into a vendor copy would be pointless (the next Composer run rebuilds it).
     */
    private function isVendorCopy(string $packagePath): bool
    {
        return str_contains(rtrim($packagePath, '/') . '/', '/vendor/');
    }

    /**
     * The project-relative path when inside the project, the absolute path otherwise.
     */
    private function displayPath(string $absolute): string
    {
        $base = \Maho::getBasePath() . '/';
        return str_starts_with($absolute, $base) ? substr($absolute, strlen($base)) : $absolute;
    }

    /**
     * Mirror $src into $dst, copying new/changed files and (when $delete) removing
     * the files that only exist in $dst. Native PHP: the kit needs no extra
     * dependency beyond what Maho already ships.
     *
     * @return array{copied: list<string>, deleted: list<string>}
     */
    private function mirrorTree(string $src, string $dst, bool $delete): array
    {
        $copied = [];
        $deleted = [];

        foreach ($this->filesMap($src) as $relative => $hash) {
            $target = "{$dst}/{$relative}";
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            if (!is_file($target) || (md5_file($target) ?: '') !== $hash) {
                copy("{$src}/{$relative}", $target);
                $copied[] = $relative;
            }
        }

        if ($delete) {
            foreach (array_keys($this->filesMap($dst)) as $relative) {
                if (!is_file("{$src}/{$relative}")) {
                    unlink("{$dst}/{$relative}");
                    $deleted[] = $relative;
                }
            }
        }

        sort($copied);
        sort($deleted);

        return ['copied' => $copied, 'deleted' => $deleted];
    }

    /**
     * @return array<string, string> relative path => content hash
     */
    private function filesMap(string $dir): array
    {
        $map = [];
        if (!is_dir($dir)) {
            return $map;
        }

        $prefix = rtrim($dir, '/') . '/';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $relative = substr($item->getPathname(), strlen($prefix));
            if (str_starts_with($relative, '.git/') || str_contains($relative, '/.git/')) {
                continue;
            }
            $map[$relative] = md5_file($item->getPathname()) ?: '';
        }

        return $map;
    }
}
