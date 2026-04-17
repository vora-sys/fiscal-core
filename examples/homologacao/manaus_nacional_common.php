<?php

declare(strict_types=1);

use sabbajohn\FiscalCore\Facade\FiscalFacade;

function manausNacionalApplyEnvOverrides(string $projectRoot): void
{
    $envOverrides = array_merge(
        nfseMunicipalBuildEnvOverrides('manaus', 'homologacao', $projectRoot),
        [
            'FISCAL_CNPJ' => nfseMunicipalRequiredEnvValue('FISCAL_CNPJ'),
            'FISCAL_RAZAO_SOCIAL' => nfseMunicipalRequiredEnvValue('FISCAL_RAZAO_SOCIAL'),
            'FISCAL_UF' => nfseMunicipalEnvValue('FISCAL_UF') ?? 'AM',
        ]
    );

    nfseMunicipalApplyEnvOverrides($envOverrides);
}

function manausNacionalUsage(string $scriptName): string
{
    return <<<TXT
Uso:
  php {$scriptName} [--send] [--tomador-doc=12345678909] [--tomador-nome="TOMADOR TESTE"] [--valor=10.00]
                     [--competencia=2026-04-03] [--c-trib-nac=010101] [--aliquota=0.02] [--obra-codigo=OBRA123]
  php {$scriptName} --listar-codigos [--buscar-codigo=informatica] [--codigo-prefixo=01] [--limite=20]
  php {$scriptName} --consultar-chave=CHAVE
  php {$scriptName} --consultar-rps-numero=1 [--consultar-rps-serie=1] [--consultar-rps-tipo=1]
  php {$scriptName} --consultar-lote=PROTOCOLO
  php {$scriptName} --baixar-xml=CHAVE
  php {$scriptName} --baixar-danfse=CHAVE
  php {$scriptName} --cancelar-chave=CHAVE --motivo="Motivo do cancelamento" [--protocolo=PROTOCOLO]
  php {$scriptName} --aliquotas [--c-trib-nac=010101] [--competencia=2026-04-03]
  php {$scriptName} --convenio

Comportamento:
  Sem flags de operacao, o script entra em modo de emissao
  --send            Envia de verdade para a API nacional em homologacao
  --tomador-doc     CPF ou CNPJ do tomador
  --tomador-nome    Razao social ou nome do tomador
  --valor           Valor do servico
  --competencia     Data no formato YYYY-MM-DD
  --c-trib-nac      Codigo tributario nacional com 6 digitos
  --aliquota        Aliquota ISS em formato decimal, ex.: 0.02
  --obra-codigo     Identificador da obra para codigos que exigem grupo serv.obra
  --consultar-chave Consulta NFSe pela chave
  --consultar-rps-* Consulta por RPS
  --consultar-lote  Consulta status do lote
  --baixar-xml      Baixa XML da NFSe
  --baixar-danfse   Baixa DANFSe
  --cancelar-chave  Cancela NFSe pela chave
  --listar-codigos  Lista codigos cTribNac da tabela nacional oficial usada pelo projeto
  --buscar-codigo   Filtra a lista por descricao ou codigo
  --codigo-prefixo  Filtra a lista por prefixo numerico do codigo
  --limite          Limita quantidade de registros retornados (0 = sem limite)
  --aliquotas       Consulta aliquotas nacionais do municipio
  --convenio        Consulta convenio do municipio no catalogo

Sem --send, o modo de emissao executa somente preview seguro do XML DPS.
TXT;
}

function manausNacionalOperationsUsage(string $scriptName): string
{
    return manausNacionalUsage($scriptName);
}

function manausNacionalParseOptions(array $argv): array
{
    $options = [
        'send' => false,
        'tomador_doc' => '12345678909',
        'tomador_nome' => 'TOMADOR DE TESTE MANAUS',
        'valor' => '10.00',
        'competencia' => date('Y-m-d'),
        'c_trib_nac' => '010101',
        'aliquota' => '0.02',
        'obra_codigo' => 'OBRA-HOMOLOG-001',
        'consultar_rps_serie' => '1',
        'consultar_rps_tipo' => '1',
        'motivo' => 'Cancelamento de teste em homologacao',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--send') {
            $options['send'] = true;
            continue;
        }

        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }

        if ($arg === '--aliquotas') {
            $options['aliquotas'] = true;
            continue;
        }

        if ($arg === '--listar-codigos') {
            $options['listar_codigos'] = true;
            continue;
        }

        if ($arg === '--convenio') {
            $options['convenio'] = true;
            continue;
        }

        foreach ([
            '--tomador-doc=' => 'tomador_doc',
            '--tomador-nome=' => 'tomador_nome',
            '--valor=' => 'valor',
            '--competencia=' => 'competencia',
            '--c-trib-nac=' => 'c_trib_nac',
            '--aliquota=' => 'aliquota',
            '--obra-codigo=' => 'obra_codigo',
            '--buscar-codigo=' => 'buscar_codigo',
            '--codigo-prefixo=' => 'codigo_prefixo',
            '--limite=' => 'limite',
            '--consultar-chave=' => 'consultar_chave',
            '--consultar-rps-numero=' => 'consultar_rps_numero',
            '--consultar-rps-serie=' => 'consultar_rps_serie',
            '--consultar-rps-tipo=' => 'consultar_rps_tipo',
            '--consultar-lote=' => 'consultar_lote',
            '--baixar-xml=' => 'baixar_xml',
            '--baixar-danfse=' => 'baixar_danfse',
            '--cancelar-chave=' => 'cancelar_chave',
            '--motivo=' => 'motivo',
            '--protocolo=' => 'protocolo',
        ] as $prefix => $key) {
            if (str_starts_with($arg, $prefix)) {
                $options[$key] = substr($arg, strlen($prefix));
                continue 2;
            }
        }
    }

    return $options;
}

function manausNacionalBuildPayload(array $options): array
{
    $cnpj = preg_replace('/\D+/', '', nfseMunicipalRequiredEnvValue('FISCAL_CNPJ')) ?? '';
    $inscricaoMunicipal = nfseMunicipalRequiredEnvValue('FISCAL_IM');
    $razaoSocial = nfseMunicipalRequiredEnvValue('FISCAL_RAZAO_SOCIAL');
    $competencia = (string) ($options['competencia'] ?? date('Y-m-d'));
    $dhEmi = $competencia . 'T10:00:00-04:00';
    $serie = str_pad('1', 5, '0', STR_PAD_LEFT);
    $numero = str_pad(date('His'), 15, '0', STR_PAD_LEFT);
    $dpsId = 'DPS13026032' . $cnpj . $serie . $numero;
    $nbs="120018900";

    $cTribNac = (string) ($options['c_trib_nac'] ?? '140101');
    $payload = [
        'id' => $dpsId,
        'tpAmb' => '2',
        'dhEmi' => $dhEmi,
        'verAplic' => 'fiscal-core-examples',
        'serie' => '1',
        'nDPS' => $numero,
        'dCompet' => $competencia,
        'tpEmit' => '1',
        'cLocEmi' => '1302603',
        'prestador' => [
            'cnpj' => $cnpj,
            'inscricaoMunicipal' => $inscricaoMunicipal,
            'razaoSocial' => $razaoSocial,
            'codigoMunicipio' => '1302603',
            'opSimpNac' => '1',
            'regEspTrib' => '0',
        ],
        'tomador' => [
            'documento' => (string) ($options['tomador_doc'] ?? '12345678909'),
            'razaoSocial' => (string) ($options['tomador_nome'] ?? 'TOMADOR DE TESTE MANAUS'),
        ],
        'servico' => [
            'codigo' => $nbs,
            'cTribNac' => $cTribNac,
            'descricao' => 'Servico de homologacao NFSe nacional para Manaus.',
            'cLocPrestacao' => '1302603',
            'codigo_municipio' => '1302603',
            'tribISSQN' => '1',
            'tpRetISSQN' => '1',
            'aliquota' => (float) ($options['aliquota'] ?? '0.02'),
        ],
        'valor_servicos' => (float) ($options['valor'] ?? '10.00'),
    ];

    if (manausNacionalCodigoExigeObra($cTribNac)) {
        $payload['servico']['obra'] = [
            'cObra' => (string) ($options['obra_codigo'] ?? 'OBRA-HOMOLOG-001'),
        ];
    }

    return $payload;
}

function manausNacionalHasOperationFlags(array $options): bool
{
    foreach ([
        'listar_codigos',
        'consultar_chave',
        'consultar_rps_numero',
        'consultar_lote',
        'baixar_xml',
        'baixar_danfse',
        'cancelar_chave',
        'aliquotas',
        'convenio',
    ] as $key) {
        if (!empty($options[$key])) {
            return true;
        }
    }

    return false;
}

function manausNacionalListarCodigos(array $options, ?string $projectRoot = null): array
{
    $projectRoot ??= dirname(__DIR__, 2);
    $catalogPath = $projectRoot . '/docs_nfse_nacional/MUN.INCID_INFO.SERV.-Table 1.csv';
    if (!is_file($catalogPath)) {
        throw new RuntimeException('Tabela nacional de servicos nao encontrada: ' . $catalogPath);
    }

    $search = manausNacionalNormalizeSearchText((string) ($options['buscar_codigo'] ?? ''));
    $prefix = preg_replace('/\D+/', '', (string) ($options['codigo_prefixo'] ?? '')) ?? '';
    $limit = (int) ($options['limite'] ?? 100);

    $handle = fopen($catalogPath, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Nao foi possivel abrir a tabela nacional de servicos.');
    }

    $matches = [];
    $total = 0;
    try {
        while (($row = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
            $codigo = trim((string) ($row[0] ?? ''));
            if (preg_match('/^\d{6}$/', $codigo) !== 1) {
                continue;
            }

            $descricao = trim((string) ($row[1] ?? ''));
            $registro = [
                'codigo' => $codigo,
                'descricao' => $descricao,
                'incidencia' => [
                    'ep' => (($row[2] ?? '') === 'X'),
                    'lp' => (($row[3] ?? '') === 'X'),
                    'et' => (($row[4] ?? '') === 'X'),
                    'edemit_importacao' => (($row[5] ?? '') === 'X'),
                ],
                'grupos' => [
                    'obra_ou_atv_evento' => (($row[6] ?? '') === 'X'),
                    'info_complementar' => (($row[7] ?? '') === 'X'),
                ],
            ];

            if ($prefix !== '' && !str_starts_with($codigo, $prefix)) {
                continue;
            }

            if ($search !== '') {
                $haystack = manausNacionalNormalizeSearchText($codigo . ' ' . $descricao);
                if (!str_contains($haystack, $search)) {
                    continue;
                }
            }

            $total++;
            if ($limit === 0 || count($matches) < $limit) {
                $matches[] = $registro;
            }
        }
    } finally {
        fclose($handle);
    }

    return [
        'municipio' => 'manaus',
        'codigo_municipio' => '1302603',
        'source' => 'docs_nfse_nacional/MUN.INCID_INFO.SERV.-Table 1.csv',
        'filtros' => [
            'buscar_codigo' => $search !== '' ? $search : null,
            'codigo_prefixo' => $prefix !== '' ? $prefix : null,
            'limite' => $limit,
        ],
        'metadata' => [
            'observacao' => 'Esta e a lista nacional oficial de codigos cTribNac. A API de parametrizacao de Manaus nao expoe listagem publica de codigos administrados; a administracao municipal precisa ser validada por consulta/emitir por codigo.',
            'total_encontrado' => $total,
            'total_retornado' => count($matches),
        ],
        'codigos' => $matches,
    ];
}

function manausNacionalNormalizeSearchText(string $value): string
{
    $normalized = trim(mb_strtolower($value));
    if ($normalized === '') {
        return '';
    }

    $normalized = strtr($normalized, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c',
    ]);

    $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

    return trim($normalized);
}

function manausNacionalCodigoExigeObra(string $cTribNac): bool
{
    return in_array($cTribNac, [
        '070201',
        '070202',
        '070401',
        '070501',
        '070502',
        '070601',
        '070602',
        '070701',
        '070801',
        '071701',
        '071901',
        '141403',
        '141404',
    ], true);
}

function manausNacionalFacade(): \sabbajohn\FiscalCore\Facade\NFSeFacade
{
    $fiscal = new FiscalFacade();
    return $fiscal->nfse('manaus');
}
