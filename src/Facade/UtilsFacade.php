<?php

namespace sabbajohn\FiscalCore\Facade;

use sabbajohn\FiscalCore\Adapters\BrasilAPIAdapter;
use sabbajohn\FiscalCore\Support\FiscalResponse;
use sabbajohn\FiscalCore\Support\ResponseHandler;

/**
 * UtilsFacade - Interface para consultas públicas e utilitários
 *
 * Responsabilidades:
 * - Consultas CEP (ViaCEP, BrasilAPI)
 * - Consultas CNPJ/CPF (ReceitaWS, brazanation/documents)
 * - Lista de bancos, feriados, cidades
 * - Validação de documentos brasileiros
 * - APIs públicas diversas
 *
 * Separado do contexto fiscal para manter responsabilidades claras
 */
class UtilsFacade
{
    private BrasilAPIAdapter $brasilApi;

    private ResponseHandler $responseHandler;

    public function __construct(?BrasilAPIAdapter $brasilApi = null)
    {
        $this->brasilApi = $brasilApi ?? new BrasilAPIAdapter;
        $this->responseHandler = new ResponseHandler;
    }

    /**
     * Consulta CEP via ViaCEP ou BrasilAPI
     */
    public function consultarCEP(string $cep): FiscalResponse
    {
        return $this->responseHandler->execute(function () use ($cep) {
            $resultado = $this->brasilApi->consultarCEP($cep);

            if (! $resultado) {
                throw new \Exception('CEP não encontrado');
            }

            return [
                'cep' => $resultado['cep'] ?? $cep,
                'logradouro' => $resultado['street'] ?? $resultado['logradouro'] ?? '',
                'bairro' => $resultado['district'] ?? $resultado['bairro'] ?? '',
                'localidade' => $resultado['city'] ?? $resultado['localidade'] ?? '',
                'uf' => $resultado['state'] ?? $resultado['uf'] ?? '',
                'ibge' => $resultado['city_ibge'] ?? $resultado['ibge'] ?? '',
                'ddd' => $resultado['ddd'] ?? '',
            ];
        });
    }

    /** CEP v2 preserva localização aninhada; não fornece IBGE obrigatório. */
    public function consultarCEPv2(string $cep): FiscalResponse
    {
        return $this->executarComplementar('consultar_cep_v2', function () use ($cep): array {
            $dados = $this->brasilApi->consultarCEPv2($cep);
            foreach (['cep', 'city', 'state'] as $campo) {
                if (! is_string($dados[$campo] ?? null) || trim($dados[$campo]) === '') {
                    throw new \UnexpectedValueException('Resposta CEP v2 inválida.', 502);
                }
            }
            $location = is_array($dados['location'] ?? null) ? $dados['location'] : [];
            $coordinates = is_array($location['coordinates'] ?? null) ? $location['coordinates'] : [];

            return [
                'cep' => $dados['cep'],
                'logradouro' => is_string($dados['street'] ?? null) ? $dados['street'] : '',
                'bairro' => is_string($dados['neighborhood'] ?? null) ? $dados['neighborhood'] : '',
                'localidade' => $dados['city'],
                'uf' => $dados['state'],
                'servico' => is_string($dados['service'] ?? null) ? $dados['service'] : '',
                'fuso_horario' => is_string($dados['timezoneName'] ?? null) ? $dados['timezoneName'] : null,
                'localizacao' => [
                    'tipo' => is_string($location['type'] ?? null) ? $location['type'] : null,
                    'coordenadas' => [
                        'latitude' => is_numeric($coordinates['latitude'] ?? null) ? (string) $coordinates['latitude'] : null,
                        'longitude' => is_numeric($coordinates['longitude'] ?? null) ? (string) $coordinates['longitude'] : null,
                    ],
                ],
                'fonte' => 'brasil_api',
            ];
        });
    }

    public function listarTabelasReferenciaFipe(): FiscalResponse
    {
        return $this->executarComplementar('listar_tabelas_fipe', fn (): array => $this->validarListaComplementar(
            $this->brasilApi->listarTabelasReferenciaFipe(), ['codigo', 'mes']
        ));
    }

    public function consultarMarcasPorTipoVeiculo(string $tipoVeiculo, ?int $tabelaDeReferencia = null): FiscalResponse
    {
        return $this->executarComplementar('consultar_marcas_fipe', fn (): array => $this->validarListaComplementar(
            $this->brasilApi->consultarMarcasPorTipoVeiculo($tipoVeiculo, $tabelaDeReferencia), ['nome', 'valor']
        ));
    }

    /** Lista por ano/combustível; valor monetário preservado como string. */
    public function consultarPrecoFipe(string $codigoFipe, ?int $tabelaDeReferencia = null): FiscalResponse
    {
        return $this->executarComplementar('consultar_preco_fipe', function () use ($codigoFipe, $tabelaDeReferencia): array {
            $linhas = $this->validarListaComplementar($this->brasilApi->consultarPrecoFipe($codigoFipe, $tabelaDeReferencia),
                ['valor', 'marca', 'modelo', 'anoModelo', 'combustivel', 'codigoFipe', 'mesReferencia']);

            return array_map(function (array $linha) use ($codigoFipe, $tabelaDeReferencia): array {
                foreach (['valor', 'marca', 'modelo', 'combustivel', 'codigoFipe', 'mesReferencia'] as $campo) {
                    if (! is_string($linha[$campo])) {
                        throw new \UnexpectedValueException('Resposta FIPE inválida.', 502);
                    }
                }
                if ($linha['codigoFipe'] !== trim($codigoFipe) || ! ctype_digit((string) $linha['anoModelo'])) {
                    throw new \UnexpectedValueException('Resposta FIPE incompatível com a consulta.', 502);
                }

                return [
                    'codigo_fipe' => $linha['codigoFipe'],
                    'valor' => $linha['valor'],
                    'marca' => $linha['marca'],
                    'modelo' => $linha['modelo'],
                    'ano_modelo' => (int) $linha['anoModelo'],
                    'combustivel' => $linha['combustivel'],
                    'mes_referencia' => trim($linha['mesReferencia']),
                    'tipo_veiculo' => $linha['tipoVeiculo'] ?? null,
                    'sigla_combustivel' => $linha['siglaCombustivel'] ?? null,
                    'data_consulta' => $linha['dataConsulta'] ?? null,
                    'tabela_referencia' => $tabelaDeReferencia,
                    'fonte' => 'brasil_api',
                ];
            }, $linhas);
        });
    }

    private function validarListaComplementar(array $linhas, array $campos): array
    {
        if (! array_is_list($linhas) || $linhas === []) {
            throw new \UnexpectedValueException('Resposta de consulta inválida.', 502);
        }
        foreach ($linhas as $linha) {
            foreach ($campos as $campo) {
                if (! is_array($linha) || ! is_scalar($linha[$campo] ?? null) || trim((string) $linha[$campo]) === '') {
                    throw new \UnexpectedValueException('Resposta de consulta inválida.', 502);
                }
            }
        }

        return $linhas;
    }

    private function executarComplementar(string $operacao, callable $consulta): FiscalResponse
    {
        try {
            return FiscalResponse::success($consulta(), $operacao, ['provider' => 'brasil_api']);
        } catch (\Throwable $erro) {
            $status = (int) $erro->getCode();
            $codigo = match (true) {
                $erro instanceof \InvalidArgumentException, $status === 400 => 'INVALID_ARGUMENT',
                $status === 404 => 'CONSULTA_NAO_ENCONTRADA',
                $erro instanceof \UnexpectedValueException => 'RESPOSTA_INVALIDA',
                default => 'CONSULTA_INDISPONIVEL',
            };

            return FiscalResponse::error($erro->getMessage(), $codigo, $operacao, ['provider' => 'brasil_api']);
        }
    }

    /**
     * Consulta CNPJ via ReceitaWS
     */
    public function consultarCNPJ(string $cnpj): FiscalResponse
    {
        return $this->responseHandler->execute(function () use ($cnpj) {
            // Remove formatação
            $cnpj = preg_replace('/\D/', '', $cnpj);

            // Valida formato básico
            if (strlen($cnpj) !== 14) {
                throw new \InvalidArgumentException('CNPJ deve ter 14 dígitos');
            }

            $resultado = $this->brasilApi->consultarCNPJ($cnpj);

            if (! $resultado) {
                throw new \Exception('CNPJ não encontrado');
            }

            return $this->mapearConsultaCNPJ($cnpj, $resultado);
        });
    }

    /**
     * Valida CPF usando brazanation/documents
     */
    public function validarCPF(string $cpf): FiscalResponse
    {
        return $this->responseHandler->execute(function () use ($cpf) {
            $cpf = preg_replace('/\D/', '', $cpf);

            if (strlen($cpf) !== 11) {
                throw new \InvalidArgumentException('CPF deve ter 11 dígitos');
            }

            // Validação básica (todos iguais)
            if (preg_match('/(\d)\1{10}/', $cpf)) {
                throw new \InvalidArgumentException('CPF inválido');
            }

            // Cálculo dos dígitos verificadores
            if (! $this->validarDigitosCPF($cpf)) {
                throw new \InvalidArgumentException('CPF inválido');
            }

            return [
                'cpf' => $this->formatarCPF($cpf),
                'valido' => true,
            ];
        });
    }

    /**
     * Valida CNPJ usando brazanation/documents
     */
    public function validarCNPJ(string $cnpj): FiscalResponse
    {
        return $this->responseHandler->execute(function () use ($cnpj) {
            $cnpj = preg_replace('/\D/', '', $cnpj);

            if (strlen($cnpj) !== 14) {
                throw new \InvalidArgumentException('CNPJ deve ter 14 dígitos');
            }

            // Validação básica (todos iguais)
            if (preg_match('/(\d)\1{13}/', $cnpj)) {
                throw new \InvalidArgumentException('CNPJ inválido');
            }

            // Cálculo dos dígitos verificadores
            if (! $this->validarDigitosCNPJ($cnpj)) {
                throw new \InvalidArgumentException('CNPJ inválido');
            }

            return [
                'cnpj' => $this->formatarCNPJ($cnpj),
                'valido' => true,
            ];
        });
    }

    /**
     * Lista bancos brasileiros via BrasilAPI
     */
    public function listarBancos(): FiscalResponse
    {
        return $this->responseHandler->execute(function () {
            $bancos = $this->brasilApi->listarBancos();

            if (! $bancos) {
                throw new \Exception('Erro ao consultar lista de bancos');
            }

            return array_map(function ($banco) {
                $codigo = (string) ($banco['code'] ?? '');
                if (is_numeric($codigo)) {
                    $codigo = str_pad($codigo, 3, '0', STR_PAD_LEFT);
                }

                return [
                    'codigo' => $codigo,
                    'nome' => $banco['name'] ?? '',
                    'nome_completo' => $banco['fullName'] ?? '',
                    'ispb' => $banco['ispb'] ?? '',
                ];
            }, $bancos);
        });
    }

    /**
     * Consulta banco específico por código
     */
    public function consultarBanco(string $codigo): FiscalResponse
    {
        return $this->responseHandler->execute(function () use ($codigo) {
            $bancos = $this->listarBancos();

            if (! $bancos->isSuccess()) {
                throw new \Exception('Erro ao consultar bancos');
            }

            $codigoNormalizado = str_pad(preg_replace('/\D/', '', $codigo) ?? '', 3, '0', STR_PAD_LEFT);
            $banco = array_filter($bancos->getData(), function ($b) use ($codigoNormalizado) {
                return $b['codigo'] === $codigoNormalizado;
            });

            if (empty($banco)) {
                throw new \Exception("Banco {$codigo} não encontrado");
            }

            return array_values($banco)[0];
        });
    }

    /**
     * Lista feriados nacionais do ano
     */
    public function listarFeriados(?int $ano = null): FiscalResponse
    {
        return $this->responseHandler->execute(function () use ($ano) {
            $ano = $ano ?? date('Y');
            $feriados = $this->brasilApi->consultarFeriados($ano);

            if (! $feriados) {
                throw new \Exception("Erro ao consultar feriados de {$ano}");
            }

            return array_map(function ($feriado) {
                return [
                    'data' => $feriado['date'] ?? '',
                    'nome' => $feriado['name'] ?? '',
                    'tipo' => $feriado['type'] ?? 'nacional',
                ];
            }, $feriados);
        });
    }

    /**
     * Lista municípios por UF
     */
    public function listarMunicipios(string $uf): FiscalResponse
    {
        return $this->responseHandler->execute(function () use ($uf) {
            $uf = strtoupper($uf);
            $municipios = $this->brasilApi->consultarMunicipios($uf);

            if (! $municipios) {
                throw new \Exception("Erro ao consultar municípios de {$uf}");
            }

            return array_map(function ($municipio) {
                return [
                    'codigo_ibge' => $municipio['city_ibge'] ?? $municipio['code'] ?? '',
                    'nome' => $municipio['name'] ?? '',
                    'uf' => $municipio['state'] ?? '',
                ];
            }, $municipios);
        });
    }

    /**
     * Consulta dados de DDD
     */
    public function consultarDDD(string $ddd): FiscalResponse
    {
        return $this->responseHandler->execute(function () use ($ddd) {
            $resultado = $this->brasilApi->consultarDDD($ddd);

            if (! $resultado) {
                throw new \Exception("DDD {$ddd} não encontrado");
            }

            return [
                'ddd' => $ddd,
                'estado' => $resultado['state'] ?? '',
                'cidades' => $resultado['cities'] ?? [],
            ];
        });
    }

    /**
     * Verifica status das APIs públicas
     */
    public function verificarStatusAPIs(): FiscalResponse
    {
        return $this->responseHandler->execute(function () {
            $status = [];

            // Testa BrasilAPI
            try {
                $this->brasilApi->consultarCEP('01310100');
                $status['brasilapi'] = ['disponivel' => true, 'status' => 'Online'];
            } catch (\Exception $e) {
                $status['brasilapi'] = ['disponivel' => false, 'status' => $e->getMessage()];
            }

            // Testa ViaCEP
            try {
                $viacep = file_get_contents('https://viacep.com.br/ws/01310100/json/', false,
                    stream_context_create(['http' => ['timeout' => 5]]));
                $status['viacep'] = ['disponivel' => ! empty($viacep), 'status' => 'Online'];
            } catch (\Exception $e) {
                $status['viacep'] = ['disponivel' => false, 'status' => $e->getMessage()];
            }

            return [
                'apis' => $status,
                'timestamp' => date('Y-m-d H:i:s'),
                'total_disponivel' => count(array_filter($status, fn ($s) => $s['disponivel'])),
            ];
        });
    }

    // Métodos auxiliares privados

    private function formatarCPF(string $cpf): string
    {
        return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf);
    }

    private function formatarCNPJ(string $cnpj): string
    {
        return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $cnpj);
    }

    private function mapearConsultaCNPJ(string $cnpj, array $resultado): array
    {
        $telefone = trim(
            implode(' / ', array_filter([
                $resultado['ddd_telefone_1'] ?? null,
                $resultado['ddd_telefone_2'] ?? null,
                $resultado['ddd_fax'] ?? null,
                $resultado['phone'] ?? null,
                $resultado['telefone'] ?? null,
            ], fn ($valor) => $valor !== null && $valor !== ''))
        );

        $endereco = [
            'logradouro' => $resultado['address']['street'] ?? $resultado['logradouro'] ?? '',
            'numero' => $resultado['address']['number'] ?? $resultado['numero'] ?? '',
            'complemento' => $resultado['address']['details'] ?? $resultado['complemento'] ?? '',
            'bairro' => $resultado['address']['district'] ?? $resultado['bairro'] ?? '',
            'municipio' => $resultado['address']['city'] ?? $resultado['municipio'] ?? '',
            'codigo_municipio' => $resultado['codigo_municipio'] ?? '',
            'codigo_municipio_ibge' => $resultado['codigo_municipio_ibge'] ?? '',
            'uf' => $resultado['address']['state'] ?? $resultado['uf'] ?? '',
            'cep' => $resultado['address']['zip'] ?? $resultado['cep'] ?? '',
            'pais' => $resultado['pais'] ?? '',
            'codigo_pais' => $resultado['codigo_pais'] ?? '',
            'nome_cidade_no_exterior' => $resultado['nome_cidade_no_exterior'] ?? '',
            'tipo_logradouro' => $resultado['descricao_tipo_de_logradouro'] ?? '',
        ];

        return [
            'cnpj' => $this->formatarCNPJ($cnpj),
            'cnpj_limpo' => $cnpj,
            'razao_social' => $resultado['razao_social'] ?? $resultado['razaoSocial'] ?? $resultado['company']['name'] ?? $resultado['nome'] ?? '',
            'nome_fantasia' => $resultado['nome_fantasia'] ?? $resultado['nomeFantasia'] ?? $resultado['alias'] ?? $resultado['fantasia'] ?? '',
            'situacao' => $resultado['descricao_situacao_cadastral'] ?? $resultado['status'] ?? $resultado['situacao'] ?? '',
            'situacao_cadastral' => $resultado['situacao_cadastral'] ?? '',
            'descricao_situacao_cadastral' => $resultado['descricao_situacao_cadastral'] ?? '',
            'motivo_situacao_cadastral' => $resultado['motivo_situacao_cadastral'] ?? '',
            'descricao_motivo_situacao_cadastral' => $resultado['descricao_motivo_situacao_cadastral'] ?? '',
            'data_situacao_cadastral' => $resultado['data_situacao_cadastral'] ?? '',
            'situacao_especial' => $resultado['situacao_especial'] ?? '',
            'data_situacao_especial' => $resultado['data_situacao_especial'] ?? '',
            'atividade_principal' => $resultado['cnae_fiscal_descricao'] ?? $resultado['cnaePrincipal'] ?? $resultado['primary_activity'] ?? $resultado['atividade_principal'] ?? '',
            'cnae_fiscal' => $resultado['cnae_fiscal'] ?? '',
            'cnae_fiscal_descricao' => $resultado['cnae_fiscal_descricao'] ?? '',
            'cnaes_secundarios' => $resultado['cnaes_secundarios'] ?? [],
            'natureza_juridica' => $resultado['natureza_juridica'] ?? '',
            'codigo_natureza_juridica' => $resultado['codigo_natureza_juridica'] ?? '',
            'porte' => $resultado['porte'] ?? '',
            'codigo_porte' => $resultado['codigo_porte'] ?? '',
            'capital_social' => $resultado['capital_social'] ?? '',
            'telefone' => $telefone,
            'ddd_telefone_1' => $resultado['ddd_telefone_1'] ?? '',
            'ddd_telefone_2' => $resultado['ddd_telefone_2'] ?? '',
            'ddd_fax' => $resultado['ddd_fax'] ?? '',
            'email' => $resultado['email'] ?? '',
            'qsa' => $resultado['qsa'] ?? [],
            'regime_tributario' => $resultado['regime_tributario'] ?? [],
            'opcao_pelo_simples' => $resultado['opcao_pelo_simples'] ?? false,
            'data_opcao_pelo_simples' => $resultado['data_opcao_pelo_simples'] ?? null,
            'data_exclusao_do_simples' => $resultado['data_exclusao_do_simples'] ?? null,
            'opcao_pelo_mei' => $resultado['opcao_pelo_mei'] ?? false,
            'data_opcao_pelo_mei' => $resultado['data_opcao_pelo_mei'] ?? null,
            'data_exclusao_do_mei' => $resultado['data_exclusao_do_mei'] ?? null,
            'data_inicio_atividade' => $resultado['data_inicio_atividade'] ?? '',
            'identificador_matriz_filial' => $resultado['identificador_matriz_filial'] ?? '',
            'descricao_identificador_matriz_filial' => $resultado['descricao_identificador_matriz_filial'] ?? '',
            'qualificacao_do_responsavel' => $resultado['qualificacao_do_responsavel'] ?? '',
            'ente_federativo_responsavel' => $resultado['ente_federativo_responsavel'] ?? '',
            'endereco' => $endereco,
            'logradouro' => $endereco['logradouro'],
            'numero' => $endereco['numero'],
            'complemento' => $endereco['complemento'],
            'bairro' => $endereco['bairro'],
            'municipio' => $endereco['municipio'],
            'uf' => $endereco['uf'],
            'cep' => $endereco['cep'],
            'pais' => $endereco['pais'],
            'codigo_pais' => $endereco['codigo_pais'],
            'nome_cidade_no_exterior' => $endereco['nome_cidade_no_exterior'],
        ];
    }

    private function validarDigitosCPF(string $cpf): bool
    {
        // Primeiro dígito
        $soma = 0;
        for ($i = 0; $i < 9; $i++) {
            $soma += $cpf[$i] * (10 - $i);
        }
        $digito1 = $soma % 11 < 2 ? 0 : 11 - ($soma % 11);

        // Segundo dígito
        $soma = 0;
        for ($i = 0; $i < 10; $i++) {
            $soma += $cpf[$i] * (11 - $i);
        }
        $digito2 = $soma % 11 < 2 ? 0 : 11 - ($soma % 11);

        return $cpf[9] == $digito1 && $cpf[10] == $digito2;
    }

    private function validarDigitosCNPJ(string $cnpj): bool
    {
        // Primeiro dígito
        $pesos = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $soma = 0;
        for ($i = 0; $i < 12; $i++) {
            $soma += $cnpj[$i] * $pesos[$i];
        }
        $digito1 = $soma % 11 < 2 ? 0 : 11 - ($soma % 11);

        // Segundo dígito
        $pesos = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $soma = 0;
        for ($i = 0; $i < 13; $i++) {
            $soma += $cnpj[$i] * $pesos[$i];
        }
        $digito2 = $soma % 11 < 2 ? 0 : 11 - ($soma % 11);

        return $cnpj[12] == $digito1 && $cnpj[13] == $digito2;
    }
}
