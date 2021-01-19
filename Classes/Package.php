<?php

declare(strict_types=1);

namespace Flownative\GraphQL;

use Neos\Flow\Cache\CacheManager;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Core\Booting\Sequence;
use Neos\Flow\Core\Booting\Step;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;
use Neos\Flow\Package\PackageManager;
use Neos\Utility\Files;

class Package extends BasePackage
{
    /**
     * @param Bootstrap $bootstrap
     */
    public function boot(Bootstrap $bootstrap): void
    {
        if ($bootstrap->getContext()->isProduction()) {
            return;
        }

        $dispatcher = $bootstrap->getSignalSlotDispatcher();
        $dispatcher->connect(Sequence::class, 'afterInvokeStep', function (Step $step) use ($bootstrap): void {
            if ($step->getIdentifier() !== 'neos.flow:systemfilemonitor') {
                return;
            }
            $endpointConfigurations = $bootstrap->getEarlyInstance(ConfigurationManager::class)->getConfiguration(
                ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
                'Flownative.GraphQL.endpoints'
            );
            $packageManager = $bootstrap->getEarlyInstance(PackageManager::class);

            $newChecksum = '';
            foreach ($endpointConfigurations as $endpointConfiguration) {
                if (isset($endpointConfiguration['schema'])) {
                    if (strpos($endpointConfiguration['schema'], 'resource://') === 0) {
                        [$packageName, $path] = explode('/', substr($endpointConfiguration['schema'], 11), 2);

                        try {
                            $package = $packageManager->getPackage($packageName);
                            assert($package instanceof \Neos\Flow\Package\Package);
                            $schemaPathAndFilename = Files::concatenatePaths([$package->getResourcesPath(), $path]);
                            if (file_exists($schemaPathAndFilename)) {
                                $newChecksum .= sha1_file($schemaPathAndFilename);
                            }
                        } catch (\Neos\Flow\Package\Exception\UnknownPackageException $packageException) {
                        }
                    }
                }
            }
            $cacheManager = $bootstrap->getObjectManager()->get(CacheManager::class);
            $cache = $cacheManager->getCache('Flownative_GraphQL_Schema');

            $existingChecksum = $cache->get('checksum');
            if ($existingChecksum === $newChecksum) {
                return;
            }

            $cache->flush();
            $cache->set('checksum', $newChecksum);
        });
    }
}
