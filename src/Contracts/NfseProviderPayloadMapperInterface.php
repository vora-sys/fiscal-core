<?php

namespace sabbajohn\FiscalCore\Contracts;

use sabbajohn\FiscalCore\Adapters\NFSe\DTO\Canonical\NfseEmissionDTO;
use sabbajohn\FiscalCore\Support\NfseLayoutProfile;

interface NfseProviderPayloadMapperInterface
{
    public function supports(NfseLayoutProfile $profile): bool;

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function map(NfseEmissionDTO $emission, NfseLayoutProfile $profile, array $context = []): array;
}
