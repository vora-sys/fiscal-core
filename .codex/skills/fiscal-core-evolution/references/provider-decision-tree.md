# Provider Decision Tree

Use este roteiro quando a demanda envolver novos municipios, familias ou ajustes de provider NFSe.

## Pergunta 1

O municipio cabe em uma familia existente sem alterar payload, parser, assinatura e operacoes?

- Se sim, prefira atualizar `providers-catalog.json`.
- Se nao, siga para a pergunta 2.

## Pergunta 2

A diferenca cabe em configuracao ou override leve?

Sinais de que sim:

- endpoints mudam por ambiente
- namespace ou SOAP action muda
- payload default municipal muda
- URL oficial de DANFSe muda

Se sim:

- atualize `provider_config_overrides` ou `payload_defaults`
- preserve a mesma `provider_family`
- evite subclass nova

## Pergunta 3

Existe divergencia relevante em montagem XML, parser, validacoes ou pos-emissao?

Se sim:

- crie provider concreto em `src/Providers/NFSe/Municipal/`
- reaproveite o minimo necessario da base comum
- mantenha o catalogo como origem da decisao de roteamento

## Pergunta 4

O protocolo inteiro muda?

Sinais:

- transporte diferente
- layout diferente
- assinatura diferente
- operacoes diferentes
- estrategia de consulta e DANFSe diferente

Se sim:

- crie nova familia em `config/nfse/nfse-provider-families.json`
- associe provider concreto
- adicione schemas e fixtures correspondentes

## Obrigatorio antes de aceitar a mudanca

- municipio identificado com nome, UF, codigo IBGE e aliases
- operacoes suportadas listadas explicitamente
- ambiente homologacao/producao documentado
- estrategia de assinatura conhecida
- teste cobrindo pelo menos o caminho principal e uma falha relevante

## Sinal de alerta

Se a mudanca exigir logica espalhada entre facade, adapter e provider para apenas um municipio, provavelmente a classificacao esta errada e o design precisa voltar um passo.
