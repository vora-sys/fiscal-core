# Sincronização do Fiscal Core

## Conciliação de 13/10/2026

Referências de origem: API `stagging` em `f0aa31b9`; standalone v2.2.0 em
`47a4c224`; fallbacks FIPE em `d75b6b7a` (`codex/fipe-fallbacks-20261008`).
A inclusão dos fallbacks foi solicitada expressamente nesta conciliação.

O export anterior havia igualado as árvores de runtime, mas removido consultas
CEP v2, FIPE e o contrato `ConsultaPublicaComplementarInterface` do pacote. Essas
melhorias foram recuperadas do histórico Git. A combinação de `7a96970` como base,
API atual e branch de fallbacks produziu merges sem conflitos nos adapters de
consulta e em `UtilsFacade`.

Foram preservadas as mudanças da API: monofasia IBS/CBS, CNPJ alfanumérico,
descontos e acréscimos nos totais, valores opcionais zero, DANFE e impressão
térmica, identidade e endereço exterior do tomador NFS-e. Os demais assets já
eram iguais após normalização de namespace.

`CnpjIdentity` e `FederalTaxIdentity` passaram para `FiscalCore\Support`. As classes
da aplicação conservam seus nomes e métodos por herança, e o pacote passa a
executar essas regras sem depender de `App\Services`. Guzzle é uma dependência
direta nos dois projetos. O standalone declara também Endroid QR Code, utilizado
pelo DANFSe existente; o lock incorpora somente essa dependência e suas duas
dependências transitivas.

## Operação segura

Execute os comandos na raiz de `fiscal-platform-api`.

```sh
php tools/fiscal-core-sync.php check
php tools/fiscal-core-sync.php sync
php tools/fiscal-core-sync.php sync --apply
```

O baseline versionado contém hashes SHA-256 dos arquivos com namespace do pacote.
A comparação usa três estados: baseline, módulo da API e pacote. Mudança em
apenas um lado pode ser propagada; mudanças idênticas nos dois lados são aceitas;
mudanças diferentes exigem conciliação humana, incluindo remoção versus edição.
Nenhum arquivo do plano é escrito quando há conflito. Falhas de I/O durante a
gravação exigem inspeção das duas árvores e nova verificação; o baseline só é
atualizado após confirmação de igualdade.

Sem baseline, uma divergência bloqueia a escrita. Após uma conciliação manual,
`sync --apply` em árvores iguais estabelece um novo baseline. Versione esse arquivo
junto com o runtime correspondente. Para exportações em um único sentido,
`export --apply` bloqueia arquivos que tenham mudanças independentes no pacote.

O sincronizador opera exclusivamente em `src`, `config`, `resources` e
`openssl.cnf`. Composer, fixtures, testes, docs e scripts mantêm a organização
própria de cada projeto e precisam ser revisados separadamente.

## Consultas recuperadas

- CEP v2 preserva a localização aninhada e campos opcionais sem inventar IBGE.
- FIPE preserva valor monetário textual, mês, código, combustível e referência.
- `UtilsFacade` aceita um `BrasilAPIAdapter` injetado e uma `CadeiaConsultaFipe`
  opcional. Instanciá-la sem argumentos continua compatível.
- `consultarPrecoFipeComFallback()` usa a cadeia configurada pelo consumidor;
  não ativa fontes externas por conta própria. A ordem usual é BrasilAPI,
  Parallelum e FIPE direta, com quotas, circuitos, cache e orçamento de tempo.
- A fonte direta requer PHP 8.4+ e `hertzogjr/fipe-php-sdk:^1.0`, uma dependência
  opcional declarada em `suggest`. Sem ela, BrasilAPI/Parallelum continuam
  disponíveis e a ausência do SDK tem erro explícito.
- `EstadoFipeMemoria` serve a processos isolados. Workers web precisam injetar
  uma implementação compartilhada de `EstadoFipeInterface` para cache e quotas.

O guia detalhado está em `fiscal-core/docs/consultas-publicas/FIPE-FALLBACKS.md`.
A sincronização não instala o SDK opcional no runtime dos projetos. Para verificar
a integração com o SDK, esta conciliação usou um consumidor Composer temporário,
PHP 8.4 e transporte Guzzle mockado, sem consultas ou emissão externas.

## Verificação

Os testes da sincronização usam árvores temporárias independentes: dry-run,
bootstrap do baseline, import/export, tradução PHP/JSON, remoções, conflitos,
proteção do export e rejeição de dependências da aplicação.

Os testes CEP/FIPE foram portados para a API. O pacote recebeu os testes
reutilizáveis de identidade e impressão da API com a fixture correspondente.
Os testes específicos da camada Laravel permanecem na API.

Resultados locais, em PHP 8.4:

- API: **107 testes / 1.900 asserções**, sem falhas ou skips, com SDK opcional
  carregado de um consumidor temporário.
- Standalone: **110 testes / 404 asserções**, sem falhas ou skips; PHPUnit 10
  reporta depreciação em testes antigos.
- Runtime: **659 arquivos iguais** após normalização de namespace; `check` exit 0.
- Composer: manifestos e locks validados nos dois projetos.

A comparação controlada da suíte ampla usou os mesmos 488 testes e as mesmas
dependências contra o runtime do Git v2.2.0 e contra o runtime conciliado. A versão
original já apresentava **23 falhas e 6 erros**. O runtime conciliado apresentou
os mesmos problemas e uma falha adicional em uma fixture de chave de acesso com
DV inválido. Essa fixture foi atualizada com chaves numérica e alfanumérica com DV
válido, incluindo casos de rejeição de DV incorreto; os 10 testes dessa classe
passaram após a atualização. Na preparação do release v2.4.0, a suíte Unit completa foi repetida após essa
correção: **548 testes / 2.878 asserções**, com **23 falhas, 6 erros, 3 warnings,
1 depreciação e 20 skips**. As contagens de falhas e erros coincidem com as da
comparação controlada da base; a suíte ampla continua reprovada.

As suítes focadas devem ser executadas nos dois projetos. A suíte ampla antiga
do pacote contém expectativas próprias de catálogo municipal, representação
da inscrição municipal CNC e implementação privada do DANFSe. Esses contratos
precisam ser atualizados em uma revisão separada; igualdade do runtime e sucesso
dos testes focados não equivalem à aprovação dessa suíte ampla ou homologação.

## Release v2.4.0 — 13/10/2026

- Composer strict validado. O lock fixa Endroid QR Code 5.1.0 para permitir
  instalação em PHP 8.1/8.2; o manifesto admite também 6.1 em consumidores PHP 8.4+.
- Testes focados de identidade, impressão, CEP/FIPE, chave de acesso, renderização
  térmica, DTO e contrato nacional: **101 testes / 407 asserções**, sem falhas ou
  skips em PHP 8.4, com SDK FIPE opcional e transporte simulado.
- O mesmo escopo em PHP 8.2 passou: **101 testes / 302 asserções / 19 skips**,
  correspondentes ao SDK que requer PHP 8.4.
- Análise estática ampla: oito erros, incluindo símbolos do SDK opcional ausente
  e contratos antigos; o comando `composer analyse` continua reprovado.
- CI do release verifica instalação e testes focados em PHP 8.1/8.4; em PHP 8.4
  instala o SDK opcional e exige que os testes FIPE executem sem skips.

Estas verificações são offline e não representam homologação SEFAZ ou municipal.
