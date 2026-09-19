<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Support;

trait ProfilesNFSeEmission
{
    /** @var array<string,float> */
    private array $lastEmissionMetrics = [];

    private function beginEmissionProfile(): int
    {
        $this->lastEmissionMetrics = [];
        $this->lastOperation = 'emitir';
        $this->lastRequestXml = null;
        $this->lastSoapEnvelope = null;
        $this->lastResponseXml = null;
        $this->lastResponseData = [];
        $this->lastOperationArtifacts = ['operation' => 'emitir'];

        return hrtime(true);
    }

    private function addEmissionMetric(string $metric, int $startedAt): void
    {
        $elapsed = max(0, (hrtime(true) - $startedAt) / 1_000_000);
        $this->lastEmissionMetrics[$metric] = round(
            ($this->lastEmissionMetrics[$metric] ?? 0.0) + $elapsed,
            2,
        );
    }

    private function finishEmissionProfile(int $startedAt): void
    {
        $this->lastEmissionMetrics['provider_total_ms'] = round(
            max(0, (hrtime(true) - $startedAt) / 1_000_000),
            2,
        );
        $this->lastOperationArtifacts['metrics'] = $this->lastEmissionMetrics;
    }

    /**
     * @param  array<string,mixed>  $artifacts
     * @return array<string,mixed>
     */
    private function withEmissionMetrics(string $operation, array $artifacts): array
    {
        if ($operation === 'emitir') {
            $artifacts['metrics'] = $this->lastEmissionMetrics;
        }

        return $artifacts;
    }
}
