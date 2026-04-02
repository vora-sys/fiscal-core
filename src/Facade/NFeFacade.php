<?php

namespace freeline\FiscalCore\Facade;

use freeline\FiscalCore\Adapters\NF\NFeAdapter;
use freeline\FiscalCore\Adapters\ImpressaoAdapter;
use freeline\FiscalCore\Support\ResponseHandler;
use freeline\FiscalCore\Support\FiscalResponse;
use freeline\FiscalCore\Support\ManifestationType;
use freeline\FiscalCore\Adapters\NF\Builder\NotaFiscalBuilder;
use freeline\FiscalCore\Adapters\NF\Core\NotaFiscal;
use freeline\FiscalCore\Support\ToolsFactory;
use NFePHP\NFe\Tools;

/**
 * Facade para NFe - Interface simplificada e com tratamento de erros
 * Evita que aplicações recebam erros 500 fornecendo responses padronizados
 */
class NFeFacade
{
    private ?NFeAdapter $nfe = null;
    private ?ImpressaoAdapter $impressao = null;
    private ResponseHandler $responseHandler;
    private ?FiscalResponse $initializationError = null;

    public function __construct(
        ?NFeAdapter $nfe = null,
        ?ImpressaoAdapter $impressao = null
    ) {
        $this->responseHandler = new ResponseHandler();
        
        if ($nfe !== null) {
            $this->nfe = $nfe;
        } else {
            // Usa método safe que retorna FiscalResponse
            $toolsResponse = ToolsFactory::createNFeToolsSafe();
            if ($toolsResponse->isSuccess()) {
                $this->nfe = new NFeAdapter($toolsResponse->getData()['result']);
            } else {
                // Armazena o erro original em vez de sobrescrever
                $this->initializationError = $toolsResponse;
            }
        }
        
        if ($impressao !== null) {
            $this->impressao = $impressao;
        } else {
            try {
                $this->impressao = new ImpressaoAdapter();
            } catch (\Exception $e) {
                // Se falhar, impressao fica null
            }
        }
    }

    /**
     * Verifica se o adapter está inicializado e retorna erro original se não estiver
     */
    private function checkNFeInitialization(): ?FiscalResponse
    {
        if ($this->nfe === null) {
            // Retorna o erro original se houver, senão cria um genérico
            return $this->initializationError ?? FiscalResponse::error(
                'NFe adapter não inicializado devido a erro de configuração',
                'INITIALIZATION_ERROR',
                'adapter_check'
            );
        }
        return null;
    }

    /**
     * Emite uma NFe com tratamento completo de erros
     * 
     * @param array $dados Dados da nota fiscal
     * @return FiscalResponse Response padronizado com sucesso/erro
     */
    public function emitir(array $dados): FiscalResponse
    {
        // Verifica inicialização primeiro e retorna erro original se houver
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }
        
        return $this->responseHandler->handle(function() use ($dados) {
            // Garante que é modelo 55 (NFe)
            if (!isset($dados['identificacao']['mod'])) {
                $dados['identificacao']['mod'] = 55;
            }
            
            $result = $this->nfe->emitir($dados);
            
            return [
                'xml_response' => $result,
                'modelo' => 55,
                'ambiente' => $dados['identificacao']['tpAmb'] ?? 2,
                'chave_acesso' => $this->extrairChaveAcesso($result)
            ];
        }, 'emissao_nfe');
    }

    /**
     * Consulta uma NFe pelo número da chave
     * 
     * @param string $chave Chave de acesso da NFe
     * @return FiscalResponse Response padronizado
     */
    public function consultar(string $chave): FiscalResponse
    {
        // Verifica inicialização primeiro
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }
        
        return $this->responseHandler->handle(function() use ($chave) {
            if (strlen($chave) !== 44) {
                throw new \InvalidArgumentException('Chave de acesso deve ter 44 dígitos');
            }
            
            $result = $this->nfe->consultar($chave);
            
            return [
                'xml_response' => $result,
                'chave_acesso' => $chave,
                'situacao' => $this->extrairSituacao($result)
            ];
        }, 'consulta_nfe');
    }

    /**
     * Cancela uma NFe
     * 
     * @param string $chave Chave de acesso
     * @param string $motivo Motivo do cancelamento
     * @param string $protocolo Protocolo de autorização
     * @return FiscalResponse Response padronizado
     */
    public function cancelar(string $chave, string $motivo, string $protocolo): FiscalResponse
    {
        // Verifica inicialização primeiro
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }
        
        return $this->responseHandler->handle(function() use ($chave, $motivo, $protocolo) {
            if (strlen($motivo) < 15) {
                throw new \InvalidArgumentException('Motivo deve ter pelo menos 15 caracteres');
            }
            
            $xmlResponse = $this->nfe->cancelar($chave, $motivo, $protocolo);
            
            return [
                'cancelado' => $this->isSefazOperationSuccessful($xmlResponse),
                'xml_response' => $xmlResponse,
                'cstat' => $this->extrairCStat($xmlResponse),
                'chave_acesso' => $chave,
                'motivo' => $motivo,
                'protocolo' => $protocolo
            ];
        }, 'cancelamento_nfe');
    }

    /**
     * Inutiliza numeração da NFe
     */
    public function inutilizar(
        int $ano, 
        int $cnpj, 
        int $serie, 
        int $numeroInicial, 
        int $numeroFinal, 
        string $justificativa
    ): FiscalResponse {
        // Verifica inicialização primeiro
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }
        
        return $this->responseHandler->handle(function() use (
            $ano, $cnpj, $serie, $numeroInicial, $numeroFinal, $justificativa
        ) {
            if (strlen($justificativa) < 15) {
                throw new \InvalidArgumentException('Justificativa deve ter pelo menos 15 caracteres');
            }
            
            $xmlResponse = $this->nfe->inutilizar(
                $ano, $cnpj, 55, $serie, 
                $numeroInicial, $numeroFinal, $justificativa
            );
            
            return [
                'inutilizado' => $this->isSefazOperationSuccessful($xmlResponse),
                'xml_response' => $xmlResponse,
                'cstat' => $this->extrairCStat($xmlResponse),
                'serie' => $serie,
                'numeros' => [
                    'inicial' => $numeroInicial,
                    'final' => $numeroFinal
                ],
                'justificativa' => $justificativa
            ];
        }, 'inutilizacao_nfe');
    }

    /**
     * Verifica status do serviço SEFAZ
     * 
     * @param string $uf UF para consulta
     * @param int|null $ambiente Ambiente (1=produção, 2=homologação)
     * @return FiscalResponse Response com status do serviço
     */
    public function verificarStatusSefaz(string $uf = '', ?int $ambiente = null): FiscalResponse
    {
        // Verifica inicialização primeiro
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }
        
        return $this->responseHandler->handle(function() use ($uf, $ambiente) {
            $result = $this->nfe->sefazStatus($uf, $ambiente);
            
            return [
                'xml_response' => $result,
                'uf' => $uf ?: 'SC',
                'ambiente' => $ambiente ?: 2,
                'status' => $this->extrairStatusSefaz($result)
            ];
        }, 'status_sefaz');
    }

    public function consultarDistribuicaoDFe(
        int $ultNsu = 0,
        int $numNsu = 0,
        ?string $chave = null,
        string $fonte = 'AN'
    ): FiscalResponse {
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        return $this->responseHandler->handle(function () use ($ultNsu, $numNsu, $chave, $fonte) {
            $xmlResponse = $this->nfe->consultaNotasEmitidasParaEstabelecimento($ultNsu, $numNsu, $chave, $fonte);

            return array_merge(
                ['xml_response' => $xmlResponse],
                $this->parseDistDFeResponse($xmlResponse)
            );
        }, 'distdfe_nfe');
    }

    public function manifestarDestinatario(
        string $chave,
        ManifestationType|string $tipo,
        string $justificativa = '',
        int $sequencia = 1
    ): FiscalResponse {
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        return $this->responseHandler->handle(function () use ($chave, $tipo, $justificativa, $sequencia) {
            if (strlen($chave) !== 44) {
                throw new \InvalidArgumentException('Chave de acesso deve ter 44 dígitos');
            }

            $manifestationType = is_string($tipo) ? ManifestationType::fromValue($tipo) : $tipo;
            $xmlResponse = $this->nfe->manifestarDestinatario($chave, $manifestationType, $justificativa, $sequencia);

            return array_merge(
                [
                    'chave_acesso' => $chave,
                    'manifestation_type' => $manifestationType->value,
                    'justificativa' => $justificativa,
                    'sequencia' => $sequencia,
                    'xml_response' => $xmlResponse,
                ],
                $this->parseEventResponse($xmlResponse)
            );
        }, 'manifestacao_destinatario_nfe');
    }

    public function downloadNFe(string $chave): FiscalResponse
    {
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        return $this->responseHandler->handle(function () use ($chave) {
            if (strlen($chave) !== 44) {
                throw new \InvalidArgumentException('Chave de acesso deve ter 44 dígitos');
            }

            $xmlResponse = $this->nfe->downloadNFe($chave);

            return array_merge(
                [
                    'chave_acesso' => $chave,
                    'xml_response' => $xmlResponse,
                ],
                $this->parseDistDFeResponse($xmlResponse)
            );
        }, 'download_nfe_destinataria');
    }

    /**
     * Gera DANFE da NFe
     * 
     * @param string $xmlAutorizado XML da NFe autorizada
     * @return FiscalResponse Response com o PDF ou erro
     */
    public function gerarDanfe(string $xmlAutorizado): FiscalResponse
    {
        return $this->responseHandler->handle(function() use ($xmlAutorizado) {
            if (!isset($this->impressao)) {
                throw new \RuntimeException('Impressao adapter não disponível.');
            }
            
            if (empty($xmlAutorizado)) {
                throw new \InvalidArgumentException('XML autorizado é obrigatório');
            }
            
            $pdf = $this->impressao->gerarDanfe($xmlAutorizado);
            
            return [
                'pdf_base64' => base64_encode($pdf),
                'content_type' => 'application/pdf',
                'filename' => 'danfe_' . date('Ymd_His') . '.pdf'
            ];
        }, 'geracao_danfe');
    }

    /**
     * Construtor fluente para NFe
     * 
     * @return NotaFiscalBuilder Builder para construção type-safe
     */
    public static function builder(): NotaFiscalBuilder
    {
        return new NotaFiscalBuilder();
    }

    /**
     * Cria NFe a partir de array (sem emitir)
     * Útil para validação prévia
     * 
     * @param array $dados Dados da nota
     * @return FiscalResponse Response com objeto NotaFiscal
     */
    public function criarNota(array $dados): FiscalResponse
    {
        // Verifica inicialização primeiro
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }
        
        return $this->responseHandler->handle(function() use ($dados) {
            if (!isset($dados['identificacao']['mod'])) {
                $dados['identificacao']['mod'] = 55;
            }
            
            $nota = $this->nfe->criarNota($dados);
            $nota->validate();
            
            return [
                'nota_fiscal' => $nota,
                'modelo' => 55,
                'validada' => true,
                'chave_simulada' => $this->simularChaveAcesso($dados)
            ];
        }, 'criacao_nota');
    }

    // Métodos auxiliares para extrair informações dos XMLs
    
    private function extrairChaveAcesso(string $xml): ?string
    {
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml);
            $xpath = new \DOMXPath($dom);
            $node = $xpath->query('//chNFe')->item(0);
            return $node ? $node->nodeValue : null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    private function extrairSituacao(string $xml): string
    {
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml);
            $xpath = new \DOMXPath($dom);
            $node = $xpath->query('//xMotivo')->item(0);
            return $node ? $node->nodeValue : 'Status não identificado';
        } catch (\Exception $e) {
            return 'Erro ao extrair situação';
        }
    }
    
    private function extrairStatusSefaz(string $xml): string
    {
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml);
            $xpath = new \DOMXPath($dom);
            $cStat = $xpath->query('//cStat')->item(0);
            $xMotivo = $xpath->query('//xMotivo')->item(0);
            
            if ($cStat && $xMotivo) {
                return $cStat->nodeValue . ' - ' . $xMotivo->nodeValue;
            }
            return 'Status não identificado';
        } catch (\Exception $e) {
            return 'Erro ao extrair status';
        }
    }

    private function parseDistDFeResponse(string $xml): array
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);

        $documentos = [];
        $docNodes = $xpath->query("//*[local-name()='docZip']");
        if ($docNodes !== false) {
            foreach ($docNodes as $docNode) {
                if (!$docNode instanceof \DOMElement) {
                    continue;
                }

                $decodedXml = $this->decodeDocZip((string) $docNode->textContent);
                $documentos[] = [
                    'nsu' => (string) $docNode->getAttribute('NSU'),
                    'schema' => (string) $docNode->getAttribute('schema'),
                    'xml' => $decodedXml,
                    'chave' => $this->extractTagValue($decodedXml, ['chNFe']) ?? $this->extractChaveByInfNFe($decodedXml),
                ];
            }
        }

        return [
            'cstat' => $this->extractTagValue($xml, ['cStat']),
            'xmotivo' => $this->extractTagValue($xml, ['xMotivo']),
            'ult_nsu' => $this->extractTagValue($xml, ['ultNSU']) ?? '0',
            'max_nsu' => $this->extractTagValue($xml, ['maxNSU']) ?? '0',
            'documents' => $documentos,
        ];
    }

    private function parseEventResponse(string $xml): array
    {
        return [
            'cstat' => $this->extractTagValue($xml, ['cStat']),
            'xmotivo' => $this->extractTagValue($xml, ['xMotivo']),
            'protocolo' => $this->extractTagValue($xml, ['nProt']),
            'chave' => $this->extractTagValue($xml, ['chNFe']),
        ];
    }

    private function decodeDocZip(string $encoded): ?string
    {
        $decoded = base64_decode(trim($encoded), true);
        if ($decoded === false || $decoded === '') {
            return null;
        }

        $xml = @gzdecode($decoded);
        if ($xml === false) {
            $xml = @gzinflate(substr($decoded, 10));
        }

        if (!is_string($xml) || trim($xml) === '') {
            return null;
        }

        return trim($xml);
    }

    private function extractTagValue(string $xml, array $tagNames): ?string
    {
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml);
            $xpath = new \DOMXPath($dom);
            foreach ($tagNames as $tagName) {
                $node = $xpath->query("//*[local-name()='{$tagName}']")->item(0);
                if ($node instanceof \DOMNode) {
                    $value = trim((string) $node->textContent);
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function extractChaveByInfNFe(?string $xml): ?string
    {
        if ($xml === null) {
            return null;
        }

        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml);
            $xpath = new \DOMXPath($dom);
            $node = $xpath->query("//*[local-name()='infNFe']")->item(0);
            if (!$node instanceof \DOMElement) {
                return null;
            }

            $id = (string) $node->getAttribute('Id');

            return str_starts_with($id, 'NFe') ? substr($id, 3) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function extrairCStat(string $xml): ?string
    {
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml);
            $xpath = new \DOMXPath($dom);
            $cStat = $xpath->query('//cStat')->item(0);
            return $cStat ? trim((string) $cStat->nodeValue) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function isSefazOperationSuccessful(string $xml): bool
    {
        $cStat = $this->extrairCStat($xml);
        if ($cStat === null) {
            return false;
        }
        return in_array($cStat, ['100', '101', '102', '135', '136', '155'], true);
    }
    
    /**
     * Valida XML NFe
     */
    public function validarXML(string $xml): FiscalResponse
    {
        return $this->responseHandler->execute(function() use ($xml) {
            if (empty($xml)) {
                throw new \InvalidArgumentException("XML não pode estar vazio");
            }
            
            // Validação básica de XML
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            
            if (!$dom->loadXML($xml)) {
                $errors = libxml_get_errors();
                $errorMsg = "XML inválido: " . $errors[0]->message ?? 'Erro desconhecido';
                libxml_clear_errors();
                throw new \InvalidArgumentException($errorMsg);
            }
            
            // Valida se é uma NFe
            $infNFe = $dom->getElementsByTagName('infNFe');
            if ($infNFe->length === 0) {
                throw new \InvalidArgumentException("XML não é uma NFe válida");
            }
            
            $chave = $infNFe->item(0)->getAttribute('Id');
            $chave = str_replace('NFe', '', $chave);
            
            return [
                'xml_valido' => true,
                'chave_acesso' => $chave,
                'tipo_documento' => 'NFe',
                'tamanho_xml' => strlen($xml)
            ];
        }, 'validacao_xml_nfe');
    }
    
    /**
     * Valida chave de acesso NFe
     */
    public function validarChaveAcesso(string $chave): FiscalResponse
    {
        return $this->responseHandler->execute(function() use ($chave) {
            $chave = preg_replace('/\D/', '', $chave);
            
            if (strlen($chave) !== 44) {
                throw new \InvalidArgumentException("Chave de acesso deve ter 44 dígitos");
            }
            
            // Extrai informações da chave
            $uf = substr($chave, 0, 2);
            $aamm = substr($chave, 2, 4);
            $cnpj = substr($chave, 6, 14);
            $modelo = substr($chave, 20, 2);
            $serie = substr($chave, 22, 3);
            $numero = substr($chave, 25, 9);
            $tipo_emissao = substr($chave, 34, 1);
            $codigo_numerico = substr($chave, 35, 8);
            $dv = substr($chave, 43, 1);
            
            // Valida o DV
            $dv_calculado = $this->calcularDV(substr($chave, 0, 43));
            
            if ($dv !== $dv_calculado) {
                throw new \InvalidArgumentException("Dígito verificador inválido");
            }
            
            return [
                'chave_valida' => true,
                'uf' => $uf,
                'ano_mes' => $aamm,
                'cnpj_emitente' => $cnpj,
                'modelo' => $modelo,
                'serie' => intval($serie),
                'numero' => intval($numero),
                'tipo_emissao' => $tipo_emissao,
                'codigo_numerico' => $codigo_numerico,
                'digito_verificador' => $dv
            ];
        }, 'validacao_chave_nfe');
    }

    /**
     * Valida chave de acesso específica
     */
    public function validarChave(string $chave): FiscalResponse
    {
        return $this->validarChaveAcesso($chave);
    }

    /**
     * Valida dados do emitente
     */
    public function validarEmitente(array $emitente): FiscalResponse
    {
        return $this->responseHandler->execute(function() use ($emitente) {
            $erros = [];
            
            // Valida campos obrigatórios
            if (empty($emitente['CNPJ'])) {
                $erros[] = 'CNPJ obrigatório';
            } else {
                $cnpj = preg_replace('/\D/', '', $emitente['CNPJ']);
                if (strlen($cnpj) !== 14) {
                    $erros[] = 'CNPJ inválido';
                }
            }
            
            if (empty($emitente['xNome'])) {
                $erros[] = 'Razão Social obrigatória';
            }
            
            if (empty($emitente['IE'])) {
                $erros[] = 'Inscrição Estadual obrigatória';
            }
            
            // Valida endereço
            if (empty($emitente['endereco'])) {
                $erros[] = 'Endereço obrigatório';
            } else {
                $endereco = $emitente['endereco'];
                if (empty($endereco['xLgr'])) $erros[] = 'Logradouro obrigatório';
                if (empty($endereco['nro'])) $erros[] = 'Número obrigatório';
                if (empty($endereco['xMun'])) $erros[] = 'Município obrigatório';
                if (empty($endereco['UF'])) $erros[] = 'UF obrigatória';
                if (empty($endereco['CEP'])) $erros[] = 'CEP obrigatório';
            }
            
            if (!empty($erros)) {
                throw new \InvalidArgumentException(implode(', ', $erros));
            }
            
            return [
                'emitente_valido' => true,
                'cnpj_formatado' => preg_replace('/\D/', '', $emitente['CNPJ']),
                'validacoes' => [
                    'cnpj' => !empty($emitente['CNPJ']),
                    'razao_social' => !empty($emitente['xNome']),
                    'ie' => !empty($emitente['IE']),
                    'endereco' => !empty($emitente['endereco'])
                ]
            ];
        }, 'validacao_emitente_nfe');
    }

    /**
     * Valida totais da NFe
     */
    public function validarTotais(array $totais): FiscalResponse
    {
        return $this->responseHandler->execute(function() use ($totais) {
            $erros = [];
            
            // Campos obrigatórios de totais
            $required = ['vBC', 'vICMS', 'vBCST', 'vST', 'vProd', 'vFrete', 'vSeg', 'vDesc', 'vII', 'vIPI', 'vPIS', 'vCOFINS', 'vOutro', 'vNF'];
            
            foreach ($required as $campo) {
                if (!isset($totais[$campo])) {
                    $totais[$campo] = 0;
                }
            }
            
            // Validação da soma
            $somaCalculada = 
                $totais['vProd'] + 
                $totais['vFrete'] + 
                $totais['vSeg'] + 
                $totais['vOutro'] + 
                $totais['vII'] + 
                $totais['vIPI'] - 
                $totais['vDesc'];
            
            $diferenca = abs($somaCalculada - $totais['vNF']);
            
            if ($diferenca > 0.02) { // Tolerância de 2 centavos
                $erros[] = 'Total inconsistente';
            }
            
            if (!empty($erros)) {
                throw new \InvalidArgumentException(implode(', ', $erros));
            }
            
            return [
                'totais_validos' => true,
                'valor_total_nfe' => $totais['vNF'],
                'valor_total_calculado' => round($somaCalculada, 2),
                'diferenca' => round($diferenca, 2),
                'componentes' => [
                    'produtos' => $totais['vProd'],
                    'frete' => $totais['vFrete'],
                    'seguro' => $totais['vSeg'],
                    'desconto' => $totais['vDesc'],
                    'outros' => $totais['vOutro'],
                    'ipi' => $totais['vIPI'],
                    'icms' => $totais['vICMS']
                ]
            ];
        }, 'validacao_totais_nfe');
    }

    /**
     * Valida código CST
     */
    public function validarCST(string $cst): FiscalResponse
    {
        return $this->responseHandler->execute(function() use ($cst) {
            // Remove caracteres não numéricos
            $cst = preg_replace('/\D/', '', $cst);
            
            // CSTs válidos para ICMS
            $cstsValidos = [
                '00', '10', '20', '30', '40', '41', '50', '51', '60', '70', '90',
                '101', '102', '103', '201', '202', '203', '300', '400', '500'
            ];
            
            if (!in_array($cst, $cstsValidos)) {
                throw new \InvalidArgumentException('CST inválido: ' . $cst);
            }
            
            // Determina regime tributário pelo CST
            $regime = strlen($cst) === 2 ? 'normal' : 'simples_nacional';
            
            // Determina se há tributação
            $semTributacao = in_array($cst, ['40', '41', '50', '51', '103', '203', '300', '400', '500']);
            
            return [
                'cst' => $cst,
                'cst_valido' => true,
                'regime_tributario' => $regime,
                'com_tributacao' => !$semTributacao,
                'descricao' => $this->getDescricaoCST($cst)
            ];
        }, 'validacao_cst');
    }

    /**
     * Retorna descrição do CST
     */
    private function getDescricaoCST(string $cst): string
    {
        $descricoes = [
            '00' => 'Tributada integralmente',
            '10' => 'Tributada e com cobrança do ICMS por substituição tributária',
            '20' => 'Com redução de base de cálculo',
            '30' => 'Isenta ou não tributada e com cobrança do ICMS por substituição tributária',
            '40' => 'Isenta',
            '41' => 'Não tributada',
            '50' => 'Suspensão',
            '51' => 'Diferimento',
            '60' => 'ICMS cobrado anteriormente por substituição tributária',
            '70' => 'Com redução de base de cálculo e cobrança do ICMS por substituição tributária',
            '90' => 'Outras',
            '101' => 'Tributada pelo Simples Nacional com permissão de crédito',
            '102' => 'Tributada pelo Simples Nacional sem permissão de crédito',
            '103' => 'Isenção do ICMS no Simples Nacional',
            '201' => 'Tributada pelo Simples Nacional com permissão de crédito e com cobrança do ICMS por substituição tributária',
            '202' => 'Tributada pelo Simples Nacional sem permissão de crédito e com cobrança do ICMS por substituição tributária',
            '203' => 'Isenção do ICMS no Simples Nacional e com cobrança do ICMS por substituição tributária',
            '300' => 'Imune',
            '400' => 'Não tributada pelo Simples Nacional',
            '500' => 'ICMS cobrado anteriormente por substituição tributária (substituído) ou por antecipação'
        ];
        
        return $descricoes[$cst] ?? 'CST não mapeado';
    }

    private function simularChaveAcesso(array $dados): string
    {
        // Simula chave baseada nos dados (para validação prévia)
        $uf = $dados['emitente']['endereco']['cUF'] ?? '42';
        $cnpj = preg_replace('/\D/', '', $dados['emitente']['CNPJ'] ?? '00000000000000');
        $modelo = '55';
        $serie = str_pad($dados['identificacao']['serie'] ?? '1', 3, '0', STR_PAD_LEFT);
        $numero = str_pad($dados['identificacao']['nNF'] ?? '1', 9, '0', STR_PAD_LEFT);
        $codigo = str_pad(rand(10000000, 99999999), 8, '0');
        
        $chave = $uf . date('ym') . $cnpj . $modelo . $serie . $numero . '1' . $codigo;
        $dv = $this->calcularDV($chave);
        
        return $chave . $dv;
    }
    
    private function calcularDV(string $chave): string
    {
        $soma = 0;
        $peso = 2;
        
        for ($i = strlen($chave) - 1; $i >= 0; $i--) {
            $soma += (int)$chave[$i] * $peso;
            $peso = $peso == 9 ? 2 : $peso + 1;
        }
        
        $dv = 11 - ($soma % 11);
        return $dv >= 10 ? '0' : (string)$dv;
    }
}
