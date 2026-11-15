# Code inventory — 001-baseline

**Last audited:** 2026-11-16  
**Package:** `nowo-tech/runtime-setting-contract`  
**Production units:** 42 (every file tracked under `src/`)  
**Mapped:** 42  
**Unmapped:** 0

Audit: `git ls-files src | wc -l` must equal the mapped count below.


## Contract

| Source path | Requirement IDs |
| --- | --- |
| `src/Contract/RuntimeSettingAction.php` | FR-UPD-005 |
| `src/Contract/RuntimeSettingChange.php` | FR-UPD-005 |
| `src/Contract/RuntimeSettingDefinition.php` | FR-DEF-001, FR-DEF-002 |
| `src/Contract/RuntimeSettingErrorPolicy.php` | FR-RES-005 |
| `src/Contract/RuntimeSettingId.php` | FR-ID-001 |
| `src/Contract/RuntimeSettingRegistry.php` | FR-REG-001 |
| `src/Contract/RuntimeSettingResolver.php` | FR-RES-001..008 |
| `src/Contract/RuntimeSettingSource.php` | FR-RES-001, FR-RES-002 |
| `src/Contract/RuntimeSettingType.php` | FR-TYPE-001 |
| `src/Contract/RuntimeSettingUpdater.php` | FR-UPD-001..005 |
| `src/Contract/RuntimeSettingValidatorInterface.php` | FR-DEF-002 |
| `src/Contract/RuntimeSettingWriteContext.php` | FR-WRT-001 |
| `src/Contract/RuntimeSettingWriterInterface.php` | FR-WRT-001 |
| `src/Contract/RuntimeValueCacheEntry.php` | FR-RES-007 |
| `src/Contract/RuntimeValueCacheInterface.php` | FR-RES-007 |
| `src/Contract/RuntimeValueFingerprinter.php` | FR-FP-001 |
| `src/Contract/RuntimeValueSourceInterface.php` | FR-SRC-001 |
| `src/Contract/SettingChangedEvent.php` | FR-UPD-005 |
| `src/Contract/StoredRuntimeValue.php` | FR-SRC-001, FR-RES-006 |
| `src/Contract/SystemClock.php` | FR-RES-004, FR-UPD-004 |

## Contract exceptions

| Source path | Requirement IDs |
| --- | --- |
| `src/Contract/Exception/InvalidRuntimeSettingId.php` | FR-ID-001, FR-RES-005, FR-UPD-001 |
| `src/Contract/Exception/InvalidRuntimeSettingValue.php` | FR-ID-001, FR-RES-005, FR-UPD-001 |
| `src/Contract/Exception/RuntimeSettingConfigurationError.php` | FR-ID-001, FR-RES-005, FR-UPD-001 |
| `src/Contract/Exception/RuntimeSettingExceptionInterface.php` | FR-ID-001, FR-RES-005, FR-UPD-001 |
| `src/Contract/Exception/RuntimeSettingNotWritable.php` | FR-ID-001, FR-RES-005, FR-UPD-001 |
| `src/Contract/Exception/RuntimeSettingSourceFailure.php` | FR-ID-001, FR-RES-005, FR-UPD-001 |
| `src/Contract/Exception/RuntimeSettingUnavailable.php` | FR-ID-001, FR-RES-005, FR-UPD-001 |
| `src/Contract/Exception/UnknownRuntimeSetting.php` | FR-ID-001, FR-RES-005, FR-UPD-001 |

## Reference store

| Source path | Requirement IDs |
| --- | --- |
| `src/InMemory/InMemoryRuntimeSettingStore.php` | FR-MEM-001 |

## PSR-6 bridge

| Source path | Requirement IDs |
| --- | --- |
| `src/Bridge/Psr6/Psr6RuntimeValueCache.php` | FR-SF-003 |

## Symfony bridge

| Source path | Requirement IDs |
| --- | --- |
| `src/Bridge/Symfony/DependencyInjection/DefaultPortsPass.php` | FR-SF-005 |
| `src/Bridge/Symfony/DependencyInjection/RuntimeSettingsConfiguration.php` | FR-SF-004 |
| `src/Bridge/Symfony/EventListener/RuntimeSettingBoundaryListener.php` | FR-SF-002 |
| `src/Bridge/Symfony/NowoRuntimeSettingBundle.php` | FR-SF-001 |
| `src/Bridge/Symfony/UnconfiguredRuntimeSettingStore.php` | FR-SF-005 |

## Console commands

| Source path | Requirement IDs |
| --- | --- |
| `src/Bridge/Symfony/Command/ChangeOutput.php` | FR-CMD-001 |
| `src/Bridge/Symfony/Command/CommandIo.php` | FR-CMD-001 |
| `src/Bridge/Symfony/Command/GetRuntimeSettingCommand.php` | FR-CMD-001 |
| `src/Bridge/Symfony/Command/ListRuntimeSettingsCommand.php` | FR-CMD-001 |
| `src/Bridge/Symfony/Command/RotateRuntimeSettingCommand.php` | FR-CMD-001 |
| `src/Bridge/Symfony/Command/SetRuntimeSettingCommand.php` | FR-CMD-001 |
| `src/Bridge/Symfony/Command/UnsetRuntimeSettingCommand.php` | FR-CMD-001 |
