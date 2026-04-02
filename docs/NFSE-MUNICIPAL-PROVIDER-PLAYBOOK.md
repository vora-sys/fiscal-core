# NFSe Municipal - Playbook de Implementacao de Providers

Este playbook define o processo canonico para levantar, implementar, homologar e liberar providers municipais NFSe no `fiscal-core`.

O objetivo e evitar implementacoes ad hoc por municipio. A regra passa a ser: primeiro classificar o municipio dentro da arquitetura atual, depois implementar apenas o que a familia e o fluxo real exigirem.

## 1. Modelo mental atual

Hoje o roteamento municipal nao acontece por "copiar um provider generico". Ele acontece pela cadeia:

`config/nfse/providers-catalog.json` -> `config/nfse/nfse-provider-families.json` -> `NFSeProviderResolver` -> `ProviderRegistry` -> `NFSeRuntimeBootstrap` -> provider concreto

Isso implica duas distincões obrigatorias:

- Adicionar um municipio ao catalogo nao significa criar um novo provider.
- Criar ou alterar uma familia de provider nao significa adicionar um novo municipio.

### 1.1 Papel de cada artefato

- `config/nfse/providers-catalog.json`
  Registra municipios ativos, aliases, `provider_family`, `schema_package` e status de homologacao.
- `config/nfse/nfse-provider-families.json`
  Registra a familia tecnica: `provider_class`, transporte, WSDLs, schemas, assinatura e operacoes suportadas.
- `src/Support/NFSeProviderResolver.php`
  Resolve o input do municipio para a `provider_family_key`.
- `src/Support/ProviderRegistry.php`
  Carrega config de familias e instancia o provider concreto.
- `src/Support/NFSeRuntimeBootstrap.php`
  Injeta ambiente, certificado, prestador e config minima antes da execucao.
- `src/Support/NFSeMunicipalPayloadFactory.php`
  Gera payloads de demo e de homologacao reais, com defaults municipais.
- `src/Support/NFSeMunicipalHomologationService.php`
  Executa preview e envio real com introspeccao de request, response e warnings.
- `src/Support/MunicipalDanfseRendererResolver.php`
  Resolve renderer de DANFSe por `provider_key`.

### 1.2 Contratos base

Todo provider municipal deve permanecer aderente aos contratos existentes:

- `src/Contracts/NFSeProviderInterface.php`
- `src/Contracts/NFSeOperationalIntrospectionInterface.php`

Este playbook nao introduz novos contratos publicos. Quando houver gap arquitetural, registrar como backlog, sem bloquear a documentacao operacional.

## 2. Quando reutilizar familia e quando criar provider novo

Antes de escrever codigo, classifique o municipio em uma destas categorias:

### Categoria A: reuso puro de familia existente

Use quando o municipio compartilha com a familia existente:

- mesmo layout XML
- mesmo transporte
- mesmas operacoes
- mesma estrategia de assinatura
- parser equivalente
- DANFSe resolvido pelo mesmo fluxo

Resultado esperado:

- adicionar municipio em `providers-catalog.json`
- eventualmente ajustar aliases
- sem novo provider concreto

### Categoria B: novo municipio em familia existente com override leve

Use quando a base tecnica e a mesma, mas ha variacoes como:

- endpoints por ambiente
- namespaces
- operacoes SOAP
- chaves de config
- pequenas regras de payload

Resultado esperado:

- adicionar municipio no catalogo
- ajustar a entrada da familia em `nfse-provider-families.json`
- se necessario, extender comportamento em provider existente sem criar familia nova

### Categoria C: novo provider concreto sobre comportamento proximo

Use quando existe uma familia semelhante, mas o municipio diverge em partes relevantes:

- montagem de XML
- validacoes de prestador/tomador
- parser de resposta
- consulta/cancelamento
- requisitos de DANFSe

Resultado esperado:

- criar provider concreto em `src/Providers/NFSe/Municipal/`
- reaproveitar apenas o que fizer sentido da familia/base

### Categoria D: nova familia completa

Use quando o municipio muda substancialmente:

- layout
- transporte
- assinatura
- parser
- contratos de operacao
- fluxo de pos-emissao ou DANFSe

Resultado esperado:

- nova entrada em `nfse-provider-families.json`
- novo provider concreto
- novo pacote de schema, se aplicavel
- novas fixtures e documentacao operacional

## 3. Checklist de levantamento obrigatorio

Nenhum provider deve ser iniciado sem o discovery minimo abaixo.

### 3.1 Identificacao do municipio

- nome oficial
- UF
- codigo IBGE
- slug canonico
- aliases esperados no resolver

### 3.2 Plataforma e familia tecnica

- nome do provedor/plataforma fiscal
- versao do layout
- familia real do layout: ABRASF 2.03, proprietario, RPC/literal, REST, etc.
- `provider_family` candidato
- `schema_package` candidato

### 3.3 Operacoes suportadas

- `emitir`
- `consultar_lote`
- `consultar_nfse_rps`
- `cancelar_nfse`
- `substituir`
- disponibilidade/consulta por documento
- se a emissao e sincrona ou exige consulta posterior

### 3.4 Transporte e endpoints

- WSDL/API de homologacao
- WSDL/API de producao
- WSDL separado de consultas, quando existir
- estilo SOAP: document/literal ou rpc/literal
- namespace
- action e nome da operacao, quando aplicavel
- requisitos de encoding e envelopamento

### 3.5 Assinatura e seguranca

- assinatura obrigatoria ou nao
- algoritmo exigido
- quais operacoes assinam
- posicao do XML assinado
- certificado exigido em homologacao e producao

### 3.6 Schemas e artefatos tecnicos

- XSDs principais
- imports/includes auxiliares
- entrypoints por operacao
- exemplos XML oficiais
- exemplos XML rejeitados

### 3.7 Requisitos de payload

Prestador:

- CNPJ
- inscricao municipal
- razao social
- simples nacional
- MEI
- incentivo fiscal/cultural
- CNAE
- item/codigo de servico

Tomador:

- documento
- razao social
- email
- telefone
- endereco minimo
- municipio/UF/pais

Servico:

- codigo
- item da lista
- discriminacao
- aliquota
- ISS retido
- natureza da operacao
- exigibilidade

### 3.8 Consulta, cancelamento e DANFSe

- identificadores exigidos para consulta
- necessidade de protocolo
- necessidade de codigo de verificacao
- janela e regra de cancelamento
- como obter XML final
- como obter DANFSe/HTML/PDF/URL publica
- se existe renderer reaproveitavel ou se sera necessario um novo

### 3.9 Evidencias de homologacao

- XML de sucesso da emissao
- XML de rejeicao da emissao
- XML de consulta
- XML de cancelamento
- URL ou artefato final do documento, quando houver

## 4. Passo a passo obrigatorio de implementacao

Siga sempre esta ordem.

### Etapa 1: catalogar o municipio

Atualize `config/nfse/providers-catalog.json` com:

- `slug`
- `nome`
- `uf`
- `provider_family`
- `schema_package`
- `ibge`
- `homologado`
- `active`
- aliases necessarios

Critico: o catalogo registra o municipio; ele nao substitui a configuracao da familia tecnica.

### Etapa 2: registrar ou ajustar a familia

Atualize `config/nfse/nfse-provider-families.json` com:

- `provider_class`
- `layout_family`
- `schema_root`
- `xsd_entrypoints`
- `transport`
- `versao`
- `codigo_municipio`
- endpoints por ambiente
- campos de assinatura
- `supported_operations`
- campos especificos de SOAP, quando aplicavel

Se o municipio reutiliza familia existente, esta etapa pode ser apenas um ajuste leve de config.

### Etapa 3: definir o provider concreto

Criar ou ajustar o provider em `src/Providers/NFSe/Municipal/` quando o reuso por config nao for suficiente.

O provider deve:

- implementar emissao
- declarar claramente operacoes suportadas
- falhar com erro explicito quando uma operacao nao for suportada
- expor parser consistente em `getLastResponseData()`
- expor artefatos em `getLastOperationArtifacts()`

### Etapa 4: integrar schemas e validacao

Garantir que `schema_root` e `xsd_entrypoints` estejam consistentes com os XMLs reais.

O implementador deve verificar:

- schema de emissao
- schema de consulta
- schema de cancelamento
- includes/imports auxiliares
- operacoes com nomes diferentes da semantica interna

### Etapa 5: alinhar payload esperado

Revisar `src/Support/NFSeMunicipalPayloadFactory.php` quando o municipio exigir campos novos ou formatos diferentes.

So mexer no factory quando o provider realmente precisar de:

- novos campos obrigatorios
- defaults municipais
- regras de `prestador`, `tomador` ou `servico`
- diferenca entre payload demo e payload real

### Etapa 6: garantir bootstrap e runtime

Validar o fluxo real com:

- `src/Support/NFSeRuntimeBootstrap.php`
- `src/Support/NFSeMunicipalHomologationService.php`

Requisitos minimos:

- ambiente resolvido corretamente
- certificado carregado
- `FISCAL_IM` obrigatorio quando o provider for municipal
- config de prestador consistente com o certificado e com o municipio

### Etapa 7: implementar parser normalizado

Toda operacao implementada deve produzir parser utilizavel por facade e por debug operacional.

Cobertura minima:

- sucesso de emissao
- erro/rejeicao de emissao
- consulta de documento
- cancelamento

O parser precisa devolver dados suficientes para evolucao de `NFSeFacade::emitirCompleto()` quando o municipio suportar fluxo completo.

### Etapa 8: expor artefatos operacionais

O provider deve disponibilizar pelo menos:

- request XML
- envelope SOAP, quando houver
- parsed response
- lista de operacoes suportadas

Esses artefatos sao obrigatorios para homologacao, debug e documentacao de evidencias.

### Etapa 9: DANFSe e pos-emissao

Provider municipal nao esta concluido apenas com emissao autorizada.

Para concluir, o implementador deve entregar uma destas saidas:

- renderer proprio registrado em `MunicipalDanfseRendererResolver`
- reaproveitamento explicito de renderer existente
- limitacao temporaria formalizada na documentacao, com criterio de follow-up

Tambem deve ser decidido se o municipio exige:

- consulta complementar para obter XML final
- URL oficial do documento
- parser adicional de pos-emissao

### Etapa 10: testes, fixtures e exemplos

Criar ou ajustar:

- fixtures de XML real
- testes unitarios do parser e das validacoes
- testes integrados quando viavel
- exemplo operacional ou script de homologacao, quando o fluxo ainda nao estiver coberto

### Etapa 11: homologacao e evidencias

Executar preview e envio real via `NFSeMunicipalHomologationService`.

Registrar:

- request XML
- SOAP envelope
- parsed response
- warnings
- XML de sucesso
- XML de rejeicao
- XML de consulta
- XML de cancelamento

### Etapa 12: documentacao e release

Ao concluir a implementacao:

- atualizar este playbook se houver regra nova reutilizavel
- atualizar docs especificos do municipio, quando existirem
- atualizar `README.md`
- atualizar changelog/release notes

## 5. Contrato minimo de conclusao por provider

Um provider municipal so pode ser tratado como concluido quando:

- o municipio resolve corretamente via catalogo e aliases
- a familia tecnica esta registrada e carregavel pelo `ProviderRegistry`
- o provider declara operacoes suportadas
- o payload minimo esta documentado e validado
- a emissao funciona ao menos em preview coerente, e idealmente em homologacao real
- consulta e cancelamento estao implementados ou explicitamente documentados como limitacao do municipio
- parser de sucesso e parser de erro possuem fixture
- DANFSe/URL/XML final estao resolvidos ou a limitacao esta formalizada
- docs e release notes foram atualizados

## 6. Checklist binario de aceite

Marque como pronto apenas quando todos os itens abaixo estiverem em estado decidido:

- [ ] Municipio resolvido corretamente em `providers-catalog.json`
- [ ] Familia tecnica resolvida corretamente em `nfse-provider-families.json`
- [ ] Provider carregado sem intervencao manual fora da config prevista
- [ ] Payload minimo validado
- [ ] Emissao real ou preview com request XML consistente
- [ ] Consulta funcionando ou limitacao documentada
- [ ] Cancelamento funcionando ou limitacao documentada
- [ ] Parser de sucesso coberto por fixture
- [ ] Parser de erro coberto por fixture
- [ ] DANFSe, URL oficial ou XML final resolvido
- [ ] README e docs especificos atualizados
- [ ] Checklist de release concluido

## 7. Exemplos canonicos do repositorio

### Belem

Use Belém como referencia de:

- familia municipal propria
- SOAP com WSDL dedicado
- regras especificas de prestador
- fluxo com disponibilidade/URL oficial do documento
- renderer municipal dedicado

Arquivos de referencia:

- `src/Providers/NFSe/Municipal/BelemMunicipalProvider.php`
- `docs/NFSE-BELEM-HOMOLOGACAO.md`

### Joinville

Use Joinville como referencia de:

- familia `PUBLICA`
- SOAP com WSDL principal e de consultas
- assinatura configurada por familia
- regras especificas de operacao e consulta

Arquivos de referencia:

- `src/Providers/NFSe/Municipal/PublicaProvider.php`
- `src/Providers/NFSe/Municipal/JoinvilleProvider.php`

### Manaus

Use Manaus como caso de cobertura parcial:

- catalogado e com familia registrada
- util para validar o checklist de classificacao
- nao deve ser tratado como exemplo de provider concluido enquanto operacoes, parser, homologacao e DANFSe nao estiverem fechados

Arquivo de referencia:

- `src/Providers/NFSe/Municipal/ManausAmProvider.php`

## 8. Fluxo resumido para um novo municipio

1. Levantar todos os requisitos do municipio.
2. Classificar em A, B, C ou D.
3. Registrar municipio no catalogo.
4. Registrar ou ajustar a familia tecnica.
5. Implementar provider somente se necessario.
6. Ajustar payload factory somente se houver novos obrigatorios reais.
7. Validar bootstrap, certificado e IM.
8. Implementar parser e artefatos operacionais.
9. Resolver DANFSe/pos-emissao.
10. Criar fixtures, testes e evidencias.
11. Homologar.
12. Atualizar docs e release.

## 9. Backlog observavel, sem bloquear o processo

Se durante uma implementacao surgirem gaps estruturais, registrar como backlog sem transformar isso em pre-condicao da documentacao. Exemplos:

- inconsistencias entre contratos de provider e fluxo real da facade
- necessidade de abstrair melhor operacoes municipais especificas
- padronizacao adicional de parser ou artefatos
- consolidacao futura de renderers municipais

O objetivo deste playbook e permitir implementacao repetivel agora, com a arquitetura atual.
