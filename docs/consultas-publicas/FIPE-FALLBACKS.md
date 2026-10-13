# Consulta FIPE com fontes alternativas — v2.3.0

A nova consulta pontual usa BrasilAPI → Parallelum → FIPE direta, nesta ordem.
BrasilAPI continua sendo a fonte principal. Parallelum oferece GET documentado e
consulta por código FIPE. A fonte direta usa o SDK opcional Hertzogjr 1.0.0 e exige
marca/modelo selecionados em catálogo; não procura IDs por varredura.
`consultarPrecoFipe()` e os outros contratos de v2.2.0 continuam funcionando.

## Contrato

`ConsultaFipe` recebe tipo (`carros`, `motos`, `caminhoes`), código FIPE no formato
`000000-0`, ano/combustível (`2020-5` ou `32000-1` para zero km), código e mês da
referência retornados pelo catálogo, e opcionalmente o par marca/modelo.
O código da tabela é recebido da fonte; nunca é calculado pelo calendário.

`CadeiaConsultaFipe::consultar()` e `UtilsFacade::consultarPrecoFipeComFallback()`
retornam `FiscalResponse`. Sucesso contém uma lista com exatamente um preço em
português, `fonte`, `tabela_referencia`, `ano_combustivel`, `mes_referencia`,
`data_consulta` e `metadados_consulta` (tentativas e alertas). A resposta completa
vem de uma única fonte e deve corresponder ao tipo, código, ano, combustível e mês
solicitados. Outra referência/veículo ou formato inválido não é exibido/cacheado.
O preço é informativo, não avaliação; não altera cadastros.

A construção da facade sem argumentos e sua injeção anterior permanecem válidas.
Para o método novo, injete uma cadeia com os provedores e `EstadoFipeInterface`:

```php
$http = new GuzzleHttp\Client();
$cadeia = new sabbajohn\FiscalCore\Fipe\CadeiaConsultaFipe([
    new sabbajohn\FiscalCore\Fipe\ProvedorHttpFipe('brasil_api', $http),
    new sabbajohn\FiscalCore\Fipe\ProvedorHttpFipe('parallelum', $http),
    new sabbajohn\FiscalCore\Fipe\ProvedorSdkFipe(),
], $estadoCompartilhado);
$utils = new sabbajohn\FiscalCore\Facade\UtilsFacade(null, $cadeia);
```

`EstadoFipeMemoria` serve a consumo standalone por instância. Workers web devem
injetar cache, reserva atômica de quotas e circuitos compartilhados. O Blank usa
Redis e locks não bloqueantes, falhando fechado se a reserva não for possível.

## Catálogos e orçamento

`CatalogoFipe` suporta referências, marcas, modelos, anos e `anos_codigo`.
Catálogos usam Parallelum → SDK direto. `anos_codigo` depende de Parallelum;
se indisponível, use a seleção hierárquica, que também funciona pela fonte direta.
Cada etapa é uma ação explícita do usuário, sem pré-carregar todos os modelos.
Referências ficam em cache por uma hora; catálogos, por 24 horas.

Um `OrcamentoFipe` compartilhado limita a operação a **10 segundos e quatro
requisições HTTP**, com conexão ≤1s e cada HTTP ≤2,5s. Inclua nele a resolução da
referência. Não há retries automáticos e redirecionamentos estão desabilitados.
Se a resolução do catálogo usar mais de uma fonte, pode consumir o orçamento antes
da terceira tentativa de preço; nesse caso a resposta é indisponível, sem estender
silenciosamente o prazo. O timeout HTTP não limita espera do armazenamento de cache;
a aplicação deve manter timeouts próprios na conexão Redis.

Cache de preço: uma hora, separado por fonte/tipo/código/ano/combustível/mês/tabela
(e IDs quando presentes). Somente sucesso validado é cacheado. Quotas são contadas
por requisição, incluindo catálogos, numa janela móvel de 24h e 60s. Estado em memória:
450/dia e 30/min por fonte HTTP, 60/dia e 6/min direta. Ajuste pelo contrato do seu
provedor e coordene instalações que compartilham IP.

400/404/422 são terminais; não são tratados como falha de disponibilidade. Timeout,
rede, JSON inválido e 5xx permitem fallback, com circuito de 60s. 401/403 preservam
`ACESSO_NEGADO_OU_CONFIGURACAO_INVALIDA` nos alertas, inclusive em cache e falha total;
não tentam corrigir acesso, renovar tokens nem contornar permissões. 401/403/429
abrem circuito por pelo menos 300s; `Retry-After` pode ampliá-lo até 24h. Erros
sanitizados nunca expõem corpos HTTP, credenciais ou mensagens internas do SDK.

## Fontes e qualificação em 08/10/2026

- [BrasilAPI](https://brasilapi.com.br/docs): fonte implantada em v2.2.0; preços e
  referências apresentaram indisponibilidade no smoke anterior. O contrato foi
  preservado. Não há promessa de SLA nesta integração.
- [Parallelum/FipeAPI v2](https://fipe.api.br/docs/api): sem token, limite público
  documentado de 500 chamadas por janela de 24h. Nenhum token/conta foi criado.
  [Termos](https://fipe.api.br/termos-de-uso): proíbem coleta massiva/scraping e
  redistribuição/revenda de acesso. MIT do cliente não licencia o serviço/dataset.
  Smoke público pontual passou para Honda Civic 014090-2/2020-5, tabela 338.
- [Hertzogjr/fipe-php-sdk](https://github.com/Hertzogjr/fipe-php-sdk): SDK não oficial,
  licença MIT, v1.0.0, commit `4e0827668124a7bb98bba2e28bfb523e6a09f7e6`, PHP ^8.4.
  POST para recursos internos de `veiculos.fipe.org.br`. Não há SLA/garantia de
  estabilidade desses endpoints; qualificação operacional e aceite humano seguem
  pendentes. O SDK foi exercitado com transporte Guzzle mockado, sem scraping.

Parallelum e o SDK podem depender da mesma origem FIPE; fallback não garante
independência nem disponibilidade. Consultas retornam modelos/preços públicos e
não usam placa, CPF, CNPJ de cliente ou outros dados pessoais. Não emitir documentos
fiscais para testar esta integração. Não usar coleta em massa.

## Compatibilidade e validação

O core mantém PHP >=8.1. Instalar `hertzogjr/fipe-php-sdk:^1.0` é opcional e exige
PHP >=8.4; `ProvedorSdkFipe` verifica runtime/dependência antes de carregar o SDK.
Blank já exigia PHP ^8.4 antes desta mudança.

```sh
composer validate --strict
php vendor/bin/phpunit tests/Unit/Fiscal/FipeFallbackTest.php tests/Unit/Fiscal/ConsultasPublicasComplementaresTest.php
```

Execute também em uma cópia PHP 8.4 com SDK instalado; todos os testes do SDK devem
rodar sem skips. A CI específica faz isso. Validação local: PHP 8.4.17 e 8.5.5,
47 testes/220 asserções incluindo preço e quatro catálogos pelo SDK real mockado.
A suíte geral apresentou as mesmas 10 falhas de execução e 24 falhas de asserção
na base v2.2.0 e nesta evolução, sem teste falhando apenas no código novo. Há testes
antigos de rede que não respeitam `ENABLE_EXTERNAL_TESTS=false`; não são usados para
homologar os fallbacks. Isso permanece registrado, sem anunciar CI geral aprovado.

Cards relacionados: Blank T-0135/#723, contrato T-0339/#973, implementação
T-0340/#974 e qualificação T-0137/#725 (folhas #979–#981). Publicação não conclui
aceite, qualificação do fornecedor ou QA integrado autenticado.
