<?php

declare(strict_types=1);

namespace Sso;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Sso\Contracts\IdentityProvider;

final class ProviderRegistry
{
    /**
     * @var Container
     */
    protected Container $container;

    /**
     * @var array
     */
    protected array $providers = [];

    /**
     * @param Container
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * @param string
     */
    public function register(string $class): void
    {
        if (! class_exists($class)) {
            throw new InvalidArgumentException(sprintf('Identity provider class %s does not exist; a directory providers/<Name> must contain <Name>Provider.php.', $class));
        }

        $provider = $this->container->make($class);
        if (! $provider instanceof IdentityProvider) {
            throw new InvalidArgumentException(sprintf('%s must implement %s.', $class, IdentityProvider::class));
        }

        if (preg_match('/^[a-z][a-z0-9-]{0,31}$/', $provider->id()) !== 1) {
            throw new InvalidArgumentException(sprintf('Identity provider id "%s" must be a lowercase slug of at most 32 characters.', $provider->id()));
        }

        $this->providers[$provider->id()] = $class;
    }

    /**
     * @return array
     */
    public function all(): array
    {
        return array_values(array_map(fn (string $class): IdentityProvider => $this->container->make($class), $this->providers));
    }

    /**
     * @return array
     */
    public function enabled(): array
    {
        return array_values(array_filter($this->all(), fn (IdentityProvider $provider): bool => $provider->enabled()));
    }

    /**
     * @param string
     * @return IdentityProvider|null
     */
    public function find(string $id): ?IdentityProvider
    {
        $class = $this->providers[$id] ?? null;

        return $class === null ? null : $this->container->make($class);
    }

    /**
     * @return array
     */
    public function settings(): array
    {
        return array_merge(...array_map(fn (IdentityProvider $provider): array => $provider->settings(), $this->all()));
    }
}
