# Providers e Configuracao NFSe

## Fonte de verdade

O runtime de NFSe usa apenas os arquivos abaixo:

- `config/nfse/providers-catalog.json`
- `config/nfse/nfse-provider-families.json`
- `config/nfse/municipio-provider-overrides.json` (opcional, para hot swap operacional)

Fluxo canonico:

`providers-catalog.json` + `municipio-provider-overrides.json` -> `NFSeProviderResolver` -> `ProviderRegistry` -> `NFSeRuntimeBootstrap` -> provider concreto

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
- `provider_config_overrides` opcional
- `payload_defaults` opcional

Regras praticas:

- `provider_family` precisa apontar para uma chave existente em `nfse-provider-families.json`
- `schema_package` pode permanecer diferente da chave do provider quando o layout tecnico for compartilhado
- `provider_config_overrides` aplica merge recursivo sobre a familia no `ProviderRegistry`
- `payload_defaults` serve para exemplos/homologacao e nao substitui a configuracao operacional da familia
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

### `config/nfse/municipio-provider-overrides.json`

Define overrides operacionais temporários para troca rápida de provider por município, sem editar o catálogo base.

Campos por override:

- `provider_family` (ou `provider_key`)
- `active`
- `reason` (opcional)
- `ticket` (opcional)
- `updated_at` (opcional)

Comportamento:

- quando ativo e válido, o override substitui o `provider_family` resolvido do município.
- quando inválido (provider inexistente), o runtime mantém o provider do catálogo e retorna warning de override ignorado.

## Exemplos importantes

- `nfse_nacional`: provider REST canonico do ambiente nacional
- `BELEM_MUNICIPAL_2025`: override municipal especifico
- `ISSWEB_AM`: familia compartilhada ativa para Presidente Figueiredo/AM e Rio Preto da Eva/AM
- `PUBLICA`: familia compartilhada com defaults municipais leves em Joinville

## Politica global de MEI

- emissao classificada como MEI usa sempre `nfse_nacional`
- a classificacao continua vindo do payload do prestador
- municipios que exigem classificacao explicita devem declarar isso em config via `requires_explicit_mei_classification`

## Manaus

- `manaus` nao usa mais a familia historica `MANAUS_AM` no roteamento ativo
- o catalogo resolve Manaus para `nfse_nacional`
- o provider municipal antigo permanece apenas como referencia historica de implementacao

## Como adicionar ou ajustar um municipio

1. Atualize o municipio em `config/nfse/providers-catalog.json`.
2. Garanta que a `provider_family` exista em `config/nfse/nfse-provider-families.json`.
3. Se houver override manual, sincronize `config/nfse/nfse-catalog-manifest.json`.
4. Se houver scaffold novo, use `scripts/nfse/scaffold-municipio.php` e `scripts/nfse/scaffold-family.php`.
5. Rode os testes de resolver, registry, adapter e facade NFSe.

## Hot swap rápido (operacional)

Script utilitário:

- `scripts/nfse/provider-switch.php --list`
- `scripts/nfse/provider-switch.php --set --municipio=presidente-figueiredo --provider=ISSWEB_AM --reason="rollback endpoint"`
- `scripts/nfse/provider-switch.php --remove --municipio=presidente-figueiredo`

Use `--dry-run` para simular sem gravar.

## Tabela de cobertura por provider

Documento consolidado:

- `docs/NFSE-PROVIDERS-MUNICIPIOS.md`

Geração/atualização:

- `php scripts/nfse/generate-providers-municipios-doc.php`
- `php scripts/nfse/generate-providers-municipios-doc.php --output=docs/NFSE-PROVIDERS-MUNICIPIOS.md`

## Reconciliação com Uninfe

Use o repositório `Uninfe` local como fonte auxiliar de mapeamento de provedores municipais.

Atualização do checkout local:

- `git -C Uninfe fetch origin`
- `git -C Uninfe checkout main`
- `git -C Uninfe pull --ff-only`

Reconciliar catálogo atual com a base do Uninfe:

- `php scripts/nfse/reconcile-uninfe-providers.php`
- `php scripts/nfse/reconcile-uninfe-providers.php --format=md --output=docs/NFSE-UNINFE-RECONCILIACAO.md`
- `php scripts/nfse/reconcile-uninfe-providers.php --fail-on-unexpected`

Leitura do resultado:

- `missing_in_catalog` precisa ficar `0`.
- `divergences_unexpected` precisa ficar `0`.
- divergencias esperadas atuais: municipios migrados para `nfse_nacional` e alias legado `DSF` convergido para ABRASF.

## Status NFePHP (phpnfe)

Validação executada em `2026-05-25`:

- `composer outdated 'nfephp-org/*' --direct`: sem pendências.
- `composer outdated 'nfephp-org/*'`: sem pendências diretas e transitivas.

Versões efetivas no lock:

- `nfephp-org/sped-nfe`: `v5.2.5`
- `nfephp-org/sped-common`: `v5.1.16`
- `nfephp-org/sped-da`: `v1.1.6`
- `nfephp-org/sped-gtin`: `v1.1.2`
- `nfephp-org/sped-ibpt`: `v2.0.2`

## Observacao sobre legado

`config/nfse-municipios.json` nao faz mais parte do runtime e nao deve ser usado como referencia para novas manutencoes.
