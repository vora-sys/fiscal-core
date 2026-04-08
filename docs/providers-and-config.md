# Providers e Configuracao NFSe

## Fonte de verdade

O runtime de NFSe usa apenas os arquivos abaixo:

- `config/nfse/providers-catalog.json`
- `config/nfse/nfse-provider-families.json`

Fluxo canonico:

`providers-catalog.json` -> `NFSeProviderResolver` -> `ProviderRegistry` -> `NFSeRuntimeBootstrap` -> provider concreto

## Responsabilidade de cada arquivo

### `config/nfse/providers-catalog.json`

Define o roteamento por municipio.

Cada entrada informa:

- `slug`
- `nome`
- `uf`
- `provider_family`
- `schema_package`
- `ibge`
- `active`

Regras praticas:

- `provider_family` precisa apontar para uma chave existente em `nfse-provider-families.json`
- `schema_package` pode permanecer diferente da chave do provider quando o layout tecnico for compartilhado
- overrides locais devem aparecer tambem em `config/nfse/nfse-catalog-manifest.json`

### `config/nfse/nfse-provider-families.json`

Define a familia tecnica e os parametros operacionais de cada provider.

Campos comuns:

- `provider_class`
- `layout_family`
- `schema_root`
- `xsd_entrypoints`
- `transport`
- `versao`
- `timeout`
- `auth`

No caso de `nfse_nacional`, este arquivo tambem concentra:

- `services`
- `endpoints`
- `operation_methods`
- `catalog_endpoints`
- `cnc_endpoints`
- URLs de homologacao e producao

## Exemplos importantes

- `nfse_nacional`: provider REST canonico do ambiente nacional
- `BELEM_MUNICIPAL_2025`: override municipal especifico
- `ISSWEB_AM`: familia compartilhada usada por Presidente Figueiredo e Rio Preto da Eva

## Manaus

- `manaus` nao usa mais a familia historica `MANAUS_AM` no roteamento ativo
- o catalogo resolve Manaus para `nfse_nacional`
- o provider municipal antigo permanece apenas como referencia historica de implementacao

## Como adicionar ou ajustar um municipio

1. Atualize o municipio em `config/nfse/providers-catalog.json`.
2. Garanta que a `provider_family` exista em `config/nfse/nfse-provider-families.json`.
3. Se houver override manual, sincronize `config/nfse/nfse-catalog-manifest.json`.
4. Rode os testes de resolver, registry, adapter e facade NFSe.

## Observacao sobre legado

`config/nfse-municipios.json` nao faz mais parte do runtime e nao deve ser usado como referencia para novas manutencoes.
