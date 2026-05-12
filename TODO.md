# TODO - fiscal-core

Data de referencia: 2026-05-12

## Status geral

`fiscal-core` esta em estado intermediario: a base de adapters, facades, providers NFSe, normalizadores e testes cresceu bastante desde o roadmap antigo, mas o pacote ainda nao deve ser tratado como camada NFSe totalmente fechada.

Resumo atual:

- Versao local: `1.2.2`.
- Consumido pelo backend Laravel por path repository.
- NF-e/NFC-e possuem adapters/facades e testes de formato de resposta.
- NFSe possui provider nacional, providers municipais, catalogo municipal, resolver, renderers e transporte SOAP.
- O provider generico ABRASF v2 ja possui base funcional de XML, assinatura configuravel, SOAP e parser basico.
- A suite focada de NFSe esta verde neste corte.

Validacao executada neste corte:

- Comando: `vendor/bin/phpunit --testsuite NFSe`
- Resultado: `92 tests`, `438 assertions`
- Status: `OK`
- Falha corrigida: `ProviderConfigTest::testFacadeMapsManausToNationalProvider`
- Deprecations corrigidas: remocao de `ReflectionMethod::setAccessible()` nos testes NFSe
- Warning de coverage local removido da configuracao padrao do PHPUnit
- `AbrasfV2Provider` agora monta XML ABRASF v2, assina XML quando configurado, despacha SOAP para emissao/consulta/cancelamento/substituicao e expoe resultado operacional normalizado na introspeccao.

## Prioridade imediata

### 1. Manter suite NFSe limpa

- [x] Corrigir `ProviderConfigTest::testFacadeMapsManausToNationalProvider`.
- [x] Revisar configuracao/capabilities de Manaus no mapeamento nacional.
- [x] Tratar as 2 deprecations restantes.
- [x] Decidir configuracao de coverage local para evitar warning sem driver.
- [x] Rodar novamente `vendor/bin/phpunit --testsuite NFSe`.
- [ ] Registrar decisao no documento NFSe correspondente se o comportamento esperado mudou.

### 2. Fechar contrato NFSe generico

- [x] Completar base funcional de `src/Providers/NFSe/AbrasfV2Provider.php`.
- [x] Implementar montagem XML ABRASF v2 real em `montarXmlRps()`.
- [x] Implementar parser ABRASF basico em `processarResposta()`.
- [x] Integrar ABRASF v2 generico com transporte SOAP para emissao, consulta por numero/RPS e cancelamento.
- [ ] Definir se `AbstractNFSeProvider::emitir()` deve continuar como fallback ou exigir provider concreto nos demais providers.
- [x] Expor `normalized_result` para operacoes NFSe via `NFSeResultNormalizer` no adapter/facade.
- [ ] Decidir se o contrato publico de emissao/cancelamento/substituicao deve migrar de `string`/`bool` para objeto de resultado em versao futura.

### 3. Transporte, assinatura e operacoes

- [ ] Integrar envio SOAP em providers que dependem do padrao base.
- [x] Integrar assinatura digital ABRASF generica quando exigida pelo provider.
- [x] Fechar introspeccao de consulta/cancelamento/substituicao com resultado normalizado quando o provider expoe resposta parseada.
- [x] Fechar substituicao ABRASF generica quando suportada pelo municipio/provider.

## Roadmap por feature

### 1. NFSe nacional e municipal

Status: em progresso.

Concluido:

- [x] Provider nacional (`NacionalProvider`).
- [x] Providers municipais em `src/Providers/NFSe/Municipal/`.
- [x] Resolver de provider (`NFSeProviderResolver`).
- [x] Catalogo/manifesto municipal em `config/nfse/`.
- [x] Support para payload municipal.
- [x] Transporte SOAP via `NFSeSoapCurlTransport`.
- [x] Normalizacao de resultado via `NFSeResultNormalizer`.
- [x] Renderers DANFSE nacional/municipais.
- [x] Testes unitarios e de integracao para varios fluxos NFSe.

Pendente:

- [x] Corrigir falha funcional da suite NFSe atual.
- [x] Tratar deprecations restantes da suite NFSe.
- [x] Completar base XML/parser do ABRASF v2 generico.
- [x] Integrar ABRASF v2 generico com transporte SOAP quando usado diretamente.
- [x] Integrar assinatura ABRASF v2 generica quando exigida por municipio/provider.
- [x] Integrar substituicao ABRASF v2 generica com SOAP, assinatura configuravel e introspeccao no adapter/facade.
- [ ] Uniformizar contrato publico operacional entre providers nacional e municipais.
- [ ] Expandir matriz de municipios suportados com playbook e fixtures.

### 2. Certificado digital

Status: funcionando com pendencias.

- [x] `CertificateManager`.
- [x] `SafeCertificateManager`.
- [x] Integracao com configuracao.
- [x] Testes de singleton/managers.
- [ ] Resolver caso OpenSSL legacy para certificados especificos.
- [ ] Definir suporte A3 ou documentar explicitamente fora de escopo.
- [ ] Cobrir assinatura NFSe nos providers que exigem XML assinado.

### 3. Configuracao

Status: funcionando com evolucao pendente.

- [x] `ConfigManager`.
- [x] `SafeConfigManager`.
- [x] Catalogos JSON de NFSe.
- [x] Configuracao por providers/familias.
- [ ] Cache controlado de configuracao.
- [ ] Guia final de configuracao Laravel.
- [ ] Validacao de configuracao por provider antes de emissao real.

### 4. Facades e adapters

Status: implementados, nao mais apenas stubs.

- [x] `FiscalFacade`.
- [x] `NFeFacade`.
- [x] `NFCeFacade`.
- [x] `NFSeFacade`.
- [x] `ImpressaoFacade`.
- [x] `TributacaoFacade`.
- [x] `UtilsFacade`.
- [x] Adapters principais para documentos, NFe, NFCe, NFSe, impressao, IBPT/GTIN e BrasilAPI.
- [ ] Padronizar tratamento de erros em todos os adapters.
- [ ] Completar logs/diagnostico.
- [ ] Revisar shape publico de respostas para compatibilidade semantica com backend.

### 5. GTIN, BrasilAPI e utilitarios

Status: funcionando com melhorias pendentes.

- [x] Validador/adapter GTIN.
- [x] BrasilAPI adapter.
- [x] UtilsFacade.
- [x] Testes de mapeamento CNPJ e utilitarios.
- [ ] Cache de consultas remotas.
- [ ] `buscarProduto()` completo.
- [ ] Politica de timeout/retry uniforme.

### 6. Testes

Status: parcial, mas substancialmente melhor que o corte antigo.

- [x] Testes unitarios de DTOs, builders, nodes, facades e tributacao.
- [x] Testes NFSe unitarios e de integracao.
- [x] Testes de provider resolver/catalogo municipal.
- [x] Testes de shape de resposta NFe/NFCe/NFSe.
- [x] Corrigir a falha funcional atual da suite NFSe.
- [x] Tratar as 2 deprecations restantes.
- [ ] Separar testes externos reais de homologacao com flag clara.
- [ ] Definir meta de cobertura realista por modulo.
- [ ] Adicionar CI para rodar suites criticas.

### 7. Documentacao

Status: em progresso.

- [x] README principal.
- [x] Arquitetura.
- [x] Providers/config.
- [x] Playbook NFSe municipal.
- [x] Matrix de providers NFSe.
- [x] Documentos especificos para Belem, Manaus/Nacional, ISSWeb e migracao municipal/nacional.
- [ ] API reference final.
- [ ] Guia Laravel final.
- [ ] Guia de troubleshooting por provider.
- [ ] Changelog por versao.

### 8. DevOps e publicacao

Status: planejado.

- [ ] GitHub Actions.
- [ ] PHPStan.
- [ ] PHP-CS-Fixer ou Pint equivalente.
- [ ] Relatorio de coverage.
- [ ] Semantic versioning aplicado com changelog.
- [ ] Preparar Packagist/GitHub Packages.

## Bugs conhecidos

- [x] Suite NFSe nao falha mais em `ProviderConfigTest::testFacadeMapsManausToNationalProvider`.
- [x] Suite NFSe nao reporta mais deprecations no corte atual.
- [x] Suite NFSe em modo padrao nao reporta mais warning de coverage sem driver local.
- [ ] OpenSSL legacy: `error:0308010C:digital envelope routines::unsupported`.
- [ ] Coverage indisponivel no ambiente local atual.
- [x] `AbrasfV2Provider` deixou de retornar XML placeholder.
- [ ] `AbstractNFSeProvider` mantem consulta/cancelamento/substituicao pendentes no fallback base.

## Proxima sequencia recomendada

1. Decidir contrato publico futuro para retorno de emissao, cancelamento e substituicao sem quebrar compatibilidade.
2. Especializar substituicao por municipio/provider quando o contrato municipal divergir da base ABRASF.
3. Rodar suites `NFSe`, `NFe`, `Tributacao` e `Integration` sem testes externos reais.
4. Atualizar docs NFSe com a matriz final de suporte por municipio/provider.

## Arquivos de retomada

- `src/Providers/NFSe/AbrasfV2Provider.php`
- `src/Providers/NFSe/AbstractNFSeProvider.php`
- `src/Providers/NFSe/NacionalProvider.php`
- `src/Providers/NFSe/Municipal/`
- `src/Support/NFSeProviderResolver.php`
- `src/Support/NFSeResultNormalizer.php`
- `src/Support/NFSeSoapCurlTransport.php`
- `config/nfse/`
- `docs/NFSE-PROVIDER-MATRIX.md`
- `docs/NFSE-MUNICIPAL-PROVIDER-PLAYBOOK.md`
- `tests/Unit/NFSe/ProviderConfigTest.php`

## Observacao de arquitetura

`fiscal-core` deve continuar focado em provider, XML/DPS, transporte, certificados, parsing e normalizacao de resposta. Decisao de negocio fiscal, CFOP, regra tributaria, perfil, aliquota por empresa e snapshot autoritativo pertencem ao backend Laravel em `invoice-flow/backend/laravel-api`.
