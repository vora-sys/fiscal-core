<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional;

use InvalidArgumentException;

class NfseNacionalContractException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $expectedFields
     * @param  list<string>  $legacyFields
     * @param list<array{
     *     path:string,
     *     reason:string,
     *     received:mixed,
     *     received_type:string,
     *     expected:list<string>,
     *     expected_type:string|null,
     *     message:string
     * }> $invalidFields
     */
    public function __construct(
        private readonly array $expectedFields,
        private readonly array $legacyFields,
        private readonly array $invalidFields,
    ) {
        parent::__construct(NfseNacionalCanonicalContract::INVALID_MESSAGE);
    }

    /**
     * @return array{
     *     expected_fields:list<string>,
     *     legacy_fields:list<string>,
     *     invalid_fields:list<array{
     *         path:string,
     *         reason:string,
     *         received:mixed,
     *         received_type:string,
     *         expected:list<string>,
     *         expected_type:string|null,
     *         message:string
     *     }>,
     *     summary:string
     * }
     */
    public function details(): array
    {
        return [
            'expected_fields' => $this->expectedFields,
            'legacy_fields' => $this->legacyFields,
            'invalid_fields' => $this->invalidFields,
            'summary' => $this->summary(),
        ];
    }

    public function summary(): string
    {
        $messages = array_map(
            static fn (array $issue): string => (string) ($issue['message'] ?? ''),
            array_slice($this->invalidFields, 0, 5)
        );
        $messages = array_values(array_filter($messages, static fn (string $message): bool => trim($message) !== ''));
        $remaining = count($this->invalidFields) - count($messages);

        if ($messages === []) {
            return NfseNacionalCanonicalContract::INVALID_MESSAGE;
        }

        $summary = implode(' | ', $messages);
        if ($remaining > 0) {
            $summary .= sprintf(' | e mais %d campo(s) inválido(s).', $remaining);
        }

        return $summary;
    }
}
