# Magento Community Patches

> **Beta.** This package is new and we are still ironing out bugs. Try it on a staging environment before production, check the install output, and please [open an issue](https://github.com/DeployEcommerce/magento-community-patches/issues) if anything looks wrong.

`deployecommerce/magento-community-patches` is a Composer plugin. It applies Adobe's official security patches for Magento Open Source automatically every time you run `composer install` or `composer update`.

```bash
composer config allow-plugins.deployecommerce/magento-community-patches true
composer require deployecommerce/magento-community-patches
```

**Magento Open Source only.** On an Adobe Commerce project (one with `magento/product-enterprise-edition` installed) the plugin stops the install before patching anything and tells you to install `magento/magento-cloud-patches` instead. Adobe Commerce needs Adobe's Commerce patches, which this package does not ship.

The plugin reads the installed version of `magento/magento2-base`, selects the patches published for that version, and applies them with `git apply` in the order Adobe's prerequisites require. A patch that is already applied is skipped. A patch that can neither be applied nor recognised as already applied stops the install with an error, so a half-patched project never goes unnoticed.

## Projects that also use magento/magento-cloud-patches

The plugin works alongside `magento/magento-cloud-patches` (often installed by `magento/quality-patches`) and never fights it over the same files:

- Where cloud-patches ships its own copy of a patch, the plugin treats that copy as the patch, so `ece-patches` keeps recognising it.
- Where a cloud-patches copy leaves out part of Adobe's published patch, the plugin applies the missing files itself, as long as no cloud-patches patch touches them, and says so in the install output.
- Adobe's monthly patches only apply in order on unpatched files. So if `ece-patches` has not run yet when the plugin runs, the plugin holds back any patch that changes files `ece-patches` will patch later, and prints a warning naming it.

To get everything applied on every install, run `ece-patches` from your root `composer.json`. The plugin always runs after the root package's own scripts:

```json
"scripts": {
    "post-install-cmd": ["vendor/bin/ece-patches apply"],
    "post-update-cmd": ["vendor/bin/ece-patches apply"]
}
```

## Copyright and licensing

**The patch files in `patches/` are Adobe's work and Adobe owns the copyright in them.** They are Adobe's Magento Open Source isolated security patches, redistributed byte-for-byte as Adobe publishes them, under the Open Software License 3.0 (OSL-3.0) that Magento Open Source is released under. A full copy of the licence is in [LICENSE.txt](LICENSE.txt).

Each patch has a sibling `.sha256` file. Its hash must equal the `sha256` Adobe publishes for that patch in the [Adobe patch registry](https://repo.magento.com/patch/patch-registry.json). You can check every file yourself:

```bash
cd patches && sha256sum -c *.sha256
```

A public page compares every shipped patch with Adobe's registry and is rebuilt within an hour of any change to the registry: <https://deployecommerce.github.io/magento-community-patches/>. Any mismatch opens an issue on this repository.

The plugin code in `src/` was written by Deploy Ecommerce and is also licensed under OSL-3.0.

This project is not affiliated with, endorsed by or supported by Adobe. "Adobe", "Adobe Commerce" and "Magento" are trademarks of Adobe Inc.

## Why this package exists

Adobe's `magento/magento-cloud-patches` package is how Adobe Commerce projects receive security patches automatically. Magento Open Source projects relied on it as well, because `magento/quality-patches` pulls it in. That route is no longer open to Magento Open Source:

- **From version 1.1.15 (July 2026), `magento/magento-cloud-patches` is distributed only through Adobe's Commerce repository and needs an Adobe Commerce entitlement to download.** The public GitHub repository stops at 1.1.14. Magento Open Source projects without that entitlement have no supported way to get later releases. [Adobe gates magento-cloud-patches behind an EE entitlement](https://dor.ky/post/adobe-gates-magento-cloud-patches-behind-ee-entitlement) explains the change and what it means for Magento Open Source projects.
- **It only applies patches when a project runs `vendor/bin/ece-patches apply` itself.** Composer does not run scripts that belong to dependencies, so a project that forgets the call gets no patches.
- **Its copies of the monthly patches do not always match the patches Adobe publishes.** For example, the October 2026 patch for 2.4.8-p5 in 1.1.22 leaves out the `framework/Validator/EmailAddress.php` change that Adobe's published `248p5-2026-10-001-CE` patch contains.

This package uses Adobe's published patches directly, checks every one against Adobe's own registry hash, and applies them through a Composer plugin, so installing the package is all a project has to do.

## What is included

Only patches that meet every one of these conditions are shipped:

- Adobe's registry lists the patch as a Magento Open Source (`CE`) patch.
- The registry attaches no entitlement to it.
- Adobe publishes it for download without authentication.
- Every patch it depends on meets the same conditions.
- Its sha256 matches the value in Adobe's registry.
- Every file it changes belongs to a Magento Open Source package.

A patch is published only for the versions in its registry entry that are public Magento Open Source releases. Adobe Commerce, B2B and entitlement-restricted patches are never included, and patches that fail any check are never downloaded.

`patches/` and `patches.json` are generated by a scheduled sync against Adobe's registry (`tools/bin/sync`), so they should not be edited by hand.

## How patches get here

Everything is automated from Adobe's public patch registry; nothing is added by hand.

1. **Sync** (every 15 minutes, `.github/workflows/sync.yml`): `tools/bin/sync` reads the registry, applies the checks above, downloads only the patches that pass, and opens a pull request with the new `.patch` and `.sha256` files and the regenerated `patches.json`. If Adobe ever changes the bytes of a patch it already published, the sync stops and asks for a human review instead of overwriting it.
2. **CI** (`.github/workflows/ci.yml`): runs the tests on PHP 8.1 to 8.5, re-checks every patch against the live registry (`tools/bin/verify`), and applies each release's full patch sequence to pristine Magento Open Source files from the Mage-OS mirror (`tools/bin/apply-test`).
3. **Release** (`.github/workflows/release.yml`): merging to `main` tags a release. Adding or removing patches is a minor release, anything else a patch release. Each release carries `patches-manifest.sha256` and a GitHub build provenance attestation:

   ```bash
   gh attestation verify patches-manifest.sha256 --repo DeployEcommerce/magento-community-patches
   ```

4. **Hash page** (`.github/workflows/pages.yml`): rebuilt on every push to `main`, and hourly whenever Adobe's registry has changed since the last build.

