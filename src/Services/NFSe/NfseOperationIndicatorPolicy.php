<?php

namespace sabbajohn\FiscalCore\Services\NFSe;

final class NfseOperationIndicatorPolicy
{
    public const POLICY_VERSION = 'nfse-operation-indicator-policy-v1';

    /** @var array<string, true> */
    private const TAKER_REQUIRED = [
        '030102' => true,
        '050102' => true,
        '100101' => true,
        '100301' => true,
        '100501' => true,
        '030103' => true,
        '050103' => true,
        '100102' => true,
        '100201' => true,
        '100302' => true,
        '100401' => true,
        '100502' => true,
        '100601' => true,
    ];

    /** @return array{required:bool,address_required:bool,reason_code:?string,enforcement:string,message:string,policy_version:string} */
    public function takerRequirement(?string $operationIndicator): array
    {
        $code = substr($this->digits($operationIndicator), 0, 6);
        $required = $code !== '' && isset(self::TAKER_REQUIRED[$code]);

        return [
            'required' => $required,
            'address_required' => $required,
            'reason_code' => $required ? 'E0234' : null,
            'enforcement' => 'warning',
            'message' => $required
                ? 'O indicador da operação exige a identificação e o endereço do tomador.'
                : 'O indicador da operação não exige, por si só, a identificação do tomador.',
            'policy_version' => self::POLICY_VERSION,
        ];
    }

    public function requiresTaker(?string $operationIndicator): bool
    {
        return $this->takerRequirement($operationIndicator)['required'];
    }

    private function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', trim((string) $value)) ?? '';
    }
}
