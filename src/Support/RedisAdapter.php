<?php

namespace Laravel\Pulse\Support;

use Illuminate\Config\Repository;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Collection;
use Predis\Client as Predis;
use Predis\Command\RawCommand;
use Predis\Pipeline\Pipeline;
use Predis\Response\ServerException as PredisServerException;
use Redis as PhpRedis;

/**
 * @internal
 */
class RedisAdapter
{
    /**
     * Create a new Redis instance.
     */
    public function __construct(
        protected Connection $connection,
        protected Repository $config,
        protected Pipeline|PhpRedis|null $client = null,
    ) {
        //
    }

    /**
     * Add an entry to the stream.
     *
     * @param  array<string, string>  $dictionary
     */
    public function xadd(string $key, array $dictionary): string|Pipeline|PhpRedis
    {
        return $this->handle([
            'XADD',
            $this->config->get('database.redis.options.prefix').$key,
            '*',
            ...collect($dictionary)->keys()->zip($dictionary)->flatten()->all(),
        ]);
    }

    /**
     * Read a range of items from the stream.
     *
     * @return array<string, array<string, string>>
     */
    public function xrange(string $key, string $start, string $end, int $count = null): array
    {
        return collect($this->handle([
            'XRANGE',
            $this->config->get('database.redis.options.prefix').$key,
            $start,
            $end,
            ...$count !== null ? ['COUNT', "$count"] : [],
        ]))->mapWithKeys(fn ($value, $key) => [
            $value[0] => collect($value[1])
                ->chunk(2)
                ->map->values()
                ->mapWithKeys(fn ($value, $key) => [$value[0] => $value[1]])
                ->all(),
        ])->all();
    }

    /**
     * Trim the stream.
     */
    public function xtrim(string $key, string $strategy, string $strategyModifier, string|int $threshold): int
    {
        return $this->handle([
            'XTRIM',
            $this->config->get('database.redis.options.prefix').$key,
            $strategy,
            $strategyModifier,
            $threshold,
        ]);
    }

    /**
     * Delete the items from the stream.
     *
     * @param  \Illuminate\Support\Collection<int, string>|array<int, string>  $keys
     */
    public function xdel(string $stream, Collection|array $keys): int
    {
        return $this->handle([
            'XDEL',
            $this->config->get('database.redis.options.prefix').$stream,
            ...$keys,
        ]);
    }

    /**
     * Run commands within a pipeline.
     *
     * @param  (callable(self): void)  $closure
     * @return array<int, mixed>
     */
    public function pipeline(callable $closure): array
    {
        // Create a pipeline and wrap the Redis client in an instance of this class to ensure our wrapper methods are used within the pipeline...
        return $this->connection->pipeline(fn (Pipeline|PhpRedis $client) => $closure(new self($this->connection, $this->config, $client))); // @phpstan-ignore method.notFound
    }

    /**
     * Run the given command.
     */
    protected function handle($args): mixed
    {
        try {
            return tap($this->run($args), function ($result) {
                if ($result === false && $this->client() instanceof PhpRedis) {
                    throw new RedisClientException($this->client()->getLastError());
                }
            });
        } catch (PredisServerException $e) {
            throw new RedisClientException($e->getMessage(), previous: $e);
        }
    }

    /**
     * Run the given command.
     */
    protected function run($args): mixed
    {
        return match (true) {
            $this->client() instanceof PhpRedis => $this->client()->rawCommand(...$args),
            $this->client() instanceof Predis,
            $this->client() instanceof Pipeline => $this->client()->executeCommand(new RawCommand($args)),
        };
    }

    /**
     * Retrieve the Redis client.
     */
    protected function client(): PhpRedis|Predis|Pipeline
    {
        return $this->client ?? $this->connection->client();
    }
}
