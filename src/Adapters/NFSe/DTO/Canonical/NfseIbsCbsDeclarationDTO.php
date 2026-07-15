<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Canonical;

use sabbajohn\FiscalCore\Support\NfseNacionalIbscbsClassificationRules;
use sabbajohn\FiscalCore\Support\NfseNacionalOperationIndicatorCatalog;

final class NfseIbsCbsDeclarationDTO
{
    /** @param array<string,mixed> $data */
    private function __construct(private readonly array $data) {}

    /**
     * @param  array<string,mixed>  $additional
     * @param  array<string,mixed>  $declaration
     */
    public static function fromPublicPayload(array $additional, array $declaration): self
    {
        $regular = is_array($declaration['regular'] ?? null) ? $declaration['regular'] : [];
        $deferral = is_array($declaration['diferimento'] ?? null) ? $declaration['diferimento'] : [];
        $destination = is_array($additional['destinatario'] ?? null) ? $additional['destinatario'] : [];
        $adjustment = is_array($declaration['ajuste'] ?? null) ? $declaration['ajuste'] : [];

        return new self(self::filter([
            'adicionais' => self::filter([
                'finalidade_nfse' => self::string($additional['finalidade_nfse'] ?? null),
                'tipo_nfse_debito' => self::string($additional['tipo_nfse_debito'] ?? null),
                'tipo_nfse_credito' => self::string($additional['tipo_nfse_credito'] ?? null),
                'ind_final' => self::string($additional['ind_final'] ?? null),
                'uso_consumo_pessoal' => self::boolean($additional['uso_consumo_pessoal'] ?? null),
                'codigo_indicador_operacao' => self::string($additional['codigo_indicador_operacao'] ?? null),
                'tipo_operacao' => self::string($additional['tipo_operacao'] ?? null),
                'referencias_nfse' => self::stringList($additional['referencias_nfse'] ?? null),
                'tipo_ente_governamental' => self::string($additional['tipo_ente_governamental'] ?? null),
                'indicador_destinatario' => self::string($additional['indicador_destinatario'] ?? null),
                'destinatario' => self::filter([
                    'cpf_cnpj' => self::document($destination['cpf_cnpj'] ?? null),
                    'razao_social' => self::string($destination['razao_social'] ?? null),
                ]),
            ]),
            'ibs_cbs' => self::filter([
                'cst' => self::string($declaration['cst'] ?? null),
                'classe' => self::string($declaration['classe'] ?? null),
                'codigo_credito_presumido' => self::digits($declaration['codigo_credito_presumido'] ?? null),
                'regular' => self::filter([
                    'cst' => self::string($regular['cst'] ?? null),
                    'classe' => self::string($regular['classe'] ?? null),
                ]),
                'diferimento' => self::filter([
                    'percentual_ibs_uf' => self::decimal($deferral['percentual_ibs_uf'] ?? null),
                    'percentual_ibs_municipal' => self::decimal($deferral['percentual_ibs_municipal'] ?? null),
                    'percentual_cbs' => self::decimal($deferral['percentual_cbs'] ?? null),
                ]),
                'regime_apuracao_simples_nacional' => self::string($declaration['regime_apuracao_simples_nacional'] ?? null),
                'ajuste' => self::filter([
                    'valor_ibs' => self::decimal($adjustment['valor_ibs'] ?? null),
                    'valor_cbs' => self::decimal($adjustment['valor_cbs'] ?? null),
                ]),
                'imovel' => is_array($declaration['imovel'] ?? null) ? $declaration['imovel'] : null,
                'bens_moveis' => is_array($declaration['bens_moveis'] ?? null) ? $declaration['bens_moveis'] : null,
                'pagamentos_vinculados' => is_array($declaration['pagamentos_vinculados'] ?? null) ? $declaration['pagamentos_vinculados'] : null,
            ]),
        ]));
    }

    /** @return list<string> */
    public function validate(): array
    {
        $errors = [];
        $purpose = (string) ($this->get('adicionais.finalidade_nfse') ?? '0');
        $required = $purpose === '0' ? [
            'payload.tributacao.adicionais.finalidade_nfse' => $this->get('adicionais.finalidade_nfse'),
            'payload.tributacao.adicionais.uso_consumo_pessoal' => $this->get('adicionais.uso_consumo_pessoal'),
            'payload.tributacao.adicionais.codigo_indicador_operacao' => $this->get('adicionais.codigo_indicador_operacao'),
            'payload.tributacao.adicionais.indicador_destinatario' => $this->get('adicionais.indicador_destinatario'),
            'payload.tributacao.ibs_cbs.cst' => $this->get('ibs_cbs.cst'),
            'payload.tributacao.ibs_cbs.classe' => $this->get('ibs_cbs.classe'),
        ] : [
            'payload.tributacao.adicionais.finalidade_nfse' => $this->get('adicionais.finalidade_nfse'),
        ];
        foreach ($required as $path => $value) {
            if ($value === null || $value === '') {
                $errors[] = "Campo obrigatório ausente no bloco IBS/CBS: {$path}.";
            }
        }

        $operation = (string) ($this->get('adicionais.codigo_indicador_operacao') ?? '');
        if ($operation !== '' && preg_match('/^\d{6}$/', $operation) !== 1) {
            $errors[] = 'payload.tributacao.adicionais.codigo_indicador_operacao deve conter 6 dígitos.';
        } elseif ($operation !== '' && ! NfseNacionalOperationIndicatorCatalog::contains($operation)) {
            $errors[] = 'payload.tributacao.adicionais.codigo_indicador_operacao não consta no domínio oficial cIndOp aplicável à NFS-e.';
        }

        $cst = (string) ($this->get('ibs_cbs.cst') ?? '');
        $class = (string) ($this->get('ibs_cbs.classe') ?? '');
        if ($cst !== '' && preg_match('/^\d{3}$/', $cst) !== 1) {
            $errors[] = 'payload.tributacao.ibs_cbs.cst deve conter 3 dígitos.';
        }
        if ($class !== '' && preg_match('/^\d{6}$/', $class) !== 1) {
            $errors[] = 'payload.tributacao.ibs_cbs.classe deve conter 6 dígitos.';
        }
        $presumedCredit = $this->get('ibs_cbs.codigo_credito_presumido');
        if ($presumedCredit !== null
            && ! NfseNacionalIbscbsClassificationRules::allowsCreditoPresumido($class, $cst)) {
            $errors[] = 'payload.tributacao.ibs_cbs.codigo_credito_presumido não é permitido para o CST/classificação informado.';
        }
        $regular = $this->get('ibs_cbs.regular');
        if (is_array($regular) && $regular !== []) {
            if (! NfseNacionalIbscbsClassificationRules::allowsTribRegular($class, $cst)) {
                $errors[] = 'payload.tributacao.ibs_cbs.regular não é permitido para o CST/classificação informado.';
            } elseif (! isset($regular['cst'], $regular['classe'])) {
                $errors[] = 'payload.tributacao.ibs_cbs.regular exige cst e classe.';
            } elseif (preg_match('/^\d{3}$/', (string) $regular['cst']) !== 1
                || preg_match('/^\d{6}$/', (string) $regular['classe']) !== 1) {
                $errors[] = 'payload.tributacao.ibs_cbs.regular exige cst com 3 e classe com 6 dígitos.';
            }
        }

        $deferral = $this->get('ibs_cbs.diferimento');
        if (is_array($deferral) && $deferral !== []) {
            if (! NfseNacionalIbscbsClassificationRules::allowsDiferimento($class, $cst)) {
                $errors[] = 'payload.tributacao.ibs_cbs.diferimento não é permitido para o CST/classificação informado.';
            }
            foreach (['percentual_ibs_uf', 'percentual_ibs_municipal', 'percentual_cbs'] as $field) {
                if (! array_key_exists($field, $deferral)) {
                    $errors[] = "payload.tributacao.ibs_cbs.diferimento.{$field} é obrigatório quando houver diferimento.";
                } elseif ((float) $deferral[$field] < 0 || (float) $deferral[$field] > 100) {
                    $errors[] = "payload.tributacao.ibs_cbs.diferimento.{$field} deve estar entre 0 e 100.";
                }
            }
        }

        return $errors;
    }

    public function hasLayout104Fields(): bool
    {
        foreach ([
            'adicionais.tipo_nfse_debito',
            'adicionais.tipo_nfse_credito',
            'ibs_cbs.ajuste',
            'ibs_cbs.imovel',
            'ibs_cbs.bens_moveis',
            'ibs_cbs.pagamentos_vinculados',
        ] as $path) {
            $value = $this->get($path);
            if ($value !== null && $value !== '' && $value !== []) {
                return true;
            }
        }

        return false;
    }

    public function hasSimpleNationalRegime(): bool
    {
        return $this->get('ibs_cbs.regime_apuracao_simples_nacional') !== null;
    }

    /** @return array<string,mixed> */
    public function additional(): array
    {
        $value = $this->get('adicionais');

        return is_array($value) ? $value : [];
    }

    /** @return array<string,mixed> */
    public function declaration(): array
    {
        $value = $this->get('ibs_cbs');

        return is_array($value) ? $value : [];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    private function get(string $path): mixed
    {
        $value = $this->data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function filter(array $data): array
    {
        return array_filter($data, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    private static function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private static function digits(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', is_scalar($value) ? (string) $value : '') ?? '';

        return $digits !== '' ? $digits : null;
    }

    private static function document(mixed $value): ?string
    {
        $document = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', is_scalar($value) ? (string) $value : ''));

        return $document !== '' ? $document : null;
    }

    private static function decimal(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function boolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /** @return list<string>|null */
    private static function stringList(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $values = array_values(array_filter(array_map(self::string(...), $value)));

        return $values !== [] ? $values : null;
    }
}
