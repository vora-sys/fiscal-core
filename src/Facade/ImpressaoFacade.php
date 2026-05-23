<?php

namespace sabbajohn\FiscalCore\Facade;

use sabbajohn\FiscalCore\Adapters\ImpressaoAdapter;
use sabbajohn\FiscalCore\Support\ResponseHandler;
use sabbajohn\FiscalCore\Support\FiscalResponse;

/**
 * Facade para impressão de documentos fiscais
 * Interface simplificada para DANFE, DANFCE, DACTE e DAMDFE
 */
class ImpressaoFacade
{
    private ImpressaoAdapter $impressao;
    private ResponseHandler $responseHandler;

    public function __construct(?ImpressaoAdapter $impressao = null)
    {
        $this->responseHandler = new ResponseHandler();
        $this->impressao = $impressao ?? new ImpressaoAdapter();
    }

    /**
     * Gera DANFE (PDF da NFe)
     */
    public function gerarDanfe(string $xmlNfe): FiscalResponse
    {
        try {
            if (!function_exists('imagefontheight')) {
                return FiscalResponse::error(
                    'GD_EXTENSION_MISSING',
                    'Extensão GD não disponível no runtime PHP para gerar DANFE.',
                    'impressao_danfe',
                    [
                        'suggestions' => [
                            'Rebuild do container PHP com a extensão gd habilitada',
                            'Verifique se imagefontheight() está disponível no PHP em execução',
                        ],
                    ]
                );
            }

            if (empty($xmlNfe)) {
                return FiscalResponse::error(
                    'XML_EMPTY',
                    'XML da NFe não pode estar vazio',
                    'impressao_danfe',
                    [
                        'suggestions' => [
                            'Forneça um XML válido de NFe autorizada',
                            'Verifique se o XML contém todos os elementos obrigatórios'
                        ]
                    ]
                );
            }

            $pdf = $this->impressao->gerarDanfe($xmlNfe);
            
            return FiscalResponse::success([
                'pdf' => $pdf,
                'size' => strlen($pdf),
                'type' => 'danfe_nfe'
            ], 'impressao_danfe', [
                'xml_size' => strlen($xmlNfe)
            ]);
            
        } catch (\Throwable $e) {
            return $this->responseHandler->handle($e, 'impressao_danfe');
        }
    }

    /**
     * Gera DANFCE (PDF da NFCe)
     */
    public function gerarDanfce(string $xmlNfce, array $context = []): FiscalResponse
    {
        try {
            if (!function_exists('imagefontheight')) {
                return FiscalResponse::error(
                    'GD_EXTENSION_MISSING',
                    'Extensão GD não disponível no runtime PHP para gerar DANFCE.',
                    'impressao_danfce',
                    [
                        'suggestions' => [
                            'Rebuild do container PHP com a extensão gd habilitada',
                            'Verifique se imagefontheight() está disponível no PHP em execução',
                        ],
                    ]
                );
            }

            if (empty($xmlNfce)) {
                return FiscalResponse::error(
                    'XML_EMPTY',
                    'XML da NFCe não pode estar vazio',
                    'impressao_danfce',
                    [
                        'suggestions' => [
                            'Forneça um XML válido de NFCe autorizada',
                            'Verifique se o XML contém o QR Code obrigatório'
                        ]
                    ]
                );
            }

            $pdf = $this->impressao->gerarDanfce($xmlNfce, $context);
            
            return FiscalResponse::success([
                'pdf' => $pdf,
                'size' => strlen($pdf),
                'type' => 'danfce_nfce'
            ], 'impressao_danfce', [
                'xml_size' => strlen($xmlNfce)
            ]);
            
        } catch (\Throwable $e) {
            return $this->responseHandler->handle($e, 'impressao_danfce');
        }
    }

    /**
     * Gera DACTE (PDF do CTe)
     */
    public function gerarDacte(string $xmlCte): FiscalResponse
    {
        try {
            if (!function_exists('imagefontheight')) {
                return FiscalResponse::error(
                    'GD_EXTENSION_MISSING',
                    'Extensão GD não disponível no runtime PHP para gerar DACTE.',
                    'impressao_dacte',
                    [
                        'suggestions' => [
                            'Rebuild do container PHP com a extensão gd habilitada',
                        ],
                    ]
                );
            }

            if (empty($xmlCte)) {
                return FiscalResponse::error(
                    'XML_EMPTY',
                    'XML do CTe não pode estar vazio',
                    'impressao_dacte',
                    [
                        'suggestions' => [
                            'Forneça um XML válido de CTe autorizado',
                            'Verifique se o XML contém todos os dados de transporte'
                        ]
                    ]
                );
            }

            $pdf = $this->impressao->gerarCte($xmlCte);
            
            return FiscalResponse::success([
                'pdf' => $pdf,
                'size' => strlen($pdf),
                'type' => 'dacte_cte'
            ], 'impressao_dacte', [
                'xml_size' => strlen($xmlCte)
            ]);
            
        } catch (\Throwable $e) {
            return $this->responseHandler->handle($e, 'impressao_dacte');
        }
    }

    /**
     * Gera DAMDFE (PDF do MDFe)
     */
    public function gerarDamdfe(string $xmlMdfe): FiscalResponse
    {
        try {
            if (!function_exists('imagefontheight')) {
                return FiscalResponse::error(
                    'GD_EXTENSION_MISSING',
                    'Extensão GD não disponível no runtime PHP para gerar DAMDFE.',
                    'impressao_damdfe',
                    [
                        'suggestions' => [
                            'Rebuild do container PHP com a extensão gd habilitada',
                        ],
                    ]
                );
            }

            if (empty($xmlMdfe)) {
                return FiscalResponse::error(
                    'XML_EMPTY',
                    'XML do MDFe não pode estar vazio',
                    'impressao_damdfe',
                    [
                        'suggestions' => [
                            'Forneça um XML válido de MDFe autorizado',
                            'Verifique se o XML contém todos os manifestos eletrônicos'
                        ]
                    ]
                );
            }

            $pdf = $this->impressao->gerarMdfe($xmlMdfe);
            
            return FiscalResponse::success([
                'pdf' => $pdf,
                'size' => strlen($pdf),
                'type' => 'damdfe_mdfe'
            ], 'impressao_damdfe', [
                'xml_size' => strlen($xmlMdfe)
            ]);
            
        } catch (\Throwable $e) {
            return $this->responseHandler->handle($e, 'impressao_damdfe');
        }
    }

    /**
     * Salva PDF em arquivo
     */
    public function salvarPdf(string $pdfContent, string $filename): FiscalResponse
    {
        try {
            if (empty($pdfContent)) {
                return FiscalResponse::error(
                    'PDF_CONTENT_EMPTY',
                    'Conteúdo do PDF está vazio',
                    'impressao_salvar_pdf',
                    [
                        'suggestions' => [
                            'Gere o PDF primeiro usando os métodos gerarDanfe(), gerarDanfce(), etc.',
                            'Verifique se o conteúdo do PDF foi gerado corretamente'
                        ]
                    ]
                );
            }

            if (empty($filename)) {
                return FiscalResponse::error(
                    'FILENAME_EMPTY',
                    'Nome do arquivo não pode estar vazio',
                    'impressao_salvar_pdf',
                    [
                        'suggestions' => [
                            'Forneça um nome válido para o arquivo',
                            'Exemplo: "danfe_12345.pdf"'
                        ]
                    ]
                );
            }

            // Adiciona extensão se não tiver
            if (!str_ends_with($filename, '.pdf')) {
                $filename .= '.pdf';
            }

            $bytes = file_put_contents($filename, $pdfContent);
            
            if ($bytes === false) {
                return FiscalResponse::error(
                    'FILE_WRITE_FAILED',
                    'Falha ao salvar arquivo PDF',
                    'impressao_salvar_pdf',
                    [
                        'filename' => $filename,
                        'suggestions' => [
                            'Verifique se o diretório tem permissão de escrita',
                            'Verifique se há espaço disponível em disco',
                            'Tente usar um caminho absoluto para o arquivo'
                        ]
                    ]
                );
            }

            return FiscalResponse::success([
                'filename' => $filename,
                'size' => $bytes,
                'saved' => true,
                'full_path' => realpath($filename)
            ], 'impressao_salvar_pdf');
            
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'impressao_salvar_pdf');
        }
    }

    /**
     * Valida XML antes da impressão
     */
    public function validarXML(string $xml, string $tipo = 'nfe'): FiscalResponse
    {
        try {
            if (empty($xml)) {
                return FiscalResponse::error(
                    'XML_EMPTY',
                    'XML não pode estar vazio',
                    'impressao_validar_xml',
                    [
                        'tipo' => $tipo,
                        'suggestions' => ['Forneça um XML válido']
                    ]
                );
            }

            // Tenta carregar como XML
            libxml_use_internal_errors(true);
            $xmlDoc = simplexml_load_string($xml);
            $errors = libxml_get_errors();

            if ($xmlDoc === false) {
                $errorMessages = [];
                foreach ($errors as $error) {
                    $errorMessages[] = trim($error->message);
                }

                return FiscalResponse::error(
                    'XML_INVALID',
                    'XML inválido: ' . implode('; ', $errorMessages),
                    'impressao_validar_xml',
                    [
                        'tipo' => $tipo,
                        'xml_errors' => $errorMessages,
                        'suggestions' => [
                            'Verifique a estrutura do XML',
                            'Confirme que o XML está bem formado',
                            'Use um validador XML para identificar problemas'
                        ]
                    ]
                );
            }

            // Validações específicas por tipo
            $validationResults = [];
            
            switch ($tipo) {
                case 'nfe':
                    $validationResults['has_infnfe'] = isset($xmlDoc->infNFe);
                    $validationResults['has_chave'] = isset($xmlDoc->infNFe['Id']);
                    break;
                    
                case 'nfce':
                    $validationResults['has_infnfe'] = isset($xmlDoc->infNFe);
                    $validationResults['has_qrcode'] = isset($xmlDoc->infNFeSupl);
                    break;
                    
                case 'cte':
                    $validationResults['has_infcte'] = isset($xmlDoc->infCte);
                    break;
                    
                case 'mdfe':
                    $validationResults['has_infmdfe'] = isset($xmlDoc->infMDFe);
                    break;
            }

            return FiscalResponse::success([
                'valid' => true,
                'tipo' => $tipo,
                'size' => strlen($xml),
                'validations' => $validationResults
            ], 'impressao_validar_xml');
            
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'impressao_validar_xml');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors(false);
        }
    }

    /**
     * Verifica status do serviço de impressão
     */
    public function verificarStatus(): FiscalResponse
    {
        try {
            $extensions = [
                'dom' => extension_loaded('dom'),
                'xml' => extension_loaded('xml'),
                'simplexml' => extension_loaded('simplexml'),
                'libxml' => extension_loaded('libxml')
            ];

            $missing = array_filter($extensions, function($loaded) {
                return !$loaded;
            });

            $status = empty($missing) ? 'ready' : 'missing_extensions';

            return FiscalResponse::success([
                'status' => $status,
                'extensions' => $extensions,
                'missing_extensions' => array_keys($missing),
                'capabilities' => [
                    'danfe' => true,
                    'danfce' => true,
                    'dacte' => true,
                    'damdfe' => true,
                    'xml_validation' => true,
                    'file_saving' => true
                ]
            ], 'impressao_status');
            
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'impressao_status');
        }
    }
}
