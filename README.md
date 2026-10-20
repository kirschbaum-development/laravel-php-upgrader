# Laravel PHP Upgrader

A PHP CLI for checking installations across hosting providers and applying selected patch updates, one server at a time. Run it with cpx. Interactive selection and confirmation use Laravel Prompts.

The first provider is **Laravel Forge**, using its current organization-scoped API. The provider contract also supports platforms such as Laravel Cloud. A Cloud adapter is not included yet: Cloud manages environments rather than user-managed servers, so its update mechanism needs a separate implementation.

## Run with cpx (recommended)

Install [cpx](https://cpx.dev/) once, then run the package without adding it to your project or global Composer dependencies:

```bash
cpx kirschbaum-development/laravel-php-upgrader
cpx kirschbaum-development/laravel-php-upgrader scan
```

The first command opens the interactive upgrade flow. The second only inspects servers. cpx forwards command arguments and options to the tool.

This package has not been published to Packagist yet. The commands above are for the published release. Until then, use the local checkout below.

## Try the local checkout

The package requires PHP 8.2+, Composer 2.2+, mbstring, and OpenSSH. cpx 2.x requires PHP 8.3+. Interactive prompts support macOS, Linux, and Windows through WSL.

```bash
composer install
cpx ./ scan --provider=demo
cpx ./ upgrade --provider=demo
```

The demo uses fictional servers and fixed example versions. It never contacts a hosting provider or changes a server. A local cpx invocation runs this checkout directly; dependencies must already be installed. You can also run `php bin/laravel-php-upgrader` directly.

## Connect Forge

Supply a Forge API token in `FORGE_API_TOKEN`, or enter it in the hidden prompt when running interactively. Tokens are kept in memory and are never saved by this package. Do not put the token in a command argument.

The token needs organization/server read access and permission to manage PHP on servers that will be updated. Discovery includes every organization and server visible to the token, following every pagination cursor. Denied or failed requests remain visible as scan issues.

SSH is used only to read `/usr/bin/phpX.Y` and `/usr/sbin/php-fpmX.Y` versions. Use an SSH agent or `--identity` with access to the server's `forge` user. Existing OpenSSH configuration is honored; host keys must already be trusted in `known_hosts`. The tool does not disable host-key checks or request root access.

```bash
# Inspect everything. No patch requests are sent.
cpx kirschbaum-development/laravel-php-upgrader scan

# Inspect with a particular SSH key or user.
cpx kirschbaum-development/laravel-php-upgrader scan --identity=/absolute/path/to/key --ssh-user=forge

# Select servers interactively, review the plan, and confirm.
cpx kirschbaum-development/laravel-php-upgrader

# Show the patch plan without applying it.
cpx kirschbaum-development/laravel-php-upgrader upgrade --dry-run

# Use exact server keys printed by scan for an unattended run.
cpx kirschbaum-development/laravel-php-upgrader upgrade --server=forge:my-org/123 --server=forge:other-org/456 --yes -n

# Explicitly approve every confirmed outdated installation.
cpx kirschbaum-development/laravel-php-upgrader upgrade --all --yes -n

# JSON output for a report or another tool.
cpx kirschbaum-development/laravel-php-upgrader scan --json -n
cpx kirschbaum-development/laravel-php-upgrader upgrade --server=forge:my-org/123 --yes --json -n
```

Selecting a server includes every **confirmed outdated** Forge-managed PHP branch on that server. Current, busy, unknown, and uninspectable installations are not patched. An empty selection does nothing.

## How versions and upgrades work

1. List organizations, their servers, and all Forge-managed PHP installations.
2. Read both CLI and FPM binary versions over SSH. Forge's API reports branches such as `8.3`, rather than the installed patch number.
3. Fetch the latest stable patch for each branch from PHP.net, once per scan. Compare versions within the same branch.
4. Show the installed versions and target. Let the user choose servers and approve the patch requests.
5. Recheck each installation immediately before requesting a patch, so an update already applied elsewhere is skipped.
6. Ask Forge to patch the existing installation. This uses `PUT /orgs/{organization}/servers/{server}/php/versions/{phpVersion}`. It does not change site or CLI branch settings.
7. Wait until Forge reports the installation idle and both binaries meet the target. Only then move to the next installation and server.

An HTTP 202 response means **accepted**, not verified. An unsuccessful update or verification timeout stops the batch; remaining installations are listed as `not-run`. An ambiguous write is never retried automatically. The default verification timeout is 600 seconds per installation; use `--timeout=900` to change it.

For a specific advisory, pass confirmed minimum versions instead of using the latest upstream patch for those branches:

```bash
cpx kirschbaum-development/laravel-php-upgrader scan --minimum=8.3=8.3.35 --minimum=8.4=8.4.26
cpx kirschbaum-development/laravel-php-upgrader upgrade --minimum=8.3=8.3.35
```

These are examples, not a maintained list of security requirements. The command validates that a minimum belongs to the same PHP branch. Forge applies the patch available in the server's package repositories; it cannot be told to install an exact patch with this endpoint. If repositories have not caught up to the target, verification will remain incomplete.

`current` means the observed binary versions meet the selected target. It does not establish that a PHP branch is still supported, audit extensions, account for distribution security backports, or prove application health. The first version verifies installed CLI/FPM binaries, not the in-memory version of every running worker. It does not change PHP branches, restart application workers, deploy applications, or patch manually installed PHP that Forge does not know about.

This package has been tested with mocked Forge responses and an offline demo. Live production patching has not been exercised as part of its development.

## Providers

Implement `KirschbaumDevelopment\LaravelPhpUpgrader\Contracts\Provider`:

```php
interface Provider
{
    public function name(): string;
    public function scan(): ScanReport;
    public function patchAndVerify(Assessment $assessment): UpgradeResult;
}
```

`Server` is a provider-neutral resource with a string identifier and organization scope; a provider can represent a managed environment with it. `Assessment` carries current versions, a target, and whether an update can be applied. `UpgradeResult` distinguishes verified, already-current, failed, and unverified outcomes. Application orchestration and Laravel Prompts do not depend on Forge endpoints.

Register additional instances using an explicit local PHP file:

```php
<?php

use Acme\LaravelPhpUpgraderCloud\CloudProvider;

require '/absolute/path/to/adapter-project/vendor/autoload.php';

return [
    'cloud' => new CloudProvider(/* adapter dependencies */),
];
```

The `Acme` adapter above is an extension example, not an included dependency. Install the adapter in a separate Composer project and load its autoloader explicitly in the configuration file, as shown above. A cpx installation is isolated from your project's and global Composer dependencies. Then run:

```bash
cpx kirschbaum-development/laravel-php-upgrader scan --provider=cloud --providers-file=/absolute/path/to/providers.php
cpx kirschbaum-development/laravel-php-upgrader upgrade --provider=forge --provider=cloud --providers-file=/absolute/path/to/providers.php
```

A future Cloud adapter should discover applications and environments, distinguish provider-managed patches from branch changes, and verify its own asynchronous operation. It must not assume that changing a major/minor version is a patch update. The existing runner keeps operations sequential across providers.

## Exit codes

| Code | Meaning |
| --- | --- |
| 0 | Scan or selected upgrades completed; cancellation is also a successful no-op. |
| 1 | Invalid input, failed update, or unverified update. |
| 2 | Scan was incomplete; some installations or resources could not be inspected. |

## Optional Composer global installation

After publication, users who prefer a persistent global installation can run:

```bash
composer global require kirschbaum-development/laravel-php-upgrader
```

The executable is `laravel-php-upgrader`. In the examples above, replace `cpx kirschbaum-development/laravel-php-upgrader` with that executable when using a global installation.

## Development

```bash
composer test
composer lint
composer validate --strict
```

Tests cover organization/server/PHP pagination, authorization failures, unknown SSH state, CLI/FPM mismatches, stable version comparison, dry runs, explicit approval, stale scans, uncertain writes, verification timeouts, sequential execution, and stopping after partial failure. No tests contact Forge or SSH servers.

## References

- [Forge API schema](https://forge.laravel.com/api/docs.openapi)
- [Forge API introduction](https://laravel.com/forge/docs/api-reference/introduction)
- [Forge PHP management](https://laravel.com/forge/docs/servers/php)
- [Laravel Prompts](https://github.com/laravel/prompts)
- [cpx usage and local packages](https://github.com/laravel/cpx#usage)
- [PHP release feed](https://www.php.net/releases/index.php?json&version=8.3&max=1)

MIT licensed.
