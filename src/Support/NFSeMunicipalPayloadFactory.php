<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Support;

use InvalidArgumentException;
use NFePHP\Common\Certificate;

final class NFSeMunicipalPayloadFactory
{
    public function __construct(
        private readonly ?NFSeMunicipalCatalog $catalog = null
    ) {
    }

    public function demo(string $municipio): array
    {
        $meta = $this->providerMeta($municipio);
        $base = match ($meta['slug']) {
            'belem' => [
                'id' => 'RPS-BELEM-2026-1',
                'lote' => [
                    'id' => 'LOTE-BELEM-2026-1',
                    'numero' => '1001',
                ],
                'rps' => [
                    'id' => 'RPS-BELEM-2026-1-RAW',
                    'numero' => '1001',
                    'serie' => 'RPS',
                    'tipo' => '1',
                    'data_emissao' => '2026-03-18',
                    'status' => '1',
                ],
                'competencia' => '2026-03-18',
                'prestador' => [
                    'cnpj' => '12345678000195',
                    'inscricaoMunicipal' => '4007197',
                    'razao_social' => 'Freeline Tecnologia Ltda',
                    'simples_nacional' => true,
                    'regime_tributario' => 'simples nacional',
                    'mei' => false,
                    'incentivo_fiscal' => false,
                ],
                'tomador' => [
                    'documento' => '98765432000199',
                    'razao_social' => 'Cliente de Belem Ltda',
                    'email' => 'financeiro@example.com',
                    'telefone' => '(91) 99999-0000',
                    'endereco' => [
                        'logradouro' => 'Rua das Mangueiras',
                        'numero' => '100',
                        'complemento' => 'Sala 2',
                        'bairro' => 'Nazare',
                        'codigo_municipio' => '1501402',
                        'uf' => 'PA',
                        'cep' => '66000000',
                    ],
                ],
                'servico' => [
                    'codigo' => '0107',
                    'item_lista_servico' => '0107',
                    'codigo_cnae' => '620910000',
                    'descricao' => 'Servicos de tecnologia da informacao prestados em Belem.',
                    'discriminacao' => 'Servicos de tecnologia da informacao prestados em Belem.',
                    'codigo_municipio' => '1501402',
                    'aliquota' => 0.02,
                    'iss_retido' => false,
                    'exigibilidade_iss' => '1',
                ],
                'valor_servicos' => 3000.00,
            ],
            'joinville' => [
                'id' => 'JOINVILLE-RPS-2026-1',
                'rps' => [
                    'numero' => '1001',
                    'serie' => 'A1',
                    'tipo' => '1',
                    'data_emissao' => '2026-03-19 09:15:00',
                    'status' => '1',
                ],
                'competencia' => '2026-03',
                'prestador' => [
                    'cnpj' => '12345678000195',
                    'inscricaoMunicipal' => '123456',
                    'razao_social' => 'Freeline Joinville Servicos Ltda',
                    'simples_nacional' => true,
                    'incentivador_cultural' => false,
                ],
                'tomador' => [
                    'documento' => '98765432000199',
                    'razao_social' => 'Cliente Joinville Ltda',
                    'email' => 'financeiro.joinville@example.com',
                    'telefone' => '(47) 99999-1234',
                    'endereco' => [
                        'logradouro' => 'Rua do Principe',
                        'numero' => '100',
                        'complemento' => 'Sala 401',
                        'bairro' => 'Centro',
                        'codigo_municipio' => '4209102',
                        'uf' => 'SC',
                        'cep' => '89201001',
                        'municipio' => 'Joinville',
                    ],
                ],
                'servico' => [
                    'codigo' => '11.01',
                    'item_lista_servico' => '11.01',
                    'descricao' => 'Desenvolvimento e licenciamento de software.',
                    'discriminacao' => 'Desenvolvimento e licenciamento de software.',
                    'codigo_municipio' => '4209102',
                    'natureza_operacao' => '16',
                    'aliquota' => 0.02,
                    'iss_retido' => false,
                ],
                'valor_servicos' => 1500.00,
            ],
            default => $this->buildGenericDemoPayload($meta),
        };

        return $this->mergeRecursiveDistinct($base, $meta['payload_defaults']);
    }

    public function buildPrestador(string $municipio, Certificate $certificate, array $empresaConfig, array $options = []): array
    {
        $meta = $this->providerMeta($municipio);
        $cnpj = $this->normalizeDigits((string) ($empresaConfig['cnpj'] ?? $certificate->getCnpj() ?? ''));
        $razaoSocial = trim((string) ($empresaConfig['razao_social'] ?? $certificate->getCompanyName() ?? ''));
        $inscricaoMunicipal = trim((string) ($empresaConfig['inscricao_municipal'] ?? ''));
        $simples = $this->toBool($options['simples_nacional'] ?? true);

        if ($cnpj === '') {
            throw new InvalidArgumentException('Não foi possível determinar o CNPJ do prestador a partir do certificado/.env.');
        }

        if ($inscricaoMunicipal === '') {
            throw new InvalidArgumentException('FISCAL_IM é obrigatório para emissão NFSe municipal.');
        }

        if ($razaoSocial === '') {
            throw new InvalidArgumentException('FISCAL_RAZAO_SOCIAL é obrigatório quando o certificado não informa a razão social.');
        }

        $base = [
            'cnpj' => $cnpj,
            'inscricaoMunicipal' => $inscricaoMunicipal,
            'razao_social' => $razaoSocial,
            'codigo_municipio' => $meta['codigo_municipio'],
        ];

        return match ($this->normalizeMunicipio($municipio)) {
            'belem' => $base + [
                'simples_nacional' => $simples,
                'regime_tributario' => $simples ? 'simples nacional' : 'normal',
                'mei' => false,
                'incentivo_fiscal' => false,
            ],
            'joinville' => $base + [
                'simples_nacional' => $simples,
                'incentivador_cultural' => false,
            ],
            default => $base + [
                'simples_nacional' => $simples,
                'regime_tributario' => $simples ? 'simples nacional' : 'normal',
                'mei' => $this->toBool($options['mei'] ?? false),
            ],
        };
    }

    public function buildTomadorFromLookup(string $municipio, string $documento, array $lookup): array
    {
        $meta = $this->providerMeta($municipio);
        $endereco = is_array($lookup['endereco'] ?? null) ? $lookup['endereco'] : [];

        return [
            'documento' => $this->normalizeDigits($documento),
            'razao_social' => trim((string) ($lookup['razao_social'] ?? '')),
            'nome_fantasia' => trim((string) ($lookup['nome_fantasia'] ?? '')),
            'email' => trim((string) ($lookup['email'] ?? '')),
            'telefone' => trim((string) ($lookup['telefone'] ?? '')),
            'endereco' => [
                'logradouro' => trim((string) ($endereco['logradouro'] ?? '')),
                'numero' => trim((string) ($endereco['numero'] ?? 'S/N')),
                'complemento' => trim((string) ($endereco['complemento'] ?? '')),
                'bairro' => trim((string) ($endereco['bairro'] ?? '')),
                'codigo_municipio' => trim((string) ($endereco['codigo_municipio'] ?? $meta['codigo_municipio'])),
                'uf' => trim((string) ($endereco['uf'] ?? $meta['uf'])),
                'cep' => $this->normalizeDigits((string) ($endereco['cep'] ?? '')),
                'municipio' => trim((string) ($endereco['municipio'] ?? $meta['nome'])),
            ],
        ];
    }

    public function real(string $municipio, array $prestador, array $tomador, array $overrides = []): array
    {
        $meta = $this->providerMeta($municipio);
        $today = new \DateTimeImmutable('now');

        $base = match ($meta['slug']) {
            'belem' => [
                'id' => sprintf('RPS-BELEM-%s-1', $today->format('YmdHis')),
                'lote' => [
                    'id' => sprintf('LOTE-BELEM-%s', $today->format('YmdHis')),
                    'numero' => $today->format('His'),
                ],
                'rps' => [
                    'id' => sprintf('RPS-BELEM-%s-RAW', $today->format('YmdHis')),
                    'numero' => $today->format('His'),
                    'serie' => 'RPS',
                    'tipo' => '1',
                    'data_emissao' => $today->format('Y-m-d'),
                    'status' => '1',
                ],
                'competencia' => $today->format('Y-m-d'),
                'prestador' => $prestador,
                'tomador' => $tomador,
                'servico' => [
                    'codigo' => '0107',
                    'item_lista_servico' => '0107',
                    'codigo_cnae' => '620910000',
                    'descricao' => 'Servicos de tecnologia da informacao em homologacao.',
                    'discriminacao' => 'Servicos de tecnologia da informacao em homologacao.',
                    'codigo_municipio' => $meta['codigo_municipio'],
                    'aliquota' => 0.02,
                    'iss_retido' => false,
                    'exigibilidade_iss' => '1',
                ],
                'valor_servicos' => 3000.00,
            ],
            'joinville' => [
                'id' => sprintf('JOINVILLE-RPS-%s', $today->format('YmdHis')),
                'rps' => [
                    'numero' => $today->format('His'),
                    'serie' => 'A1',
                    'tipo' => '1',
                    'data_emissao' => $today->format('Y-m-d H:i:s'),
                    'status' => '1',
                ],
                'competencia' => $today->format('Y-m'),
                'prestador' => $prestador,
                'tomador' => $tomador,
                'servico' => [
                    'codigo' => '11.01',
                    'item_lista_servico' => '11.01',
                    'descricao' => 'Desenvolvimento e licenciamento de software em homologacao.',
                    'discriminacao' => 'Desenvolvimento e licenciamento de software em homologacao.',
                    'codigo_municipio' => $meta['codigo_municipio'],
                    'natureza_operacao' => '16',
                    'aliquota' => 0.02,
                    'iss_retido' => false,
                ],
                'valor_servicos' => 1500.00,
            ],
            default => $this->buildGenericRealPayload($meta, $prestador, $tomador, $today),
        };

        $base = $this->mergeRecursiveDistinct($base, $meta['payload_defaults']);
        $base['prestador'] = $this->mergeRecursiveDistinct(
            is_array($base['prestador'] ?? null) ? $base['prestador'] : [],
            $prestador
        );
        $base['tomador'] = $this->mergeRecursiveDistinct(
            is_array($base['tomador'] ?? null) ? $base['tomador'] : [],
            $tomador
        );

        return $this->mergeRecursiveDistinct($base, $overrides);
    }

    public function providerMeta(string $municipio): array
    {
        $resolved = $this->catalog()->resolveMunicipio($municipio);
        if ($resolved === null) {
            throw new InvalidArgumentException("Município '{$municipio}' não suportado.");
        }
        if ((string) ($resolved['provider_family_key'] ?? '') === ProviderRegistry::NFSE_NATIONAL_KEY) {
            throw new InvalidArgumentException(
                "Município '{$municipio}' utiliza o fluxo NFSe nacional; use o payload nacional."
            );
        }

        return [
            'slug' => (string) $resolved['slug'],
            'nome' => (string) $resolved['nome'],
            'codigo_municipio' => (string) $resolved['ibge'],
            'uf' => (string) $resolved['uf'],
            'provider_key' => (string) $resolved['provider_family_key'],
            'schema_package' => (string) $resolved['schema_package'],
            'provider_note' => (string) ($resolved['provider_note'] ?? ''),
            'payload_defaults' => is_array($resolved['payload_defaults'] ?? null)
                ? $resolved['payload_defaults']
                : [],
        ];
    }

    private function buildGenericDemoPayload(array $meta): array
    {
        $slugToken = strtoupper(str_replace('-', '', $meta['slug']));

        $base = [
            'id' => sprintf('%s-RPS-2026-1', $slugToken),
            'rps' => [
                'numero' => '1001',
                'serie' => 'RPS',
                'tipo' => '1',
                'data_emissao' => '2026-03-20',
                'status' => '1',
            ],
            'competencia' => '2026-03-20',
            'prestador' => [
                'cnpj' => '12345678000195',
                'inscricaoMunicipal' => '123456',
                'razao_social' => 'Freeline Servicos Digitais Ltda',
                'simples_nacional' => true,
                'regime_tributario' => 'simples nacional',
                'mei' => false,
                'codigo_municipio' => $meta['codigo_municipio'],
            ],
            'tomador' => [
                'documento' => '98765432000199',
                'razao_social' => 'Tomador de Homologacao Ltda',
                'email' => 'financeiro@example.com',
                'telefone' => '(11) 99999-0000',
                'endereco' => [
                    'logradouro' => 'Rua de Homologacao',
                    'numero' => '100',
                    'bairro' => 'Centro',
                    'codigo_municipio' => $meta['codigo_municipio'],
                    'uf' => $meta['uf'],
                    'cep' => '69000000',
                    'municipio' => $meta['nome'],
                ],
            ],
            'servico' => [
                'codigo' => '101',
                'descricao' => 'Servico de homologacao NFSe.',
                'discriminacao' => 'Servico de homologacao NFSe.',
                'codigo_municipio' => $meta['codigo_municipio'],
                'aliquota' => 0.02,
                'iss_retido' => false,
            ],
            'valor_servicos' => 150.00,
        ];

        if (($meta['payload_defaults'] ?? []) === []) {
            throw new InvalidArgumentException(
                "Município '{$meta['slug']}' não possui payload demo canonizado; defina payload_defaults no catálogo."
            );
        }

        return $base;
    }

    private function buildGenericRealPayload(
        array $meta,
        array $prestador,
        array $tomador,
        \DateTimeImmutable $today
    ): array {
        if (($meta['payload_defaults'] ?? []) === []) {
            throw new InvalidArgumentException(
                "Município '{$meta['slug']}' não possui payload real canonizado; defina payload_defaults no catálogo."
            );
        }

        return [
            'id' => sprintf('%s-RPS-%s', strtoupper(str_replace('-', '', $meta['slug'])), $today->format('YmdHis')),
            'rps' => [
                'numero' => $today->format('His'),
                'serie' => 'RPS',
                'tipo' => '1',
                'data_emissao' => $today->format('Y-m-d'),
                'status' => '1',
            ],
            'competencia' => $today->format('Y-m-d'),
            'prestador' => $prestador,
            'tomador' => $tomador,
            'servico' => [
                'codigo' => '101',
                'descricao' => 'Servico de homologacao NFSe.',
                'discriminacao' => 'Servico de homologacao NFSe.',
                'codigo_municipio' => $meta['codigo_municipio'],
                'aliquota' => 0.02,
                'iss_retido' => false,
            ],
            'valor_servicos' => 150.00,
        ];
    }

    private function mergeRecursiveDistinct(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeRecursiveDistinct($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    private function normalizeMunicipio(string $municipio): string
    {
        return strtolower(trim($municipio));
    }

    private function catalog(): NFSeMunicipalCatalog
    {
        return $this->catalog ?? new NFSeMunicipalCatalog();
    }

    private function normalizeDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'sim'], true);
    }
}
