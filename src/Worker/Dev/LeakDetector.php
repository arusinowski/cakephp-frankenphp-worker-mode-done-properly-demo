<?php
declare(strict_types=1);

namespace App\Worker\Dev;

use Cake\Core\Configure;
use Cake\Datasource\FactoryLocator;
use Cake\Event\EventManager;
use Cake\Routing\Router;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * Development aid: finds state that a request wrote and that outlived it (article section 6).
 *
 * Take a snapshot before a request and one after the request and the reset layer; diff() names every
 * fingerprint that changed. A static property written during a request is reported on that request;
 * state that keeps growing (routes, global listeners) is reported on every request.
 *
 * It scans every loaded class in the given namespaces with reflection, so it is slow: dev only.
 * It sees static state, not state inside long-lived objects such as shared container services;
 * the soak test (`bin/cake worker_soak`) is what catches those.
 */
class LeakDetector
{
    /**
     * @param list<string> $namespaces Namespace prefixes whose static properties are watched.
     * @param list<string> $allow Fingerprint names that are kept on purpose and never reported.
     */
    public function __construct(
        private array $namespaces = ['App\\'],
        private array $allow = [],
    ) {
    }

    /**
     * Fingerprint the watched state.
     *
     * @return array<string, string> Name => fingerprint.
     */
    public function snapshot(): array
    {
        $snapshot = [
            'Router routes' => (string)count(Router::routes()),
            'EventManager::instance() listeners' => (string)$this->countGlobalListeners(),
            'Configure' => $this->fingerprint(Configure::read()),
            'TableLocator tables' => (string)$this->countTables(),
        ];

        foreach (get_declared_classes() as $class) {
            if (!$this->watched($class)) {
                continue;
            }
            foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_STATIC) as $property) {
                if ($property->getDeclaringClass()->getName() !== $class || !$property->isInitialized()) {
                    continue;
                }
                $snapshot["static {$class}::\${$property->getName()}"] = $this->fingerprint($property->getValue());
            }
        }

        return $snapshot;
    }

    /**
     * Names whose fingerprint changed between two snapshots, minus the allow list.
     *
     * @param array<string, string> $before Snapshot before the request.
     * @param array<string, string> $after Snapshot after the request and the reset layer.
     * @return list<string>
     */
    public function diff(array $before, array $after): array
    {
        $changed = [];
        foreach ($after as $name => $fingerprint) {
            if (($before[$name] ?? null) !== $fingerprint && !in_array($name, $this->allow, true)) {
                $changed[] = $name;
            }
        }

        return $changed;
    }

    /**
     * @param string $class Class name.
     * @return bool
     */
    private function watched(string $class): bool
    {
        foreach ($this->namespaces as $namespace) {
            if (str_starts_with($class, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value Any value.
     * @return string
     */
    private function fingerprint(mixed $value): string
    {
        try {
            return md5(serialize($value));
        } catch (Throwable) {
            // Closures and other unserializable values: fall back to what JSON can see.
            return md5((string)json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
    }

    /**
     * @return int
     */
    private function countGlobalListeners(): int
    {
        $listeners = (new ReflectionProperty(EventManager::class, '_listeners'))->getValue(EventManager::instance());
        $count = 0;
        foreach ((array)$listeners as $byPriority) {
            foreach ((array)$byPriority as $list) {
                $count += count((array)$list);
            }
        }

        return $count;
    }

    /**
     * @return int
     */
    private function countTables(): int
    {
        $locator = FactoryLocator::get('Table');

        return count((array)(new ReflectionProperty($locator, 'instances'))->getValue($locator));
    }
}
