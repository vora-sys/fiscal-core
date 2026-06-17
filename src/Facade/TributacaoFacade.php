<?php

namespace sabbajohn\FiscalCore\Facade;

use sabbajohn\FiscalCore\Adapters\IBPTAdapter;
use sabbajohn\FiscalCore\Adapters\BrasilAPIAdapter;
use sabbajohn\FiscalCore\Support\ResponseHandler;
use sabbajohn\FiscalCore\Support\FiscalResponse;
use sabbajohn\FiscalCore\Support\FiscalResponseNormalizer;

/**
 * Facade para operações de tributação
 * Interface simplificada para IBPT, consultas NCM e cálculos tributários
 */
class TributacaoFacade
{
    private ?IBPTAdapter $ibpt = null;
    private BrasilAPIAdapter $brasilApi;
    private ResponseHandler $responseHandler;
    private FiscalResponseNormalizer $normalizer;
    private ?FiscalResponse $initializationError = null;

    public function __construct(?IBPTAdapter $ibpt = null)
    {
        $this->responseHandler = new ResponseHandler();
        $this->normalizer = new FiscalResponseNormalizer();
        $this->brasilApi = new BrasilAPIAdapter();
        
        if ($ibpt !== null) {
            $this->ibpt = $ibpt;
        } else {
            try {
                // Tenta carregar configuração IBPT do ambiente
                $cnpj = $_ENV['IBPT_CNPJ'] ?? '';
                $token = $_ENV['IBPT_TOKEN'] ?? '';
                $uf = $_ENV['IBPT_UF'] ?? 'SP';
                
                if (empty($cnpj) || empty($token)) {
                    $this->initializationError = FiscalResponse::error(
                        'Configuração IBPT não encontrada',
                        'IBPT_CONFIG_MISSING',
                        'tributacao_initialization',
                        [
                            'category' => 'configuration',
                            'required_env_vars' => ['IBPT_CNPJ', 'IBPT_TOKEN'],
                            'optional_env_vars' => ['IBPT_UF'],
                            'suggestions' => [
                                'Configure as variáveis de ambiente IBPT_CNPJ e IBPT_TOKEN',
                                'Obtenha suas credenciais em https://ibpt.com.br',
                                'Defina IBPT_UF com a UF padrão (opcional, default: SP)'
                            ]
                        ]
                    );
                } else {
                    $this->ibpt = new IBPTAdapter($cnpj, $token, $uf);
                }
            } catch (\Exception $e) {
                $this->initializationError = $this->responseHandler->handle($e, 'tributacao_initialization');
            }
        }
    }

    /**
     * Verifica se o IBPT está inicializado
     */
    private function checkIBPTInitialization(): ?FiscalResponse
    {
        if ($this->initializationError !== null) {
            return $this->initializationError;
        }
        return null;
    }

    /**
     * Calcula impostos de um produto usando IBPT
     */
    public function calcular(array $produto): FiscalResponse
    {
        if ($check = $this->checkIBPTInitialization()) {
            return $check;
        }

        try {
            $resultado = $this->ibpt->calcularImpostos($produto);
            return FiscalResponse::success($this->normalizer->normalizeTributacao('tributacao_calculo', $resultado, [
                'ncm' => $produto['ncm'] ?? null,
                'valor' => $produto['valor'] ?? null,
                'request_payload' => $produto,
            ]), 'tributacao_calculo', [
                'ncm' => $produto['ncm'] ?? null,
                'valor' => $produto['valor'] ?? null
            ]);
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'tributacao_calculo');
        }
    }

    /**
     * Consulta NCM via BrasilAPI (não precisa do IBPT)
     */
    public function consultarNCM(string $ncm): FiscalResponse
    {
        try {
            $resultado = $this->brasilApi->consultaNcm($ncm);
            if (is_array($resultado) && isset($resultado['codigo'])) {
                $resultado['codigo'] = preg_replace('/\D/', '', (string) $resultado['codigo']);
            }

            return FiscalResponse::success($this->normalizer->normalizeTributacao('tributacao_consulta_ncm', $resultado, [
                'ncm' => $ncm
            ]), 'tributacao_consulta_ncm', [
                'ncm' => $ncm
            ]);
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'tributacao_consulta_ncm');
        }
    }

    /**
     * Pesquisa NCMs por descrição
     */
    public function pesquisarNCM(string $descricao): FiscalResponse
    {
        try {
            $resultado = $this->brasilApi->pesquisarNcm($descricao);
            return FiscalResponse::success($this->normalizer->normalizeTributacao('tributacao_pesquisa_ncm', is_array($resultado) ? $resultado : ['resultado' => $resultado], [
                'descricao' => $descricao,
                'total_results' => count($resultado)
            ]), 'tributacao_pesquisa_ncm', [
                'descricao' => $descricao,
                'total_results' => count($resultado)
            ]);
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'tributacao_pesquisa_ncm');
        }
    }

    /**
     * Lista todos os NCMs disponíveis
     */
    public function listarNCMs(): FiscalResponse
    {
        try {
            $resultado = $this->brasilApi->listarNcms();
            return FiscalResponse::success($resultado, 'tributacao_listar_ncms', [
                'total_ncms' => count($resultado)
            ]);
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'tributacao_listar_ncms');
        }
    }

    /**
     * Consulta CEP (funcionalidade adicional)
     */
    public function consultarCEP(string $cep): FiscalResponse
    {
        try {
            $resultado = $this->brasilApi->consultarCEP($cep);
            return FiscalResponse::success($resultado, 'tributacao_consulta_cep', [
                'cep' => $cep
            ]);
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'tributacao_consulta_cep');
        }
    }

    /**
     * Consulta CNPJ (funcionalidade adicional)
     */
    public function consultarCNPJ(string $cnpj): FiscalResponse
    {
        try {
            $resultado = $this->brasilApi->consultarCNPJ($cnpj);
            return FiscalResponse::success($resultado, 'tributacao_consulta_cnpj', [
                'cnpj' => $cnpj
            ]);
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'tributacao_consulta_cnpj');
        }
    }

    /**
     * Lista bancos disponíveis
     */
    public function listarBancos(): FiscalResponse
    {
        try {
            $resultado = $this->brasilApi->listarBancos();
            return FiscalResponse::success($resultado, 'tributacao_listar_bancos', [
                'total_bancos' => count($resultado)
            ]);
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'tributacao_listar_bancos');
        }
    }

    /**
     * Consulta banco por código
     */
    public function consultarBanco(string $codigo): FiscalResponse
    {
        try {
            $resultado = $this->brasilApi->consultarBanco($codigo);
            return FiscalResponse::success($resultado, 'tributacao_consulta_banco', [
                'codigo' => $codigo
            ]);
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'tributacao_consulta_banco');
        }
    }

    /**
     * Verifica status dos serviços de tributação
     */
    public function verificarStatus(): FiscalResponse
    {
        try {
            $status = [
                'ibpt' => [
                    'available' => $this->ibpt !== null,
                    'error' => $this->initializationError?->getError()
                ],
                'brasil_api' => [
                    'available' => true,
                    'service' => 'BrasilAPI'
                ]
            ];

            $overall = $this->ibpt !== null ? 'full_available' : 'partial_available';

            return FiscalResponse::success([
                'status' => $overall,
                'services' => $status,
                'capabilities' => [
                    'calculo_impostos' => $this->ibpt !== null,
                    'consulta_ncm' => true,
                    'consulta_cep' => true,
                    'consulta_cnpj' => true,
                    'consulta_bancos' => true
                ]
            ], 'tributacao_status');
            
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'tributacao_status');
        }
    }

    /**
     * Valida se um código NCM é válido
     */
    public function validarNCM(string $ncm): FiscalResponse
    {
        return $this->responseHandler->execute(function() use ($ncm) {
            $ncm = trim($ncm);
            
            // Verifica se tem 8 dígitos
            if (strlen($ncm) !== 8) {
                throw new \InvalidArgumentException("NCM deve conter exatamente 8 dígitos");
            }
            
            // Valida se é numérico
            if (!is_numeric($ncm)) {
                throw new \InvalidArgumentException("NCM deve conter apenas números");
            }

            if ($ncm === '00000000') {
                throw new \InvalidArgumentException("NCM não pode conter apenas zeros");
            }
            
            return [
                'ncm' => $ncm,
                'valido' => true,
                'formatado' => substr($ncm, 0, 4) . '.' . substr($ncm, 4, 2) . '.' . substr($ncm, 6, 2)
            ];
        }, 'validacao_ncm');
    }
    
    /**
     * Valida se um código CEST é válido
     */
    public function validarCEST(string $cest): FiscalResponse
    {
        return $this->responseHandler->execute(function() use ($cest) {
            // Remove caracteres não numéricos
            $cest = preg_replace('/\D/', '', $cest);
            
            // Verifica se tem 7 dígitos
            if (strlen($cest) !== 7) {
                throw new \InvalidArgumentException("CEST deve conter exatamente 7 dígitos");
            }
            
            return [
                'cest' => $cest,
                'valido' => true,
                'formatado' => substr($cest, 0, 2) . '.' . substr($cest, 2, 3) . '.' . substr($cest, 5, 2)
            ];
        }, 'validacao_cest');
    }

    /**
     * Validação avançada de produto para cálculo
     */
    public function validarProduto(array $produto): FiscalResponse
    {
        try {
            $required = ['ncm', 'valor'];
            $missing = [];
            
            foreach ($required as $field) {
                if (!isset($produto[$field])) {
                    $missing[] = $field;
                }
            }
            
            if (!empty($missing)) {
                return FiscalResponse::error(
                    'Campos obrigatórios não informados: ' . implode(', ', $missing),
                    'PRODUCT_VALIDATION_FAILED',
                    'tributacao_validacao_produto',
                    [
                        'category' => 'validation',
                        'missing_fields' => $missing,
                        'required_fields' => $required,
                        'optional_fields' => ['uf', 'extarif', 'descricao', 'unidade', 'gtin', 'codigoInterno'],
                        'suggestions' => [
                            'Informe o NCM do produto',
                            'Informe o valor do produto',
                            'Campos opcionais: UF, descrição, unidade, GTIN, código interno'
                        ]
                    ]
                );
            }

            // Validações específicas
            $warnings = [];
            
            if (strlen($produto['ncm']) !== 8) {
                $warnings[] = 'NCM deve ter exatamente 8 dígitos';
            }
            
            if ($produto['valor'] <= 0) {
                $warnings[] = 'Valor deve ser maior que zero';
            }

            return FiscalResponse::success([
                'valid' => true,
                'warnings' => $warnings,
                'produto' => $produto
            ], 'tributacao_validacao_produto');
            
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'tributacao_validacao_produto');
        }
    }

    /**
     * Calcula ICMS de um produto
     */
    public function calcularICMS(array $dados): FiscalResponse
    {
        return $this->responseHandler->execute(function() use ($dados) {
            // Normaliza campos para compatibilidade
            if (isset($dados['origem'])) $dados['uf_origem'] = $dados['origem'];
            if (isset($dados['destino'])) $dados['uf_destino'] = $dados['destino'];
            
            // Validação dos dados obrigatórios
            $required = ['valor', 'uf_origem', 'uf_destino', 'ncm'];
            foreach ($required as $field) {
                if (empty($dados[$field])) {
                    $mensagem = $field === 'ncm' ? 'NCM é obrigatório' : "Campo '{$field}' é obrigatório";
                    throw new \InvalidArgumentException($mensagem);
                }
            }
            
            if ($dados['valor'] <= 0) {
                throw new \InvalidArgumentException('Valor deve ser maior que zero');
            }
            
            // Validação básica das UFs
            $ufsValidas = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
            if (!in_array(strtoupper($dados['uf_origem']), $ufsValidas)) {
                throw new \InvalidArgumentException('UF de origem inválida');
            }
            if (!in_array(strtoupper($dados['uf_destino']), $ufsValidas)) {
                throw new \InvalidArgumentException('UF de destino inválida');
            }
            
            // Validação NCM
            if (!empty($dados['ncm'])) {
                $ncm = preg_replace('/\D/', '', $dados['ncm']);
                if (strlen($ncm) !== 8) {
                    throw new \InvalidArgumentException('NCM é obrigatório');
                }
            }
            
            // Simulação de cálculo ICMS
            $valor = (float) $dados['valor'];
            $regime = $dados['regime_tributario'] ?? 'normal';
            $operacaoInterna = strtoupper($dados['uf_origem']) === strtoupper($dados['uf_destino']);
            
            switch ($regime) {
                case 'simples_nacional':
                    $aliquota = 0.04; // 4%
                    break;
                case 'normal':
                    $aliquota = $operacaoInterna ? 0.18 : 0.12; // 18% interna, 12% interestadual
                    break;
                default:
                    throw new \InvalidArgumentException('Regime tributário inválido');
            }
            
            $icms = $valor * $aliquota;
            
            // Verificar substituição tributária se CST for 60
            $substituicaoTributaria = ($dados['cst'] ?? '') === '60';
            $valorST = 0;
            
            if ($substituicaoTributaria) {
                // Simulação de cálculo de ST
                $valorST = $valor * 0.05; // 5% fictício para ST
            }
            
            return [
                'valor_produto' => $valor,
                'aliquota' => $aliquota * 100, // Compatibilidade com teste
                'aliquota_icms' => $aliquota * 100,
                'valor_icms' => round($icms, 2),
                'regime_tributario' => $regime,
                'operacao_interna' => $operacaoInterna,
                'substituicao_tributaria' => $substituicaoTributaria,
                'valor_st' => round($valorST, 2)
            ];
        }, 'calculo_icms');
    }

    /**
     * Verifica se produto está sujeito à substituição tributária
     */
    public function verificarSubstituicaoTributaria(array $produto): FiscalResponse
    {
        return $this->responseHandler->execute(function() use ($produto) {
            if (empty($produto['ncm'])) {
                throw new \InvalidArgumentException('NCM é obrigatório');
            }
            
            $ncm = preg_replace('/\D/', '', $produto['ncm']);
            
            // Lista simplificada de NCMs sujeitos à ST
            $ncmsComST = [
                '22030001', // Cerveja de malte
                '22071000', // Álcool etílico
                '22021000', // Água mineral
                '87030100', // Veículos de passeio
                '27101129', // Combustíveis
                '27101199', // Derivados de petróleo
                '27101259', // Gasolina
                '39241000'  // Pratos, copos descartáveis
            ];
            
            $sujeitoST = in_array($ncm, $ncmsComST);
            $uf = strtoupper($produto['uf'] ?? 'SP');
            
            return [
                'ncm' => $ncm,
                'sujeito_st' => $sujeitoST,
                'uf_aplicacao' => $uf,
                'observacoes' => $sujeitoST ? 'Produto sujeito à Substituição Tributária' : 'Produto não sujeito à ST'
            ];
        }, 'verificacao_st');
    }

    /**
     * Consulta alíquota IPI para um NCM
     */
    public function consultarAliquotaIPI(string $ncm): FiscalResponse
    {
        return $this->responseHandler->execute(function() use ($ncm) {
            $ncm = preg_replace('/\D/', '', $ncm);
            
            if (strlen($ncm) !== 8) {
                throw new \InvalidArgumentException('NCM deve ter 8 dígitos');
            }
            
            // Simulação de consulta de IPI baseada em faixas de NCM
            $primeirosDigitos = substr($ncm, 0, 2);
            
            $aliquotasIPI = [
                '22' => 20,    // Bebidas alcoólicas
                '39' => 5,     // Plásticos
                '87' => 25,    // Veículos
                '27' => 0,     // Combustíveis
                '84' => 0,     // Máquinas
                '85' => 10     // Equipamentos eletrônicos
            ];
            
            $aliquota = $aliquotasIPI[$primeirosDigitos] ?? 5; // 5% padrão
            
            return [
                'ncm' => $ncm,
                'ncm_formatado' => substr($ncm, 0, 4) . '.' . substr($ncm, 4, 2) . '.' . substr($ncm, 6, 2),
                'aliquota' => $aliquota,
                'aliquota_ipi' => $aliquota,
                'tributado_ipi' => $aliquota > 0,
                'capitulo' => $primeirosDigitos
            ];
        }, 'consulta_aliquota_ipi');
    }

    /**
     * Analisa hierarquia do NCM
     */
    public function analisarHieraquiaNCM(string $ncm): FiscalResponse
    {
        return $this->responseHandler->execute(function() use ($ncm) {
            $ncm = preg_replace('/\D/', '', $ncm);
            
            if (strlen($ncm) !== 8) {
                throw new \InvalidArgumentException('NCM deve ter 8 dígitos');
            }
            
            $capitulo = substr($ncm, 0, 2);
            $posicao = substr($ncm, 0, 4);
            $subposicao1 = substr($ncm, 0, 6);
            $subposicao2 = $ncm;
            
            // Mapeamento básico de capítulos
            $capitulos = [
                '22' => 'Bebidas, líquidos alcoólicos e vinagres',
                '39' => 'Plásticos e suas obras',
                '87' => 'Veículos automóveis, tratores, ciclos',
                '27' => 'Combustíveis minerais, óleos minerais',
                '84' => 'Reatores nucleares, caldeiras, máquinas',
                '85' => 'Máquinas, aparelhos e materiais elétricos'
            ];
            
            return [
                'ncm' => $ncm,
                'ncm_formatado' => substr($ncm, 0, 4) . '.' . substr($ncm, 4, 2) . '.' . substr($ncm, 6, 2),
                'capitulo' => $capitulo,
                'posicao' => $posicao,
                'subposicao' => $subposicao1,
                'item' => $subposicao2,
                'hierarquia' => [
                    'capitulo' => [
                        'codigo' => $capitulo,
                        'descricao' => $capitulos[$capitulo] ?? 'Capítulo não mapeado'
                    ],
                    'posicao' => $posicao,
                    'subposicao_1' => $subposicao1,
                    'subposicao_2' => $subposicao2
                ],
                'estrutura' => [
                    'nivel_1' => 'Capítulo: ' . $capitulo,
                    'nivel_2' => 'Posição: ' . $posicao,
                    'nivel_3' => 'Subposição 1: ' . $subposicao1,
                    'nivel_4' => 'Subposição 2: ' . $subposicao2
                ]
            ];
        }, 'analise_hierarquia_ncm');
    }
}
