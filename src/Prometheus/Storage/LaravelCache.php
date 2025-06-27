<?php

namespace Prometheus\Storage;

use Illuminate\Support\Facades\Cache;
use Prometheus\MetricFamilySamples;

class LaravelCache implements Adapter
{
    protected string $prefix = 'prometheus_';

    public function collect(): array
    {
        $rawMetrics = Cache::get($this->prefix . 'metrics', []);
        $results = [];
        foreach ($rawMetrics as $raw) {
            $samples = array_values($raw['samples']); // Reset numeric keys
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
        $metrics = Cache::get($this->prefix . 'metrics', []);

        $name = $data['name'];
        $help = $data['help'];
        $labelNames = $data['labelNames'] ?? [];
        $labelValues = $data['labelValues'] ?? [];
        $buckets = $data['buckets'];
        $value = $data['value'];

        $labelKey = implode('|', $labelValues);
        $metricKey = "histogram_{$name}";

        if (!isset($metrics[$metricKey])) {
            $metrics[$metricKey] = [
                'type' => 'histogram',
                'name' => $name,
                'help' => $help,
                'labelNames' => $labelNames,
                'samples' => [],
            ];
        }

        // Initialize or update buckets
        foreach ($buckets as $bucket) {
            if (!isset($metrics[$metricKey]['samples']["{$labelKey}_le_{$bucket}"])) {
                $metrics[$metricKey]['samples']["{$labelKey}_le_{$bucket}"] = [
                    'name' => "{$name}_bucket",
                    'labelNames' => array_merge($labelNames, ['le']),
                    'labelValues' => array_merge($labelValues, [(string) $bucket]),
                    'value' => 0,
                ];
            }

            if ($value <= $bucket) {
                $metrics[$metricKey]['samples']["{$labelKey}_le_{$bucket}"]['value'] += 1;
            }
        }

        // Always increment +Inf bucket
        $infKey = "{$labelKey}_le_+Inf";
        if (!isset($metrics[$metricKey]['samples'][$infKey])) {
            $metrics[$metricKey]['samples'][$infKey] = [
                'name' => "{$name}_bucket",
                'labelNames' => array_merge($labelNames, ['le']),
                'labelValues' => array_merge($labelValues, ['+Inf']),
                'value' => 0,
            ];
        }
        $metrics[$metricKey]['samples'][$infKey]['value'] += 1;

        // Total count
        $countKey = "{$labelKey}_count";
        if (!isset($metrics[$metricKey]['samples'][$countKey])) {
            $metrics[$metricKey]['samples'][$countKey] = [
                'name' => "{$name}_count",
                'labelNames' => $labelNames,
                'labelValues' => $labelValues,
                'value' => 0,
            ];
        }
        $metrics[$metricKey]['samples'][$countKey]['value'] += 1;

        // Total sum
        $sumKey = "{$labelKey}_sum";
        if (!isset($metrics[$metricKey]['samples'][$sumKey])) {
            $metrics[$metricKey]['samples'][$sumKey] = [
                'name' => "{$name}_sum",
                'labelNames' => $labelNames,
                'labelValues' => $labelValues,
                'value' => 0.0,
            ];
        }
        $metrics[$metricKey]['samples'][$sumKey]['value'] += $value;

        Cache::put($this->prefix . 'metrics', $metrics, now()->addHours(1));
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

        $existingSampleIndex = null;

        foreach ($metrics[$metricKey]['samples'] as $index => $sample) {
            if ($sample['labelValues'] === $labelValues) {
                $existingSampleIndex = $index;
                break;
            }
        }

        if ($existingSampleIndex !== null) {
            if ($type === 'counter') {
                $metrics[$metricKey]['samples'][$existingSampleIndex]['value'] += $value;
            } elseif ($type === 'gauge') {
                $metrics[$metricKey]['samples'][$existingSampleIndex]['value'] = $value;
            }
        } else {
            $metrics[$metricKey]['samples'][] = [
                'name' => $name,
                'labelNames' => $labelNames,
                'labelValues' => $labelValues,
                'value' => $value,
            ];
        }

        Cache::put($this->prefix . 'metrics', $metrics, now()->addHours(1));
    }
}
