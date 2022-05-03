<?php
declare(strict_types=1);

namespace Flownative\OpenIdConnect\Neos;

use Flownative\OpenIdConnect\Client\Authentication\OpenIdConnectProvider;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;

class Package extends BasePackage
{
    /**
     * @param Bootstrap $bootstrap
     */
    public function boot(Bootstrap $bootstrap)
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();
        $dispatcher->connect(OpenIdConnectProvider::class, 'authenticated', AccountManager::class, 'addBackendUserAndAccountIfNotExistent', false);
    }
}
