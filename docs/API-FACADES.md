# API das Facades

Data de referencia: 2026-05-25

Este documento formaliza o contrato publico minimo das facades do `fiscal-core` para o release `v1.2.4`.

## Envelope publico

Todas as facades publicas devem retornar `sabbajohn\FiscalCore\Support\FiscalResponse`.

Contrato canonico:

- `isSuccess()` indica sucesso operacional da chamada da facade.
- `isError()` indica falha tratada.
- `getData()` retorna o payload estruturado.
- `getData('chave')` retorna uma chave de primeiro nivel ou `null`.
- `getError()` retorna mensagem de erro tratada.
- `getErrorCode()` retorna codigo/classe do erro quando existir.
- `getMetadata()` retorna metadados tecnicos, incluindo contexto, warnings, provider e diagnostico quando aplicavel.
- `toArray()` e `toJson()` existem para serializacao.

As propriedades internas de `FiscalResponse` nao fazem parte do contrato publico. Consumidores devem usar os metodos acima.

## Shape canonico para documentos fiscais

Operacoes de NFe, NFCe e NFSe devem preferir estas chaves em respostas de sucesso:

- `documento`: dados do documento fiscal normalizado.
- `documento.xml`: XML fiscal final imprimivel quando disponivel.
- `documento.chave_acesso` ou `documento.chave_consulta`: identificador usado para consulta.
- `documento.situacao`: motivo/status textual normalizado quando disponivel.
- `documento.protocolo`: protocolo de autorizacao/cancelamento quando disponivel.
- `impressao`: dados para DANFE, DANFCE ou DANFSe.
- `impressao.modo`: `pdf_base64`, `url` ou `indisponivel`.
- `raw.response_xml`: XML tecnico bruto de transporte/resposta quando existir.
- `raw.response_body`: payload textual bruto quando nao for XML fiscal final.
- `raw.parsed_response`: estrutura parseada auxiliar.

Regra importante: XML tecnico de retorno, SOAP envelope, JSON administrativo e mensagens de indisponibilidade nao devem ser promovidos para `documento.xml`.

## Facades disponiveis

### FiscalFacade

Facade agregadora para consumidores que querem uma entrada unica.

Principais metodos estaveis:

- `emitirNFe(array $dados)`
- `consultarNFe(string $chave)`
- `baixarXmlNFe(string $chave)`
- `cancelarNFe(string $chave, string $motivo, string $protocolo)`
- `emitirNFCe(array $dados)`
- `consultarNFCe(string $chave)`
- `baixarXmlNFCe(string $chave)`
- `cancelarNFCe(string $chave, string $motivo, string $protocolo)`
- `emitirNFSe(array $dados, string $municipio = 'nacional')`
- `emitirNFSeCompleto(array $dados, string $municipio = 'nacional', array $options = [])`
- `consultarNFSe(string $chave, string $municipio = 'nacional')`
- `baixarXmlNFSe(string $chave, string $municipio = 'nacional')`
- `gerarDanfe(string $xmlNfe)`
- `gerarDanfce(string $xmlNfce, array $context = [])`
- `calcularTributos(array $produto)`
- `consultarNCM(string $ncm)`

### NFeFacade

Facade para NFe modelo 55.

Principais metodos estaveis:

- `emitir(array $dados)`
- `consultar(string $chave)`
- `cancelar(string $chave, string $motivo, string $protocolo)`
- `baixarXml(string $chave)`
- `gerarDanfe(string $xmlAutorizado)`
- `validarXML(string $xml)`
- `validarChaveAcesso(string $chave)`

### NFCeFacade

Facade para NFCe modelo 65.

Principais metodos estaveis:

- `emitir(array $dados)`
- `consultar(string $chave)`
- `cancelar(string $chave, string $motivo, string $protocolo)`
- `baixarXml(string $chave)`
- `gerarDanfce(string $xmlAutorizado, array $context = [])`

### NFSeFacade

Facade para NFSe nacional e municipal.

Principais metodos estaveis:

- `emitir(array $dados)`
- `emitirCompleto(array $dados, array $options = [])`
- `consultarDisponibilidade(array $criterios, array $options = [])`
- `consultar(string $chave)`
- `cancelar(string $chave, string $motivo, string $protocolo = '')`
- `substituir(string $chave, array $dados)`
- `consultarPorRps(array $identificacaoRps)`
- `consultarLote(string $protocolo)`
- `baixarXml(string $chave)`
- `baixarDanfse(string $chave)`
- `gerarDanfse(string $xmlNfse)`
- `consultarAliquotasMunicipio(...)`
- `consultarConvenioMunicipio(string $codigoMunicipio, bool $forceRefresh = false)`
- `validarLayoutDps(array $payload, bool $checkCatalog = true)`
- `gerarXmlDpsPreview(array $payload)`
- `validarXmlDps(array $payload)`
- `validarPrestador(array $prestador)`
- `validarMunicipio(?string $municipio = null)`

NFSe municipal continua dependente de homologacao real por municipio. Roteamento em catalogo nao equivale a homologacao.

### ImpressaoFacade

Facade para renderizacao/impressao.

Principais metodos estaveis:

- `gerarDanfe(string $xmlNfe)`
- `gerarDanfce(string $xmlNfce, array $context = [])`
- `gerarDacte(string $xmlCte)`
- `gerarDamdfe(string $xmlMdfe)`
- `validarXML(string $xml, string $tipo = 'nfe')`

### TributacaoFacade

Facade para calculos e consultas tributarias.

Principais metodos estaveis:

- `calcular(array $produto)`
- `consultarNCM(string $ncm)`
- `validarNCM(string $ncm)`
- `validarCEST(string $cest)`
- `validarProduto(array $produto)`
- `calcularICMS(array $dados)`
- `consultarAliquotaIPI(string $ncm)`

### UtilsFacade

Facade para documentos e consultas publicas.

Principais metodos estaveis:

- `consultarCEP(string $cep)`
- `consultarCNPJ(string $cnpj)`
- `validarCPF(string $cpf)`
- `validarCNPJ(string $cnpj)`
- `consultarBanco(string $codigo)`
- `consultarDDD(string $ddd)`

## Compatibilidade

Este release nao remove metodos existentes nem altera assinaturas publicas. Melhorias futuras devem expandir o envelope e os metadados sem quebrar consumidores atuais.

