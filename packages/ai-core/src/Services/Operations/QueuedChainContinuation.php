<?php

declare(strict_types=1);

namespace Core45\AiCore\Services\Operations;

use RuntimeException;

/**
 * A durable copy of the jobs Laravel attached after an asynchronous AI job.
 */
final readonly class QueuedChainContinuation
{
    /**
     * @param  list<string>  $jobs
     * @param  array<int, mixed>|null  $catchCallbacks
     */
    public function __construct(
        public array $jobs,
        public ?string $connection,
        public ?string $queue,
        public ?array $catchCallbacks,
    ) {}

    public static function capture(object $job): ?self
    {
        if (!property_exists($job, 'chained') || !is_array($job->chained) || $job->chained === []) {
            return null;
        }

        $continuation = new self(
            jobs: array_values($job->chained),
            connection: is_string($job->chainConnection ?? null) ? $job->chainConnection : null,
            queue: is_string($job->chainQueue ?? null) ? $job->chainQueue : null,
            catchCallbacks: is_array($job->chainCatchCallbacks ?? null) ? $job->chainCatchCallbacks : null,
        );

        $job->chained = [];

        return $continuation;
    }

    public function encode(): string
    {
        return base64_encode(serialize($this));
    }

    public static function decode(?string $payload): ?self
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            throw new RuntimeException('The queued chain continuation payload is invalid.');
        }

        $continuation = unserialize($decoded, ['allowed_classes' => true]);

        if (!$continuation instanceof self) {
            throw new RuntimeException('The queued chain continuation has an unexpected type.');
        }

        return $continuation;
    }

    public function dispatchNext(): void
    {
        $remainingJobs = $this->jobs;
        $serializedNextJob = array_shift($remainingJobs);

        if (!is_string($serializedNextJob)) {
            return;
        }

        $nextJob = unserialize($serializedNextJob, ['allowed_classes' => true]);

        if (!is_object($nextJob)
            || !method_exists($nextJob, 'onConnection')
            || !method_exists($nextJob, 'onQueue')) {
            throw new RuntimeException('The queued chain contains an invalid job.');
        }

        $jobConnection = data_get($nextJob, 'connection');
        $jobQueue = data_get($nextJob, 'queue');

        data_set($nextJob, 'chained', $remainingJobs);
        $nextJob->onConnection(is_string($jobConnection) && $jobConnection !== '' ? $jobConnection : $this->connection);
        $nextJob->onQueue(is_string($jobQueue) && $jobQueue !== '' ? $jobQueue : $this->queue);
        data_set($nextJob, 'chainConnection', $this->connection);
        data_set($nextJob, 'chainQueue', $this->queue);
        data_set($nextJob, 'chainCatchCallbacks', $this->catchCallbacks);

        dispatch($nextJob);
    }

    public function attachTo(object $job): void
    {
        if (!method_exists($job, 'onConnection') || !method_exists($job, 'onQueue')) {
            throw new RuntimeException('The continuation wrapper is not queueable.');
        }

        data_set($job, 'chained', $this->jobs);
        $job->onConnection($this->connection);
        $job->onQueue($this->queue);
        data_set($job, 'chainConnection', $this->connection);
        data_set($job, 'chainQueue', $this->queue);
        data_set($job, 'chainCatchCallbacks', $this->catchCallbacks);
    }
}
