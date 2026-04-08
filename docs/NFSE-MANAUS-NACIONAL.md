# NFSe Manaus - Fluxo Nacional

Manaus/AM deve ser tratada no `fiscal-core` como municipio do fluxo `nfse_nacional`.

## Regra de vigencia

- fatos geradores a partir de `2026-01-01`: usar exclusivamente o emissor nacional
- fatos geradores ate `2025-12-31`: fora do escopo do provider padrao; permanecem no sistema legado `Nota Manaus`

Base normativa e operacional:

- Prefeitura de Manaus oficializou em `2025-12-17` a adocao obrigatoria da NFS-e Padrao Nacional a partir de `2026-01-01`
- o sistema `Nota Manaus` continua apenas para retroativos, consultas, cancelamentos, substituicoes e guias do periodo anterior
- o recolhimento do ISS das notas emitidas no padrao nacional continua por guia gerada no `Nota Manaus`, exceto contribuintes do Simples Nacional

Fonte:

- https://www.manaus.am.gov.br/semef/2025/12/17/prefeitura-semef-nfs-e/
- https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/documentacao-atual

## Roteamento esperado

- catalogo canonico em `config/nfse/providers-catalog.json`
- familia tecnica e endpoints em `config/nfse/nfse-provider-families.json`
- `NFSeAdapter('manaus')` resolve para `NacionalProvider`
- `NFSeFacade('manaus')` expoe o ciclo nacional completo:
  - `emitir`
  - `consultar`
  - `cancelar`
  - `consultarPorRps`
  - `consultarLote`
  - `baixarXml`
  - `baixarDanfse`

## Payload minimo esperado para emissao

- `prestador.cnpj`
- `prestador.inscricaoMunicipal`
- `prestador.opSimpNac`
- `prestador.regEspTrib`
- `cLocEmi = 1302603`
- `servico.cLocPrestacao = 1302603` quando a incidencia for Manaus
- `servico.cTribNac`
- `servico.descricao`
- `servico.tribISSQN`
- `servico.tpRetISSQN`
- `valor_servicos`

## Observacoes

- nao ha roteamento hibrido por data no provider
- ao usar `municipio = manaus`, o sistema assume fluxo nacional
- para evitar uso indevido do fluxo novo em fatos geradores anteriores, a facade rejeita emissao de Manaus com referencia anterior a `2026-01-01`

## Exemplos

CLI principal de Manaus em homologacao:

```bash
php examples/homologacao/05-manaus-operacoes-nacionais.php
```

Emissao real em homologacao:

```bash
php examples/homologacao/05-manaus-operacoes-nacionais.php --send
```

Operacoes nacionais:

```bash
php examples/homologacao/05-manaus-operacoes-nacionais.php --consultar-rps-numero=123 --consultar-rps-serie=1
php examples/homologacao/05-manaus-operacoes-nacionais.php --consultar-lote=SEU_PROTOCOLO
php examples/homologacao/05-manaus-operacoes-nacionais.php --baixar-xml=SUA_CHAVE
php examples/homologacao/05-manaus-operacoes-nacionais.php --baixar-danfse=SUA_CHAVE
php examples/homologacao/05-manaus-operacoes-nacionais.php --cancelar-chave=SUA_CHAVE --motivo="Cancelamento de teste"
```

Compatibilidade:

```bash
php examples/homologacao/04-manaus-emitir-nacional.php --send
```
