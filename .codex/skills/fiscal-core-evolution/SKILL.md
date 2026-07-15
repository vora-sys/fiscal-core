---
name: fiscal-core-evolution
description: Use esta skill quando a tarefa envolver evolucao do fiscal-core em NFSe, onboarding de providers municipais pelo Brasil, consolidacao do provider nacional, suporte a eventos fiscais, ajustes de contratos/facades de documentos fiscais ou definicao de roadmap tecnico para estas frentes.
---

# Fiscal Core Evolution

Use esta skill para orientar mudancas arquiteturais e incrementais no `fiscal-core` quando o trabalho tocar NFSe municipal, NFSe nacional, eventos fiscais e contratos publicos da biblioteca.

## Raiz canonica

- Trate `fiscal-platform-api/app/Library/fiscal-core` como a unica fonte editavel do `fiscal-core`.
- Resolva os caminhos `src/`, `config/`, `docs/` e `tests/` desta skill a partir dessa raiz incorporada.
- Nao replique mudancas na antiga pasta irma `Fiscal/fiscal-core`; use-a apenas como referencia historica quando a comparacao for explicitamente necessaria.
- Ao alterar integracao Laravel, continue a partir da raiz `fiscal-platform-api` e mantenha o core incorporado e a camada `app/` coerentes no mesmo change set.

## Resultado esperado

Antes de editar codigo:

1. Classifique a mudanca.
2. Descubra os pontos de extensao reais do repo.
3. Decida o menor delta que preserva contratos e reuso.
4. Defina evidencias minimas de teste e documentacao.

## Classificacao inicial

Classifique a demanda em uma destas trilhas:

- `provider-municipal`: onboarding de municipio, familia SOAP, schema package, payloads e homologacao.
- `provider-nacional`: evolucao do fluxo nacional REST, parametrizacao municipal, DANFSe, download, cancelamento e substituicao.
- `eventos-fiscais`: cancelamento, substituicao, manifestacoes, registro de evento e modelos de capacidade por documento/provider.
- `contrato-facade`: mudancas em `Facade`, `Contracts`, normalizacao de resposta e compatibilidade publica.
- `catalogo-config`: ajustes de catalogos, families, overrides, aliases e resolucao de municipio.
- `roadmap-arquitetural`: definicao de backlog, gaps, prioridades e estrategia de convergencia.

Se a demanda cair em mais de uma trilha, trate primeiro a que altera contrato publico ou roteamento.

## Leitura minima por trilha

- Para `provider-municipal`, leia [references/provider-decision-tree.md](references/provider-decision-tree.md).
- Para `eventos-fiscais`, leia [references/event-evolution.md](references/event-evolution.md).
- Para localizar touchpoints no repo, leia [references/repo-map.md](references/repo-map.md).

Leia arquivos adicionais do repo apenas quando a trilha exigir.

## Regras de evolucao

- Preserve contratos publicos existentes sempre que possivel; expanda comportamento antes de quebrar assinatura.
- Prefira reuso de familia, `provider_config_overrides` e catalogo antes de criar novo provider concreto.
- Nao trate "municipio novo" como sinonimo de "provider novo".
- Nao trate "evento" como um detalhe do provider. Modele capacidade, payload, validacao e introspeccao de forma explicita.
- Separe claramente NFSe e NFe quando o conceito for parecido mas o protocolo for diferente. `ManifestationType` atual representa manifestacao do destinatario de NFe, nao um modelo generico de eventos fiscais.
- Quando adicionar suporte novo, atualize ao mesmo tempo: implementacao, contrato exposto, testes focados e documentacao operacional.

## Workflow canonico

1. Identifique se a mudanca altera contrato, roteamento, provider ou apenas configuracao.
2. Mapeie os arquivos afetados com `rg` antes de propor classes novas.
3. Reuse `config/nfse/providers-catalog.json` e `config/nfse/nfse-provider-families.json` como fonte primaria de roteamento NFSe.
4. Se houver evento novo, defina:
   - tipo do evento
   - documento alvo
   - quem suporta a operacao
   - formato do payload
   - estrategia de assinatura
   - estrategia de introspeccao e normalizacao
5. Implemente no menor ponto de extensao coerente:
   - config/catalogo
   - provider concreto
   - facade/adapter
   - support/normalizer
   - contrato novo apenas se o gap for real
6. Valide com testes focados antes de ampliar a cobertura.

## Checklist de entrega

- O roteamento continua explicito e auditavel.
- A mudanca nao duplica responsabilidade entre facade, adapter e provider.
- O comportamento novo tem fixture ou teste de unidade/integracao representativo.
- A documentacao operacional aponta limites reais, especialmente em homologacao e dependencia de endpoint municipal.
- Se houver lacuna arquitetural nao resolvida, registre como backlog tecnico no resultado final.

## Comandos uteis

```bash
cd fiscal-platform-api
rg -n "NFSe|Provider|evento|cancelar|substituir|manifest" app/Library/fiscal-core/{src,config,docs,tests}
php -d memory_limit=512M vendor/bin/phpunit app/Library/fiscal-core/tests --filter NFSe
php -d memory_limit=512M vendor/bin/phpunit app/Library/fiscal-core/tests --filter NFe
```

## Notas de contexto

- O repo ja tem playbook e matriz para NFSe municipal; nao recrie estes guias dentro da skill.
- A skill deve orientar decisoes e implementacoes, nao substituir a leitura dos contratos e classes reais.
- Se a documentacao mencionar scripts inexistentes no repo atual, trate isso como gap documental e nao como capacidade disponivel.
