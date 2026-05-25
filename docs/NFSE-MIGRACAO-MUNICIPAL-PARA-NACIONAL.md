# Migração de Município NFSe Municipal para Nacional

Este guia define o fluxo canônico para migrar um município do provider municipal para `nfse_nacional` sem perder rastreabilidade histórica.

## Quando migrar

Migre quando o município:

- aderir formalmente ao ambiente nacional
- disponibilizar emissão e operações principais pelo fluxo nacional
- possuir janela de vigência definida para fatos geradores

## Passos obrigatórios

1. Atualize `config/nfse/providers-catalog.json` para apontar `provider_family = nfse_nacional`.
2. Defina `national_migration_policy` no município (`effective_from`, `legacy_system`, `error_code` e URLs oficiais).
3. Sincronize `config/nfse/nfse-catalog-manifest.json` com a mesma decisão e política de vigência.
4. Preserve o provider municipal histórico fora da rota ativa.
5. Atualize exemplos e scripts de homologação para o fluxo nacional.
6. Valide emissão, consulta, cancelamento e download no provider nacional.
7. Documente a janela de vigência e qualquer limitação de retroativos.

## Regras de compatibilidade

- O resolver não deve fazer roteamento híbrido por data.
- A vigência deve ficar explícita em metadata municipal (`national_migration_policy`) e não em regra hardcoded por cidade.
- A facade aplica bloqueio genérico de emissão pré-vigência quando `enforce_emission_block_before_effective_date=true`.
- Consultas e cancelamentos seguem o provider efetivo da nota já emitida.
- MEI continua seguindo a política global: emissão sempre no nacional.

## Evidências mínimas

- request/response de emissão nacional
- consulta por chave ou RPS
- cancelamento ou limitação formalizada
- prova de DANFSe/XML final
- nota documental sobre o provider histórico mantido apenas para referência
