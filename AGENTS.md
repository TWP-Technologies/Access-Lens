# Repository Guidelines

## Interaction & Reasoning Protocol

Unless directed otherwise, engage as a rigorous sparring partner: inspect every assumption, surface counterpoints, stress-test logic, propose alternative frames, and prioritize truth over agreement. If confirmation bias or unexamined premises appear, flag them explicitly while keeping feedback constructive.

## Project Structure & Module Organization

This WordPress plugin boots from `protected-media-links.php`, which registers hooks and loads classes enumerated in `includes/pml-class-map.php`. Core PHP modules sit in `includes/class-*.php` plus utilities under `includes/utilities/`. Admin-facing assets ship from `admin/` with compiled React bundles in `admin/assets/js/gutenberg-integration-react/`; the JSX sources live in `build/gutenberg-integration-react/`. Place smoke tests in `tests/`, translations in `languages/`, Composer output in `vendor/`, and rerun `php build/generate-class-map.php` after adding or renaming classes.

## Build, Test, and Development Commands

When running package scripts, prefer bun first, pnpm second, npm last; document repository commits into memory for future reference. Run `bun install`. Use `bun run start` for a watch build and `bun run build` for production bundles. `bun run lint:js` applies the WordPress ESLint rules. For PHP tooling, run `composer install`, then `vendor/bin/phpcs --standard=WordPress`. Refresh the class map with `php build/generate-class-map.php` whenever service files move.

## Coding Style & Naming Conventions

Adhere to WordPress PHP Coding Standards: four-space indentation, snake*case functions such as `pml_register_routes`, and PascalCase classes prefixed `PML*`. File names in `includes/` stay dash-separated (`class-token-manager.php`). Wrap translatable strings in `esc_html**`/`**`with the plugin text domain. JavaScript follows ESNext conventions via`@wordpress/scripts`; prefer function components, hooks, and kebab-case filenames.

## Testing Guidelines

Keep `php tests/sanitize_location_test.php` green and model new procedural tests after it (name `*_test.php`, exit non-zero on failure). When introducing substantial JS logic, add Jest coverage under `tests/js/` and wire it into `npm test`; raise an issue if new tooling is required. Target coverage for authentication and token-signing paths.

## Commit & Pull Request Guidelines

History favors Conventional Commit prefixes (`refactor: tighten token limits (#41)`). Write imperative subjects, reference issues or PRs, and group related changes. Pull requests should summarize impact, document manual verification, attach UI screenshots when admin views shift, highlight migrations or cache flushes, and confirm linters/tests passed before requesting review.
