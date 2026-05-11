# Event Evolution

Use este guia quando a evolucao envolver cancelamento, substituicao, manifestacoes ou registro de eventos fiscais.

## Regra central

Nao unifique eventos apenas por nome funcional. Primeiro separe por documento e protocolo:

- NFe/NFCe: eventos SEFAZ e manifestacao do destinatario.
- NFSe nacional: eventos e pedidos definidos pelos XSDs em `src/Providers/NFSe/Xsd/`.
- NFSe municipal: cada familia pode expor cancelamento/substituicao por SOAP ou regra proprietaria.

## Modelo de decisao

Para cada evento novo, responda:

1. Qual documento suporta o evento?
2. O evento e canonico no dominio ou especifico de um provider?
3. O contrato precisa apenas disparar a operacao ou tambem consultar/introspectar o evento?
4. A assinatura e obrigatoria?
5. O retorno precisa entrar no normalizador comum?

## Diretrizes de implementacao

- Se o evento for apenas de um provider/familia, comece no provider e suba a abstracao so quando houver segunda implementacao real.
- Se o evento existir em varios providers NFSe, prefira um contrato/capability especifico em vez de inflar `NFSeProviderInterface` com operacoes ainda nao universais.
- Reutilize padroes de introspeccao existentes para request/response, evitando respostas opacas.
- Mantenha nomenclatura distinta para:
  - cancelamento de documento
  - substituicao
  - manifestacao
  - pedido/registro de evento

## Observacoes importantes do estado atual

- `NFSeProviderInterface` hoje cobre `emitir`, `consultar`, `cancelar` e `substituir`.
- `NacionalProvider` ja possui base para cancelamento e substituicao e carrega XSDs de evento no repo.
- `ManifestationType` atual e focado em NFe; nao reutilize esse enum como modelo cross-documento.

## Evidencia minima

- fixture de request/response ou teste unitario do parser/normalizer
- teste do contrato publico se a facade for expandida
- nota documental quando a capacidade existir apenas em parte dos providers
