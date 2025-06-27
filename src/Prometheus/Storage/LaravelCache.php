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
        $rawMetrics = Cache::get($this->prefix . 'metrics', []);
        $results = [];

        foreach ($rawMetrics as $raw) {
            $samples = [];

            foreach ($raw['samples'] as $sample) {
                $samples[] = [
                    'labelNames' => $raw['labelNames'],
                    'labelValues' => $sample['labelValues'],
                    'value' => $sample['value'],
                ];
            }

            $results[] = new MetricFamilySamples([
                'name' => $raw['name'],
                'type' => $raw['type'],
                'help' => $raw['help'],
                'labelNames' => $raw['labelNames'],
                'samples' => $samples,
            ]);
        }

        return $results;
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
        $metrics = Cache::get($this->prefix . 'metrics', []);

        $name = $data['name'];
        $help = $data['help'];
        $labelNames = $data['labelNames'] ?? [];
        $labelValues = $data['labelValues'] ?? [];
        $value = $data['value'] ?? 1;

        $labelKey = implode('|', $labelValues);
        $metricKey = "{$type}_{$name}";

        if (!isset($metrics[$metricKey])) {
            $metrics[$metricKey] = [
                'type' => $type,
                'name' => $name,
                'help' => $help,
                'labelNames' => $labelNames,
                'samples' => [],
            ];
        }

        if (!isset($metrics[$metricKey]['samples'][$labelKey])) {
            $metrics[$metricKey]['samples'][$labelKey] = [
                'labelValues' => $labelValues,
                'value' => 0,
            ];
        }

        if ($type === 'counter') {
            $metrics[$metricKey]['samples'][$labelKey]['value'] += $value;
        } elseif ($type === 'gauge') {
            $metrics[$metricKey]['samples'][$labelKey]['value'] = $value;
        } elseif ($type === 'histogram') {
            // Histogram support would require bucket tracking — for now, we leave this out
        }

        Cache::put($this->prefix . 'metrics', $metrics, now()->addHours(1));
    }
}
