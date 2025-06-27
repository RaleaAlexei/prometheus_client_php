<?php

namespace Prometheus\Storage;

use Illuminate\Support\Facades\Cache;
use Prometheus\MetricFamilySamples;

class LaravelCache implements Adapter
{
    protected string $prefix = 'prometheus_';

    /**
     * @return MetricFamilySamples[]
     */
    public function collect(): array
    {
        return Cache::get($this->prefix . 'metrics', []);
    }

    public function updateHistogram(array $data): void
    {
        $this->storeSample($data, 'histogram');
    }

    public function updateGauge(array $data): void
    {
        $this->storeSample($data, 'gauge');
    }

    public function updateCounter(array $data): void
    {
        $this->storeSample($data, 'counter');
    }

    public function wipeStorage(): void
    {
        Cache::forget($this->prefix . 'metrics');
    }

    /**
     * Internal helper to store a new metric sample.
     *
     * @param array $data
     * @param string $type
     */
    protected function storeSample(array $data, string $type): void
    {
        $metrics = $this->collect();

        $sample = new MetricFamilySamples([
            'name' => $data['name'] ?? '',
            'type' => $type,
            'help' => $data['help'] ?? '',
            'labelNames' => $data['labelNames'] ?? [],
            'samples' => $data['samples'] ?? [],
        ]);

        $key = "{$this->prefix}{$type}_{$data['name']}";
        $metrics[$key] = $sample;

        Cache::put($this->prefix . 'metrics', $metrics, now()->addHours(1));
    }
}
