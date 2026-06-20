# Status e pendencias do fiscal-core

Data de referencia: 2026-06-20

Este documento consolida o estado atual do pacote e substitui a lista antiga de "proximas features" que ainda tratava itens ja implementados como pendentes.

## Status resumido

| Frente | Status | Evidencia |
| --- | --- | --- |
| Cobertura municipal NFSe | Em progresso | Catalogo ativo em `config/nfse/providers-catalog.json` com 633 entradas; homologacao real segue por municipio |
| Playbook municipal | Concluido | `docs/NFSE-MUNICIPAL-PROVIDER-PLAYBOOK.md` |
| Facades principais | Implementado | `FiscalFacade`, `NFeFacade`, `NFCeFacade`, `NFSeFacade`, `ImpressaoFacade`, `TributacaoFacade`, `UtilsFacade` |
| APIs coesas das facades | Parcial | Facades existem e possuem testes de shape, mas ainda falta fechar contrato semantico uniforme entre modulos |
| NFSe nacional | Implementado com evolucao continua | `NacionalProvider`, catalogo nacional, cache local e testes focados |
| NFSe municipal | Parcial | Providers, families, schemas, resolver e SOAP existem; homologacao real ainda e incremental |
| Impressao | Implementado com variacoes por documento | DANFE/DANFCE e DANFSe nacional/municipal, incluindo URL oficial quando o municipio fornece |
| Tributacao | Implementado com melhorias pendentes | IBPT, NCM e testes de calculo/validacao |
| Cache | Parcial | `FileCacheStore`, cache de catalogo nacional e cache de instancias NFSe; falta politica unificada para consultas remotas e configuracoes |
| Laravel Service Provider | Pendente | Nao ha `ServiceProvider` no pacote |
| Middleware de validacao automatica | Pendente | Nao ha middleware Laravel/Symfony no pacote |
| Packagist | Pendente de alinhamento | Nome canonico definido como `sabbajohn/fiscal-core`; publicar/atualizar este pacote no Packagist |
| GitHub Packages | Pendente | Nao ha workflow/documentacao de publicacao no GitHub Packages |
| Documentacao de Facades/Adapters | Parcial | Referencia inicial de facades existe; falta referencia publica detalhada por adapter/provider |
| CI/Qualidade | Parcial | GitHub Actions e PHPStan inicial existem; falta formatter e meta de coverage |

## Pendencias priorizadas

### 1. Publicar o pacote Composer canonico

- Manter `sabbajohn/fiscal-core` como nome canonico do pacote.
- Publicar/submeter `sabbajohn/fiscal-core` no Packagist usando o checklist em `docs/RELEASE-PACKAGIST.md`.
- Garantir que README, tags e instalacao apontem para `sabbajohn/fiscal-core`.

### 2. Expandir cobertura municipal com homologacao real

- Continuar usando o playbook municipal como fonte canonica.
- Priorizar capitais e municipios AM pendentes listados em `docs/NFSE-MUNICIPIOS-SUPORTADOS-HOMOLOGACAO.md`.
- Para cada municipio: catalogo/family, fixtures, operacoes suportadas, renderer ou URL de impressao, teste focado e documento operacional.
- Nao contar roteamento/catalogo como homologacao real sem evidencia de endpoint municipal.

### 3. Fechar contrato publico das facades

- Uniformizar retorno de emissao, consulta, cancelamento, substituicao, download XML e impressao entre NFe, NFCe e NFSe.
- Definir compatibilidade entre propriedades legadas (`sucesso`, `dados`, `erro`) e metodos novos (`isSuccess()`, `getData()`).
- Documentar por facade quais metodos sao estaveis, experimentais ou legados.
- Revisar o shape publico usado pelo backend Laravel.

### 4. Integracao Laravel

- Criar `FiscalCoreServiceProvider` com publish de config, bindings e aliases opcionais.
- Definir config Laravel para certificados, ambiente, NFSe, cache, timeouts e credenciais IBPT.
- Criar guia Laravel final com exemplos de uso por facade.
- Adicionar testes de bootstrap sem depender de uma aplicacao Laravel completa.

### 5. Middleware de validacao automatica

- Definir escopo: validacao de payload fiscal, certificado/configuracao, municipio/provider ou todas as anteriores.
- Implementar middleware somente depois de estabilizar o contrato das facades e config Laravel.
- Expor erros normalizados sem acoplar regra de negocio do backend ao pacote.

### 6. Cache de consultas e configuracoes

- Promover `FileCacheStore` para politica explicita de cache ou criar contrato de cache injetavel.
- Cobrir consultas remotas de BrasilAPI/IBPT/NFSe nacional com TTL, force refresh e fallback stale quando fizer sentido.
- Definir invalidacao para configuracoes e catalogos.
- Documentar variaveis de ambiente e defaults.

### 7. Documentacao final

- Criar referencia por facade: NFe, NFCe, NFSe, Impressao, Tributacao, Utils e FiscalFacade.
- Criar referencia por adapter/provider quando houver diferenca operacional relevante.
- Revisar README para manter somente quick start, status real e links para guias detalhados.
- Manter `TODO.md` como backlog vivo e evitar duplicar listas divergentes.

### 8. DevOps

- Manter GitHub Actions para testes criticos em PHP 8.1 e 8.2.
- Manter PHPStan em nivel inicial ate reduzir divida tecnica.
- Definir formatter (`php-cs-fixer`, Pint ou equivalente).
- Publicar tags com changelog e processo de release.
- Documentar GitHub Packages se a publicacao for mantida alem do Packagist.

## Ja nao deve aparecer como pendente generico

- "Facades" como conceito: as classes existem e sao usadas.
- "Cache" como inexistente: ja ha cache local em partes do fluxo, mas falta politica unificada.
- "Publicar no Packagist" como concluido se o pacote publicado nao for `sabbajohn/fiscal-core`: publicacoes antigas com outro vendor devem ser tratadas como legado/desalinhamento.
- "Expandir cobertura municipal" sem criterio: a expansao deve seguir playbook, tracker e evidencia de homologacao.
