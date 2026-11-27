<?php

declare(strict_types=1);

// CI proof that src/Contract and src/InMemory run with `composer install --no-dev`: no Symfony, no Doctrine.

use Doctrine\DBAL\Driver;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingRegistry;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingUpdater;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueFingerprinter;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueProviderBinding;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueProviderInterface;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueProviderRegistry;
use Nowo\RuntimeSettingContract\Contract\StoredRuntimeValue;
use Nowo\RuntimeSettingContract\Contract\Validator\Unbounded;
use Nowo\RuntimeSettingContract\InMemory\InMemoryRuntimeSettingStore;
use Symfony\Component\HttpKernel\Kernel;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (class_exists(Kernel::class) || interface_exists(Driver::class)) {
    throw new RuntimeException('Symfony or Doctrine is installed: run this after `composer install --no-dev`.');
}

$store      = new InMemoryRuntimeSettingStore();
$definition = new RuntimeSettingDefinition('CHAT_RETENTION_DAYS', RuntimeSettingType::Int, RuntimeSettingSource::DbThenEnv, validator: new Unbounded(), configured: '30');
$resolver   = new RuntimeSettingResolver($store, versionTtl: 0);
$updater    = new RuntimeSettingUpdater(new RuntimeSettingRegistry([$definition]), $store, $resolver, new RuntimeValueFingerprinter(str_repeat('k', 32)));

$before = $resolver->get($definition);
$updater->set('CHAT_RETENTION_DAYS', '45', 'cli:ci');
$resolver->clearRuntimeCache();
$after = $resolver->get($definition);

if ($before !== 30 || $after !== 45) {
    throw new RuntimeException('Unexpected values.');
}

// 1.1: a read-only provider, framework-free.
$provider = new class implements RuntimeValueProviderInterface {
    public function read(RuntimeSettingId $id): StoredRuntimeValue
    {
        return new StoredRuntimeValue('60');
    }
};
$provided   = new RuntimeSettingDefinition('chat.tenant_days', RuntimeSettingType::Int, RuntimeSettingSource::Db, validator: new Unbounded(), provider: 'tenant');
$withTenant = new RuntimeSettingResolver($store, versionTtl: 0, providers: new RuntimeValueProviderRegistry([new RuntimeValueProviderBinding('tenant', $provider, 60)]));
if ($withTenant->get($provided) !== 60) {
    throw new RuntimeException('Unexpected provided value.');
}

echo "Contract works without Symfony or Doctrine.\n";
