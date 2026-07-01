
# Upgrade Guide: Moving to Silverstripe CMS 6

This document outlines the necessary steps and breaking changes required to upgrade the `sunnysideup/versions-table-pruner` module for compatibility with Silverstripe CMS 6.

## ⚠️ BREAKING CHANGE: Core Dependency Updates

Your project's `composer.json` must be updated to require the correct major versions for Silverstripe 6.

-   **Silverstripe Framework**: The required version has been updated from `^5.0` to `^6.0`.
-   **Silverstripe Admin**: The required version has been updated from `^2.0` to `^3.0`.

```json
"require": {
    "silverstripe/framework": "^6.0",
    "silverstripe/admin": "^3.0"
}
```

## ⚠️ BREAKING CHANGE: BuildTask API and Command Execution

All `BuildTask` classes have been updated to use the new command execution structure introduced in Silverstripe 6. The legacy `run()` method is no longer supported.

-   Tasks now extend `SilverStripe\Dev\BuildTask` and implement the `execute(InputInterface $input, PolyOutput $output)` method.
-   The `$segment` property has been replaced with `$commandName`.
-   Output is now handled via the `PolyOutput` object (e.g., `$output->writeln('message')`) instead of `DB::alteration_message()`.

### Affected Tasks:

1.  **`DeleteOldVersionsOther`**
    -   **Old segment**: `delete-old-change-sets`
    -   **New command name**: `delete-old-change-sets`
    -   The `run($request)` method has been replaced with `execute(InputInterface $input, PolyOutput $output)`.

2.  **`DeleteOldVersionsPage`**
    -   **Old segment**: `delete-old-versions-page`
    -   **New command name**: `delete-old-versions-page`
    -   The `run($request)` method has been replaced with `execute(InputInterface $input, PolyOutput $output)`.
    -   All internal methods now accept a `PolyOutput $output` parameter for logging.

### Action Required:

Update any custom scripts, cron jobs, or direct calls that previously used the URL segment (`/dev/tasks/delete-old-versions-page`) to use the new console command:

```bash
vendor/bin/sake dev/tasks/delete-old-versions-page
vendor/bin/sake dev/tasks/delete-old-change-sets
```

## API Changes

-   **Typed Properties**: Class properties such as `$title` and `$description` in `BuildTask` descendants are now strictly typed (`string`).
-   **Method Signatures**: All private methods within `DeleteOldVersionsPage` that previously handled output now require a `PolyOutput $output` argument to be passed.
