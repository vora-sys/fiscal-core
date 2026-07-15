<?php

namespace sabbajohn\FiscalCore\Facade;

use sabbajohn\FiscalCore\Adapters\ImpressaoAdapter;
use sabbajohn\FiscalCore\Support\FiscalResponse;
use sabbajohn\FiscalCore\Support\FiscalResponseNormalizer;
use sabbajohn\FiscalCore\Support\ResponseHandler;

/**
 * Facade para impressão de documentos fiscais
 * Interface simplificada para DANFE, DANFCE, DACTE e DAMDFE
 */
class ImpressaoFacade
{
    private ImpressaoAdapter $impressao;

    private ResponseHandler $responseHandler;

    private FiscalResponseNormalizer $normalizer;

    public function __construct(?ImpressaoAdapter $impressao = null)
    {
        $this->responseHandler = new ResponseHandler;
        $this->impressao = $impressao ?? new ImpressaoAdapter;
        $this->normalizer = new FiscalResponseNormalizer;
    }

    /**
     * Gera DANFE (PDF da NFe)
     */
    public function gerarDanfe(string $xmlNfe): FiscalResponse
    {
        try {
            if (! function_exists('imagefontheight')) {
                return FiscalResponse::error(
                    'Extensão GD não disponível no runtime PHP para gerar DANFE.',
                    'GD_EXTENSION_MISSING',
                    'impressao_danfe',
                    [
                        'category' => 'configuration',
                        'suggestions' => [
                            'Rebuild do container PHP com a extensão gd habilitada',
                            'Verifique se imagefontheight() está disponível no PHP em execução',
                        ],
                    ]
                );
            }

            if (empty($xmlNfe)) {
                return FiscalResponse::error(
                    'XML da NFe não pode estar vazio',
                    'XML_EMPTY',
                    'impressao_danfe',
                    [
                        'category' => 'validation',
                        'suggestions' => [
                            'Forneça um XML válido de NFe autorizada',
                            'Verifique se o XML contém todos os elementos obrigatórios',
                        ],
                    ]
                );
            }

            $pdf = $this->impressao->gerarDanfe($xmlNfe);

            return FiscalResponse::success($this->normalizer->normalizeImpressaoPdf(
                'nfe',
                'impressao_danfe',
                $xmlNfe,
                $pdf,
                'danfe_'.date('Ymd_His').'.pdf',
                [
                    'type' => 'danfe_nfe',
                    'xml_size' => strlen($xmlNfe),
                ]
            ) + [
                'pdf' => $pdf,
                'size' => strlen($pdf),
                'type' => 'danfe_nfe',
            ], 'impressao_danfe', [
                'xml_size' => strlen($xmlNfe),
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
            if (! function_exists('imagefontheight')) {
                return FiscalResponse::error(
                    'Extensão GD não disponível no runtime PHP para gerar DANFCE.',
                    'GD_EXTENSION_MISSING',
                    'impressao_danfce',
                    [
                        'category' => 'configuration',
                        'suggestions' => [
                            'Rebuild do container PHP com a extensão gd habilitada',
                            'Verifique se imagefontheight() está disponível no PHP em execução',
                        ],
                    ]
                );
            }

            if (empty($xmlNfce)) {
                return FiscalResponse::error(
                    'XML da NFCe não pode estar vazio',
                    'XML_EMPTY',
                    'impressao_danfce',
                    [
                        'category' => 'validation',
                        'suggestions' => [
                            'Forneça um XML válido de NFCe autorizada',
                            'Verifique se o XML contém o QR Code obrigatório',
                        ],
                    ]
                );
            }

            $pdf = $this->impressao->gerarDanfce($xmlNfce, $context);

            return FiscalResponse::success($this->normalizer->normalizeImpressaoPdf(
                'nfce',
                'impressao_danfce',
                $xmlNfce,
                $pdf,
                'danfce_'.date('Ymd_His').'.pdf',
                [
                    'print_source' => 'custom_thermal_layout',
                    'type' => 'danfce_nfce',
                    'xml_size' => strlen($xmlNfce),
                ]
            ) + [
                'pdf' => $pdf,
                'size' => strlen($pdf),
                'type' => 'danfce_nfce',
            ], 'impressao_danfce', [
                'xml_size' => strlen($xmlNfce),
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
            if (! function_exists('imagefontheight')) {
                return FiscalResponse::error(
                    'Extensão GD não disponível no runtime PHP para gerar DACTE.',
                    'GD_EXTENSION_MISSING',
                    'impressao_dacte',
                    [
                        'category' => 'configuration',
                        'suggestions' => [
                            'Rebuild do container PHP com a extensão gd habilitada',
                        ],
                    ]
                );
            }

            if (empty($xmlCte)) {
                return FiscalResponse::error(
                    'XML do CTe não pode estar vazio',
                    'XML_EMPTY',
                    'impressao_dacte',
                    [
                        'category' => 'validation',
                        'suggestions' => [
                            'Forneça um XML válido de CTe autorizado',
                            'Verifique se o XML contém todos os dados de transporte',
                        ],
                    ]
                );
            }

            $pdf = $this->impressao->gerarCte($xmlCte);

            return FiscalResponse::success($this->normalizer->normalizeImpressaoPdf(
                'cte',
                'impressao_dacte',
                $xmlCte,
                $pdf,
                'dacte_'.date('Ymd_His').'.pdf',
                [
                    'type' => 'dacte_cte',
                    'xml_size' => strlen($xmlCte),
                ]
            ) + [
                'pdf' => $pdf,
                'size' => strlen($pdf),
                'type' => 'dacte_cte',
            ], 'impressao_dacte', [
                'xml_size' => strlen($xmlCte),
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
            if (! function_exists('imagefontheight')) {
                return FiscalResponse::error(
                    'Extensão GD não disponível no runtime PHP para gerar DAMDFE.',
                    'GD_EXTENSION_MISSING',
                    'impressao_damdfe',
                    [
                        'category' => 'configuration',
                        'suggestions' => [
                            'Rebuild do container PHP com a extensão gd habilitada',
                        ],
                    ]
                );
            }

            if (empty($xmlMdfe)) {
                return FiscalResponse::error(
                    'XML do MDFe não pode estar vazio',
                    'XML_EMPTY',
                    'impressao_damdfe',
                    [
                        'category' => 'validation',
                        'suggestions' => [
                            'Forneça um XML válido de MDFe autorizado',
                            'Verifique se o XML contém todos os manifestos eletrônicos',
                        ],
                    ]
                );
            }

            $pdf = $this->impressao->gerarMdfe($xmlMdfe);

            return FiscalResponse::success($this->normalizer->normalizeImpressaoPdf(
                'mdfe',
                'impressao_damdfe',
                $xmlMdfe,
                $pdf,
                'damdfe_'.date('Ymd_His').'.pdf',
                [
                    'type' => 'damdfe_mdfe',
                    'xml_size' => strlen($xmlMdfe),
                ]
            ) + [
                'pdf' => $pdf,
                'size' => strlen($pdf),
                'type' => 'damdfe_mdfe',
            ], 'impressao_damdfe', [
                'xml_size' => strlen($xmlMdfe),
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
                    'Conteúdo do PDF está vazio',
                    'PDF_CONTENT_EMPTY',
                    'impressao_salvar_pdf',
                    [
                        'category' => 'validation',
                        'suggestions' => [
                            'Gere o PDF primeiro usando os métodos gerarDanfe(), gerarDanfce(), etc.',
                            'Verifique se o conteúdo do PDF foi gerado corretamente',
                        ],
                    ]
                );
            }

            if (empty($filename)) {
                return FiscalResponse::error(
                    'Nome do arquivo não pode estar vazio',
                    'FILENAME_EMPTY',
                    'impressao_salvar_pdf',
                    [
                        'category' => 'validation',
                        'suggestions' => [
                            'Forneça um nome válido para o arquivo',
                            'Exemplo: "danfe_12345.pdf"',
                        ],
                    ]
                );
            }

            // Adiciona extensão se não tiver
            if (! str_ends_with($filename, '.pdf')) {
                $filename .= '.pdf';
            }

            $bytes = file_put_contents($filename, $pdfContent);

            if ($bytes === false) {
                return FiscalResponse::error(
                    'Falha ao salvar arquivo PDF',
                    'FILE_WRITE_FAILED',
                    'impressao_salvar_pdf',
                    [
                        'category' => 'runtime',
                        'filename' => $filename,
                        'suggestions' => [
                            'Verifique se o diretório tem permissão de escrita',
                            'Verifique se há espaço disponível em disco',
                            'Tente usar um caminho absoluto para o arquivo',
                        ],
                    ]
                );
            }

            return FiscalResponse::success([
                'filename' => $filename,
                'size' => $bytes,
                'saved' => true,
                'full_path' => realpath($filename),
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
                    'XML não pode estar vazio',
                    'XML_EMPTY',
                    'impressao_validar_xml',
                    [
                        'category' => 'validation',
                        'tipo' => $tipo,
                        'suggestions' => ['Forneça um XML válido'],
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
                    'XML inválido: '.implode('; ', $errorMessages),
                    'XML_INVALID',
                    'impressao_validar_xml',
                    [
                        'category' => 'xml',
                        'tipo' => $tipo,
                        'xml_errors' => $errorMessages,
                        'suggestions' => [
                            'Verifique a estrutura do XML',
                            'Confirme que o XML está bem formado',
                            'Use um validador XML para identificar problemas',
                        ],
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
                'validations' => $validationResults,
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
                'libxml' => extension_loaded('libxml'),
            ];

            $missing = array_filter($extensions, function ($loaded) {
                return ! $loaded;
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
                    'file_saving' => true,
                ],
            ], 'impressao_status');

        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'impressao_status');
        }
    }
}
