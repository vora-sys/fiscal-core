# NFSe WEBISS/EL Nordeste - Onda 1

Onda tecnica de onboarding municipal sem homologacao real imediata.

## Municipios incluidos

- Aracaju/SE (`2800308`) - `WEBISS`
- Feira de Santana/BA (`2910800`) - `WEBISS`
- Itabuna/BA (`2914802`) - `WEBISS`
- Vitoria da Conquista/BA (`2933307`) - `EL`

## Escopo operacional desta onda

- consolidacao de roteamento no catalogo municipal
- `payload_defaults` canonizados para preview e homologacao interna
- reuso integral das familias existentes `WEBISS` e `EL`
- cobertura automatizada de resolver, adapter, runtime bootstrap e payload factory

## Limites conhecidos

- status operacional de todos os municipios acima: `PENDENTE_HOMOLOGACAO_MUNICIPAL`
- esta onda nao cria provider novo
- esta onda nao altera contratos publicos de facade, adapter ou provider
- qualquer divergencia real de XML, parser, assinatura ou pos-emissao sai do escopo e vira backlog da onda 2

## Observacoes

- Aracaju ja possuia payload canonizado e permanece como referencia `WEBISS` do lote.
- Feira de Santana e Itabuna entram como expansao comercial imediata da mesma familia.
- Vitoria da Conquista entra como ancora `EL` sem customizacao estrutural adicional.
