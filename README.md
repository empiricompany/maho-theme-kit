# Maho Theme Kit

![Maho Commerce](https://img.shields.io/badge/Maho_Commerce-theme_kit-orange)
![License](https://img.shields.io/badge/license-OSL--3.0-blue)
![PHP](https://img.shields.io/badge/php-%3E%3D8.5-8892BF)
![PHPStan Level](https://img.shields.io/badge/PHPStan-level%208-brightgreen)

**Template for Maho frontend theme packages** (`type: maho-module`). It ships the
tooling, the CI gates and an extension of the `dev:frontend:theme:build` CLI
command that adds a `--pack` flag — then you drop a theme into it and ship it.

A package built from this kit carries a theme (its `etc/theme.xml`, templates,
layouts, locales) under `app/design/frontend/<package>/<theme>/**` and its assets
under `public/skin/frontend/<package>/<theme>/**`, exactly where the Maho
composer plugin publishes them.

## Repository layout

```
maho-theme-kit/
├── .github/                       # CI gates (composer validate, lint, phpstan, syntax)
├── .editorconfig .gitattributes .gitignore
├── .php-cs-fixer.php .phpstan.dist.neon rector.php
├── composer.json
├── LICENSE.txt
├── README.md
└── lib/
    └── MahoCLI/
        └── Commands/
            ├── ThemeAssetsTrait.php
            └── ThemeBuildPack.php   # dev:frontend:theme:build, extended with --pack
```

The `app/design/**` and `public/skin/**` trees are **not** in the kit: they are
the theme you add when you generate a package.

## How the pieces resolve

- **Design** (`app/design/**`) is resolved **live from the package**: composer
  installs it from the `localdev/*` path repository as a symlink, so an edit in
  the package is served immediately — no build, no copy.
- **Assets** (`public/skin/**`) are copied **into the project's `public/` tree**
  by the Maho composer plugin on every `composer install/update` (no pruning).
  The storefront serves them from `public/`, not from the package.

## Development workflow

You edit the assets **in the project's published tree** (a real directory), where
the native `dev:frontend:theme:build --watch` works, and copy them back into the
package with `--pack` when the change is ready.

```bash
# 1. assets: edit + live rebuild, in the project's public/ tree
./vendor/bin/harbor maho dev:frontend:theme:build --theme lightclean/default --watch

# 2. design: edit in the package, served live
#    localdev/<package>/app/design/frontend/<package>/<theme>/...

# 3. before committing / a release: minified build, then copy back into the package
./vendor/bin/harbor maho dev:frontend:theme:build --theme lightclean/default --pack
```

`--watch --pack` rebuilds **and** copies on every change (one command, one
terminal):

```bash
./vendor/bin/harbor maho dev:frontend:theme:build --theme lightclean/default --watch --pack
```

> **Finish with a plain `build --pack` before committing.** The watch build emits
> an **unminified** bundle, while the committed `css/styles.css` is the
> **minified** release build: `--watch --pack` keeps replacing it with a debug
> bundle until you run a plain (minified) `build --pack`.

> **Order matters**: `composer install/update` re-copies the package's `public/`
> into the project, **overwriting** the project copies. Run `build --pack` before
> any `composer install/update`, or accept the overwrite.

### Why not symlink the assets?

Symlinking `public/skin/frontend/<pkg>/<theme>` to the package looks appealing
(one physical copy) but **breaks `--watch`**: Tailwind v4 watches the *realpath*
of the source directory yet matches change events against the *path as given*, so
with a symlinked theme it rebuilds but keeps re-emitting the initial snapshot.
Resolving the input to its realpath is not a fix either: the theme's relative
`@import "../../../base/default/src/tailwind.css"` then resolves inside the
package, where the parent engine does not exist. Working directly in the
published `public/` tree keeps both the imports and the watcher correct.

## `dev:frontend:theme:build --pack`

The command is the **core** `dev:frontend:theme:build`, re-registered by this
package: the package command is discovered after the core ones, so Symfony
replaces it by name. It only **adds** a `--pack` flag — there is no patch to the
Maho core, and without `--pack` the behaviour is identical to the core command.

| Option | Effect |
|---|---|
| `--pack` | after the build (or after **every** rebuild with `--watch`) copy the published assets back into the package and prune the removed files |
| `--theme <pkg>/<theme>` | limit the build to one theme (core option, forwarded) |
| `--watch` | rebuild on change (core option) |
| `--map` | inline source map (core option) |

The copy prints **every file** it copies or removes, with its **real destination**
inside the package, so you can see exactly what changed and where:

```
 copied   localdev/maho-lightclean-theme/public/skin/frontend/lightclean/default/css/styles.css
 removed  localdev/maho-lightclean-theme/public/skin/frontend/lightclean/default/src/old.css

 [OK] Packed: 1 file(s) copied, 1 removed.
```

Packages installed as a **vendor copy** (a distribution under `vendor/`, not a
development checkout) are **skipped**: packing into `vendor/` would be pointless,
since the next `composer install/update` rebuilds it.

It covers every directory the Maho composer plugin publishes, for the theme
leaf: `public/skin/frontend/<pkg>/<theme>` and `public/js/frontend/<pkg>/<theme>`
(from `public/**`, `skin/**` and `js/**`).

## Tooling

The standard Maho package gates (same as the module template):

- **PHPStan** (level 8) — `vendor/bin/phpstan analyze`
- **Rector** (dry-run) — `vendor/bin/rector --dry-run`
- **PHP CS Fixer** (dry-run) — `vendor/bin/php-cs-fixer fix --dry-run`
- **Composer validate**, **PHP/XML syntax**, **line endings** — on CI

```bash
./vendor/bin/harbor composer --working-dir=localdev/<package> check
```

## Requirements

- PHP >= 8.5
- Maho Commerce

---

## Using this template

When you create a new repository from this template:

1. **Rename placeholders** — find/replace `maho-theme-kit` and the placeholders in
   `composer.json` (`name`, `description`) and in this README (title, pitch,
   install command).
2. **Add your theme** under `app/design/frontend/<package>/<theme>/` and
   `public/skin/frontend/<package>/<theme>/` (see above).
3. **Update the badges** if you want CI status badges (the four static badges
   above are the org standard).
4. **Replace this section** of the README with the real theme docs once you have
   shipped something.
