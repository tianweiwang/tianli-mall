<?php
declare(strict_types=1);

namespace crmeb\compat\think;

use think\Cache;

/**
 * Defer ORM cache resolution while retaining the application's shared drivers.
 */
class DbCache extends Cache
{
    private Cache $delegate;
    private ?string $selectedStore;

    public function __construct(Cache $delegate, ?string $store = null)
    {
        // Manager::$app is inherited protected state; keep this exact App.
        parent::__construct($delegate->app);
        $this->delegate = $delegate;
        $this->selectedStore = $store;
    }

    protected function driver(string $name = null)
    {
        // Resolve through the original manager, including its existing driver
        // and native client options. Never construct or clone another driver.
        return $this->delegate->store($name ?: $this->selectedStore);
    }

    public function getDefaultDriver()
    {
        return $this->selectedStore ?: $this->delegate->getDefaultDriver();
    }
}
