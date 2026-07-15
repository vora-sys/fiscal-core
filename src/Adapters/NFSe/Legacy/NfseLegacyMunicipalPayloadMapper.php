<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\Legacy;

use sabbajohn\FiscalCore\Adapters\NFSe\DTO\Canonical\NfseEmissionDTO;
use sabbajohn\FiscalCore\Contracts\NfseProviderPayloadMapperInterface;
use sabbajohn\FiscalCore\Support\NfseLayoutCapabilityException;
use sabbajohn\FiscalCore\Support\NfseLayoutProfile;

final class NfseLegacyMunicipalPayloadMapper implements NfseProviderPayloadMapperInterface
{
    public function supports(NfseLayoutProfile $profile): bool
    {
        return $profile->family === 'MUNICIPAL_LEGACY';
    }

    public function map(NfseEmissionDTO $emission, NfseLayoutProfile $profile, array $context = []): array
    {
        $emission->assertValid(false);
        if (! $profile->supportsCapability('declaracao_ibs_cbs')) {
            throw new NfseLayoutCapabilityException(
                'A ponte municipal não possui mapeamento IBS/CBS homologado para este provedor.',
                [
                    'stage' => 'provider_capability',
                    'code' => 'NFSE_LAYOUT_CAPABILITY_UNSUPPORTED',
                    'path' => 'payload.tributacao.ibs_cbs',
                    'provider' => $profile->providerKey,
                    'layout_version' => $profile->version,
                ],
            );
        }
        $identification = $emission->identification();
        $service = $emission->service()->toArray();
        $totals = $emission->totals()->toArray();
        $taxation = $emission->taxation()->toArray();
        $amount = (float) ($totals['valor_servicos'] ?? 0);
        $issuer = $this->party($emission->issuer()->toArray(), true);
        $customer = $this->party($emission->customer()->toArray(), false);

        $item = array_filter([
            'codigo' => $service['codigo_servico_municipal'] ?? $service['codigo_servico_nacional'] ?? 'SERV-1',
            'descricao' => $service['descricao'] ?? 'Servico',
            'quantidade' => 1.0,
            'valorUnitario' => $amount,
            'valorTotal' => $amount,
            'codigoServico' => $service['codigo_servico_municipal'] ?? null,
            'codigoTributacaoMunicipal' => $service['codigo_servico_municipal'] ?? null,
            'cTribMun' => $service['codigo_servico_municipal'] ?? null,
            'codigoServicoNacional' => $service['codigo_servico_nacional'] ?? null,
            'cTribNac' => $service['codigo_servico_nacional'] ?? null,
            'cNBS' => $service['codigo_nbs'] ?? null,
            'aliquotaIss' => $taxation['municipal']['aliquota_iss'] ?? null,
            'issRetido' => isset($taxation['municipal']['tipo_retencao_iss'])
                ? in_array((string) $taxation['municipal']['tipo_retencao_iss'], ['2', '3'], true)
                : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return [
            'type' => 'nfse',
            'document_type' => 'nfse',
            'fiscal_environment' => $profile->environment,
            'nota' => [
                'serie' => (string) ($identification['serie'] ?? '1'),
                'numero' => (string) ($identification['numero'] ?? '1'),
                'naturezaOperacao' => (string) ($identification['natureza_operacao'] ?? $service['descricao'] ?? 'Prestacao de servico'),
                'emitente' => $issuer,
                'tomador' => $customer,
                'itens' => [$item],
                'valor_servicos' => $amount,
                'observacoes' => (string) ($emission->observations()['contribuinte'] ?? $emission->observations()['texto'] ?? ''),
            ],
        ];
    }

    /** @param array<string,mixed> $party @return array<string,mixed> */
    private function party(array $party, bool $issuer): array
    {
        $address = is_array($party['endereco'] ?? null) ? $party['endereco'] : [];
        $document = (string) ($party['cpf_cnpj'] ?? '');

        return array_filter([
            'cnpj' => $issuer ? $document : null,
            'documento' => $issuer ? null : $document,
            'nome' => $party['razao_social'] ?? null,
            'razaoSocial' => $party['razao_social'] ?? null,
            'tipo' => $party['tipo_pessoa'] ?? null,
            'inscricaoMunicipal' => $party['inscricao_municipal'] ?? null,
            'inscricaoEstadual' => $party['inscricao_estadual'] ?? null,
            'logradouro' => $address['logradouro'] ?? null,
            'numero' => $address['numero'] ?? null,
            'complemento' => $address['complemento'] ?? null,
            'bairro' => $address['bairro'] ?? null,
            'municipio' => $address['municipio'] ?? null,
            'uf' => $address['uf'] ?? null,
            'cep' => $address['cep'] ?? null,
            'codigoMunicipio' => $address['codigo_municipio'] ?? null,
            'email' => $party['email'] ?? null,
            'telefone' => $party['telefone'] ?? null,
            'endereco' => $address,
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }
}
