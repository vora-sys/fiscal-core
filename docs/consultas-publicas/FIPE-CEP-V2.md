# Consultas públicas: FIPE e CEP v2

Implementação assistida em 08/10/2026. Cards relacionados: Blank T-0135 #723,
T-0339 #973 (contrato/fixtures), T-0340 #974 (fatia vertical); qualificação de
fontes T-0137 #725 e #979/#980/#981. Esta entrega local não representa aceite
humano, mudança de sprint/política, publicação de pacote ou conclusão de card.

## Fonte e recorte

Raiz editável: `app/Library/FiscalCore` do fiscal-platform-api. O rascunho de John
em `Fiscal/fiscal-core/main`, `fiscal-platform-api/dev` e no vendor ignorado do Blank é equivalente: quatro
métodos novos apenas em BrasilAPIAdapter. Os checkouts originais foram preservados.
A evolução fica na branch `codex/consultas-publicas-fipe-20261008`, cópia isolada
baseada em `3e800181`. Não pertence ao épico de arquitetura nem altera integração.

Fonte primária verificada em 08/10/2026, árvore oficial `0104c49c94017e47560615c8713ba7686ae2e381`:

- https://github.com/BrasilAPI/BrasilAPI/blob/main/pages/docs/doc/fipe.json
- https://github.com/BrasilAPI/BrasilAPI/blob/main/pages/api/cep/v2/%5Bcep%5D.js
- https://brasilapi.com.br/docs
- SDK: https://github.com/andreoneres/brasilapi-php (versão instalada 1.2).

BrasilAPI intermedeia preços FIPE; disponibilidade e atualização dependem da fonte.
Não é emissão fiscal nem pesquisa de pessoa. Não houve teste externo com dados
pessoais. Fixtures FIPE derivam do exemplo da documentação oficial; CEP v2 é
fixture sintética de endereço público com coordenadas ilustrativas.

## Contrato da biblioteca

`UtilsFacade` mantém construtor sem argumentos e aceita opcionalmente um
BrasilAPIAdapter para injeção/testes. Todas as consultas novas retornam FiscalResponse.
Interface complementar separada preserva ConsultaPublicaInterface para terceiros.

| Operação | Entrada | Saída de sucesso |
| --- | --- | --- |
| consultarCEPv2 | CEP de 8 dígitos, hífen opcional | cep, logradouro, bairro, localidade, uf, servico, fuso_horario, localizacao {tipo, coordenadas {latitude,longitude}}, fonte |
| listarTabelasReferenciaFipe | nenhuma | lista de {codigo,mes}, contrato original BrasilAPI |
| consultarMarcasPorTipoVeiculo | carros/motos/caminhoes; tabela inteira positiva opcional | lista de {nome,valor}, contrato original BrasilAPI |
| consultarPrecoFipe | código `000000-0`; tabela inteira positiva opcional | lista de preços por ano/combustível em português |

Preço: codigo_fipe, valor (string monetária, sem float), marca, modelo, ano_modelo,
combustivel, mes_referencia, tipo_veiculo, sigla_combustivel, data_consulta,
tabela_referencia (null indica pedido pela tabela atual), fonte=brasil_api.
O mês do resultado deve ser exibido; null não comprova qual código de tabela foi
selecionado pelo provedor. Não escolher automaticamente um ano/combustível.

CEP v2 não garante código IBGE; nenhum código é inventado. Localização e
coordenadas continuam aninhadas; ausência gera null. Não substituir o CEP v1
nas rotinas que precisam de IBGE. Nenhum cast de array para string é necessário.

Erros novos: INVALID_ARGUMENT (validação local/HTTP400), CONSULTA_NAO_ENCONTRADA
(HTTP404), RESPOSTA_INVALIDA (JSON vazio/malformado ou formato incompatível),
CONSULTA_INDISPONIVEL (timeout/rede/429/5xx). O adapter preserva a causa da falha
sem expor seu corpo/mensagem ao consumidor. Timeout padrão: conexão 3s, total 8s.
Não há retry automático nem dados simulados em resposta de sucesso.

Os aliases consutaTabelaReferencia, consutaPrecoFipe e
consutaMarcasPorTipoVeiculo do rascunho são preservados no adapter. Os nomes
corretos são a API recomendada. A query tabela_referencia usa opção Guzzle `query`;
os métodos FIPE do SDK 1.2 enviam essa opção no nível errado.

## Validação e distribuição

```sh
composer validate --strict
php -d memory_limit=512M vendor/bin/phpunit tests/Unit/Fiscal/ConsultasPublicasComplementaresTest.php
```

A evolução vem da raiz canônica `fiscal-platform-api/app/Library/FiscalCore`.
Esta distribuição aplica somente adapter, contrato complementar e delta da
UtilsFacade, preservando os demais métodos publicados, inclusive o mapeamento CNPJ.
A sincronização completa do módulo foi evitada porque incluiria outras alterações
de emissão fiscal fora deste recorte.

Os nove testes novos passam com 55 asserções e transporte simulado. Não fazem
consultas externas. `composer validate --strict` passa. Na execução local em PHP
8.5.5, a suíte ampla reproduziu exatamente as falhas da base anterior: 10 erros e
24 falhas; o PHPStan reproduziu os mesmos oito erros em arquivos de impressão/NFSe,
sem nova ocorrência nos arquivos de consultas. A matriz PHP 8.1/8.2 do CI ainda
precisa de verificação. Essa limitação não deve ser descrita como CI totalmente verde.

O Blank importa `sabbajohn\FiscalCore` via Composer. Precisa atualizar seu
`composer.lock` para uma versão que inclua estes contratos; publicar uma release
do pacote não atualiza automaticamente o aplicativo. Não copiar arquivos para
vendor de produção.

## Aceite ainda pendente

Aceite humano do contrato #973 e qualificação de fonte #725/#979–#981;
QA autenticada do painel em ambiente de John. A publicação do código e seus testes
não removem dependências/políticas dos cards.
