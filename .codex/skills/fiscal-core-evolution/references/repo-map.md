# Repo Map

Use este mapa para localizar o ponto de extensao certo antes de editar.

## Roteamento e configuracao NFSe

- `config/nfse/providers-catalog.json`: municipios, aliases, `provider_family`, `schema_package`, overrides e status.
- `config/nfse/nfse-provider-families.json`: definicao tecnica por familia, endpoints, operacoes, transporte, assinatura e schemas.
- `config/nfse/nfse-catalog-manifest.json`: manifest e overrides auxiliares do catalogo.
- `config/nfse-municipios.json`: configuracoes legadas e referencias adicionais do nacional.

## Runtime e resolucao

- `src/Support/NFSeProviderResolver.php`: resolve municipio e provider key.
- `src/Support/ProviderRegistry.php`: instancia providers a partir das families.
- `src/Support/NFSeRuntimeBootstrap.php`: injeta ambiente, certificado e config.
- `src/Support/NFSeMunicipalPayloadFactory.php`: payloads de preview/homologacao.
- `src/Support/NFSeMunicipalHomologationService.php`: fluxo de homologacao e introspeccao.
- `src/Support/NFSeResultNormalizer.php`: normalizacao de retornos NFSe.

## Camada publica

- `src/Facade/NFSeFacade.php`: contrato publico principal para emissao, consulta, cancelamento, substituicao, impressao e introspeccao.
- `src/Adapters/NF/NFSeAdapter.php`: ponte entre facade e provider.
- `src/Contracts/NFSeProviderInterface.php`: contrato minimo de provider.
- `src/Contracts/NFSeNacionalCapabilitiesInterface.php`: capacidades especificas do provider nacional.
- `src/Contracts/NFSeOperationalIntrospectionInterface.php`: introspeccao operacional.

## Providers

- `src/Providers/NFSe/NacionalProvider.php`: fluxo REST do nacional, parametrizacao e operacoes adicionais.
- `src/Providers/NFSe/Municipal/*.php`: providers municipais concretos.
- `src/Providers/NFSe/AbstractNFSeProvider.php`: comportamento comum.

## Eventos e modelos correlatos

- `src/Providers/NFSe/Xsd/evento_v1.00.xsd`
- `src/Providers/NFSe/Xsd/pedRegEvento_v1.00.xsd`
- `src/Providers/NFSe/Xsd/tiposEventos_v1.00.xsd`
- `src/Support/ManifestationType.php`: manifestacao do destinatario de NFe, nao de NFSe.
- `src/Facade/NFeFacade.php`: referencia para eventos ja expostos em NFe.

## Documentacao operacional

- `docs/NFSE-MUNICIPAL-PROVIDER-PLAYBOOK.md`: processo canonico para providers municipais.
- `docs/NFSE-PROVIDER-MATRIX.md`: matriz de familias ativas e gaps.
- `docs/nfse-providers/*.md`: fichas especificas por familia/provider.
- `docs/NFSE-MIGRACAO-MUNICIPAL-PARA-NACIONAL.md`: regra de migracao para o nacional.

## Testes

- `tests/Unit/NFSe/`: contratos, facade e providers.
- `tests/Integration/`: fluxos maiores e mocks/reais.
- `tests/Fixtures/`: payloads e respostas sanitizadas.

Use este mapa para reduzir mudancas espalhadas e evitar criar abstrações paralelas.
