<?php

declare(strict_types=1);

namespace DeployEcommerce\CommunityPatches;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\ProcessExecutor;

/**
 * Applies the shipped patches after every composer install and update.
 *
 * Listens at a negative priority so it runs after the root package's own scripts,
 * including a `vendor/bin/ece-patches apply` in post-install-cmd or post-update-cmd.
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    private const BASE_PACKAGE = 'magento/magento2-base';

    private const CLOUD_PATCHES_PACKAGE = 'magento/magento-cloud-patches';

    private const ENTERPRISE_PACKAGE = 'magento/product-enterprise-edition';

    /** Below root scripts, which Composer runs at priority 0. */
    private const PRIORITY = -100;

    public function activate(Composer $composer, IOInterface $io): void
    {
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => ['applyPatches', self::PRIORITY],
            ScriptEvents::POST_UPDATE_CMD => ['applyPatches', self::PRIORITY],
        ];
    }

    public function applyPatches(Event $event): void
    {
        $composer = $event->getComposer();
        $io = $event->getIO();

        $installed = $composer->getRepositoryManager()->getLocalRepository();

        // Only Magento Open Source patches are shipped. Adobe Commerce needs its own
        // patches, which come from magento-cloud-patches, so stop before touching anything.
        if ($installed->findPackage(self::ENTERPRISE_PACKAGE, '*') !== null) {
            // Written as plain lines first: Composer wraps exception text at the terminal
            // width, which would break the commands mid-word.
            $io->writeError([
                '<error>magento-community-patches: ' . self::ENTERPRISE_PACKAGE . ' is installed, so this is an Adobe Commerce project.</error>',
                'This package only applies Magento Open Source patches and must not be used on Adobe Commerce.',
                'Remove it and install ' . self::CLOUD_PATCHES_PACKAGE . ' instead:',
                '',
                '    composer remove deployecommerce/magento-community-patches',
                '    composer require ' . self::CLOUD_PATCHES_PACKAGE,
                '',
            ]);
            throw new PatchException('Adobe Commerce project: use magento/magento-cloud-patches instead.');
        }

        $base = $installed->findPackage(self::BASE_PACKAGE, '*');
        if ($base === null) {
            $io->write('<warning>magento-community-patches: magento/magento2-base is not installed; nothing to patch.</warning>');
            return;
        }

        $packageDir = dirname(__DIR__);
        $patches = Manifest::fromFile($packageDir . '/patches.json')->forVersion($base->getPrettyVersion());
        if ($patches === []) {
            $io->write(sprintf('magento-community-patches: no patches for Magento %s.', $base->getPrettyVersion()));
            return;
        }

        $cloudPackage = $installed->findPackage(self::CLOUD_PATCHES_PACKAGE, '*');
        $cloud = $cloudPackage !== null
            ? new CloudPatches($composer->getInstallationManager()->getInstallPath($cloudPackage))
            : null;
        $planner = new Planner($cloud);
        $plan = $planner->plan($patches, $packageDir, $base->getPrettyVersion());
        $projectDir = dirname((string) realpath((string) $composer->getConfig()->get('vendor-dir')));
        $applier = new Applier(new ProcessExecutor($io), $io);

        if ($plan['cloud'] === []) {
            $steps = array_merge($plan['own'], $plan['gap']);
        } else {
            $done = $applier->appliedCount($plan['cloud'], $projectDir);
            if ($done === count($plan['cloud'])) {
                // ece-patches has run: its copies are in place, add ours and fill the gaps.
                $steps = array_merge($plan['cloud'], $plan['own'], $plan['gap']);
            } elseif ($done === 0) {
                // ece-patches runs later: only touch files it will never patch.
                $steps = $planner->independentOf($plan['cloud'], array_merge($plan['own'], $plan['gap']));
            } else {
                throw new PatchException(sprintf(
                    'Only %d of the %d magento-cloud-patches patches this plugin relies on are applied. '
                    . 'Reinstall the Magento packages and run vendor/bin/ece-patches apply before this plugin.',
                    $done,
                    count($plan['cloud'])
                ));
            }
        }

        $io->write(sprintf('magento-community-patches: applying patches for Magento %s', $base->getPrettyVersion()));
        foreach ($planner->warnings() as $warning) {
            $io->write('<warning>magento-community-patches: ' . $warning . '</warning>');
        }
        $applier->apply($steps, $projectDir);
    }
}
