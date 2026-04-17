<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/manaus_nacional_common.php';

use sabbajohn\FiscalCore\Support\FiscalResponse;

$projectRoot = dirname(__DIR__, 2);
$options = agroamManausParseOptions($argv);

if (($options['help'] ?? false) === true) {
    echo agroamManausUsage(basename((string) $argv[0])) . PHP_EOL;
    exit(0);
}

manausNacionalApplyEnvOverrides($projectRoot);

$nfse = manausNacionalFacade();
$providerInfo = $nfse->getProviderInfo();

if (($options['consultar_aliquota'] ?? false) === true) {
    $response = $nfse->consultarAliquotasMunicipio(
        '1302603',
        (string) $options['c_trib_nac'],
        (string) $options['competencia'],
        (bool) ($options['force_refresh'] ?? false)
    );
    agroamManausPrintResponse('consulta_aliquota', $providerInfo, $response);
    exit($response->isSuccess() ? 0 : 1);
}

if (($options['consultar_convenio'] ?? false) === true) {
    $response = $nfse->consultarConvenioMunicipio(
        '1302603',
        (bool) ($options['force_refresh'] ?? false)
    );
    agroamManausPrintResponse('consulta_convenio', $providerInfo, $response);
    exit($response->isSuccess() ? 0 : 1);
}

$payload = agroamManausBuildPayload($options);
$layout = $nfse->validarLayoutDps($payload, false);
$xmlPreview = $nfse->gerarXmlDpsPreview($payload);

if (($options['send'] ?? false) !== true) {
    echo json_encode([
        'mode' => 'preview',
        'reference' => agroamManausReferenceSnapshot(),
        'provider' => agroamManausResponseToArray($providerInfo),
        'layout' => agroamManausResponseToArray($layout),
        'payload' => $payload,
        'xml_preview' => $xmlPreview->getData('xml'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$result = $nfse->emitirCompleto($payload);
echo json_encode([
    'mode' => 'send',
    'reference' => agroamManausReferenceSnapshot(),
    'provider' => agroamManausResponseToArray($providerInfo),
    'layout' => agroamManausResponseToArray($layout),
    'payload' => $payload,
    'xml_preview' => $xmlPreview->getData('xml'),
    'result' => agroamManausResponseToArray($result),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($result->isSuccess() ? 0 : 1);

function agroamManausUsage(string $scriptName): string
{
    return <<<TXT
Uso:
  php {$scriptName} [--send] [--consultar-aliquota] [--consultar-convenio] [--force-refresh]
                     [--numero=62] [--serie=70000] [--competencia=2026-04-02]
                     [--c-trib-nac=140101] [--codigo-municipal=100]
                     [--c-nbs=12.00.18.900]
                     [--descricao="revisao"] [--valor=280.00] [--aliquota=5.00]
                     [--tomador-doc=12537098404] [--tomador-nome="ALMIR FERREIRA LIMA"]
                     [--op-simp-nac=2] [--reg-esp-trib=0]
                     [--dh-emi=2026-04-02T16:43:12-04:00]

Referencia usada neste script:
  municipio: Manaus/AM
  prestador: AGROAM - AGRICOLA AMAZONAS COMERCIAL LTDA
  IM: 7823201
  serie DPS: 70000
  cTribNac: 140101
  codigo municipal: 100
  aliquota: 5.00
  Simples Nacional: nao optante (opSimpNac=2)

Sem --send, o script gera preview completo com layout e XML.
TXT;
}

function agroamManausParseOptions(array $argv): array
{
    $options = [
        'send' => false,
        'consultar_aliquota' => false,
        'consultar_convenio' => false,
        'force_refresh' => false,
        'numero' => '73',
        'serie' => '70000',
        'competencia' => '2026-04-02',
        'dh_emi' => '2026-04-02T16:43:12-04:00',
        'c_trib_nac' => '140101',
        'codigo_municipal' => '100',
        'c_nbs' => '12.00.18.900',
        'descricao' => 'revisao',
        'valor' => '280.00',
        'aliquota' => '5.00',
        'tomador_doc' => '12537098404',
        'tomador_nome' => 'ALMIR FERREIRA LIMA',
        'tomador_logradouro' => '',
        'tomador_numero' => 'S/N',
        'tomador_bairro' => '',
        'tomador_municipio' => '',
        'tomador_uf' => '',
        'tomador_cep' => '',
        'op_simp_nac' => '2',
        'reg_esp_trib' => '0',
        'trib_issqn' => '1',
        'tp_ret_issqn' => '1',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--send') {
            $options['send'] = true;
            continue;
        }
        if ($arg === '--consultar-aliquota') {
            $options['consultar_aliquota'] = true;
            continue;
        }
        if ($arg === '--consultar-convenio') {
            $options['consultar_convenio'] = true;
            continue;
        }
        if ($arg === '--force-refresh') {
            $options['force_refresh'] = true;
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }

        foreach ([
            '--numero=' => 'numero',
            '--serie=' => 'serie',
            '--competencia=' => 'competencia',
            '--dh-emi=' => 'dh_emi',
            '--c-trib-nac=' => 'c_trib_nac',
            '--codigo-municipal=' => 'codigo_municipal',
            '--c-nbs=' => 'c_nbs',
            '--descricao=' => 'descricao',
            '--valor=' => 'valor',
            '--aliquota=' => 'aliquota',
            '--tomador-doc=' => 'tomador_doc',
            '--tomador-nome=' => 'tomador_nome',
            '--tomador-logradouro=' => 'tomador_logradouro',
            '--tomador-numero=' => 'tomador_numero',
            '--tomador-bairro=' => 'tomador_bairro',
            '--tomador-municipio=' => 'tomador_municipio',
            '--tomador-uf=' => 'tomador_uf',
            '--tomador-cep=' => 'tomador_cep',
            '--op-simp-nac=' => 'op_simp_nac',
            '--reg-esp-trib=' => 'reg_esp_trib',
            '--trib-issqn=' => 'trib_issqn',
            '--tp-ret-issqn=' => 'tp_ret_issqn',
        ] as $prefix => $key) {
            if (str_starts_with($arg, $prefix)) {
                $options[$key] = substr($arg, strlen($prefix));
                continue 2;
            }
        }
    }

    return $options;
}

function agroamManausBuildPayload(array $options): array
{
    $cnpj = preg_replace('/\D+/', '', nfseMunicipalRequiredEnvValue('FISCAL_CNPJ')) ?? '';
    $inscricaoMunicipal = nfseMunicipalRequiredEnvValue('FISCAL_IM');
    $razaoSocial = nfseMunicipalRequiredEnvValue('FISCAL_RAZAO_SOCIAL');

    $competencia = (string) $options['competencia'];
    $dhEmi = (string) $options['dh_emi'];
    $serie = preg_replace('/\D+/', '', (string) $options['serie']) ?? '70000';
    $numero = preg_replace('/\D+/', '', (string) $options['numero']) ?? '62';
    $seriePadded = str_pad(substr($serie, 0, 5), 5, '0', STR_PAD_LEFT);
    $numeroPadded = str_pad(substr($numero, 0, 15), 15, '0', STR_PAD_LEFT);
    $tpInsc = '2';
    $dpsId = 'DPS1302603' . $tpInsc . $cnpj . $seriePadded . $numeroPadded;

    $cTribNac = preg_replace('/\D+/', '', (string) $options['c_trib_nac']) ?? '140101';
    $codigoMunicipal = preg_replace('/\D+/', '', (string) $options['codigo_municipal']) ?? '100';
    $cNbs = preg_replace('/\D+/', '', (string) $options['c_nbs']) ?? '';
    $descricao = trim((string) $options['descricao']);
    $valor = (float) $options['valor'];
    $aliquota = (float) str_replace(',', '.', (string) $options['aliquota']);
    $opSimpNac = (string) $options['op_simp_nac'];
    $regEspTrib = (string) $options['reg_esp_trib'];

    $payload = [
        'id' => $dpsId,
        'tpAmb' => '2',
        'dhEmi' => $dhEmi,
        'verAplic' => 'agroam-ref',
        'serie' => $serie,
        'nDPS' => $numero,
        'dCompet' => $competencia,
        'tpEmit' => '1',
        'cLocEmi' => '1302603',
        'lote' => [
            'id' => sprintf('LOTE-1302603-%s-%s', $seriePadded, $numeroPadded),
            'numero' => $numero,
        ],
        'rps' => [
            'id' => sprintf('RPS-1302603-%s-%s-REF', $seriePadded, $numeroPadded),
            'numero' => $numero,
            'serie' => $serie,
            'tipo' => '1',
            'data_emissao' => substr($dhEmi, 0, 19),
            'status' => '1',
        ],
        'prestador' => [
            'cnpj' => $cnpj,
            'inscricaoMunicipal' => $inscricaoMunicipal,
            'razaoSocial' => $razaoSocial,
            'codigoMunicipio' => '1302603',
            'opSimpNac' => $opSimpNac,
            'regEspTrib' => $regEspTrib,
        ],
        'tomador' => [
            'documento' => preg_replace('/\D+/', '', (string) $options['tomador_doc']) ?? '',
            'razaoSocial' => (string) $options['tomador_nome'],
            'nome' => (string) $options['tomador_nome'],
            'endereco' => [
                'logradouro' => (string) $options['tomador_logradouro'],
                'numero' => (string) $options['tomador_numero'],
                'bairro' => (string) $options['tomador_bairro'],
                'municipio' => (string) $options['tomador_municipio'],
                'uf' => strtoupper((string) $options['tomador_uf']),
                'cep' => preg_replace('/\D+/', '', (string) $options['tomador_cep']) ?? '',
                'codigo_municipio' => '',
            ],
        ],
        'servico' => [
            'codigo' => $codigoMunicipal,
            'codigoMunicipal' => $codigoMunicipal,
            'cTribMun' => $codigoMunicipal,
            'cTribNac' => $cTribNac,
            'codigoServicoNacional' => $cTribNac,
            'descricao' => $descricao,
            'discriminacao' => $descricao,
            'cNBS' => $cNbs,
            'cLocPrestacao' => '1302603',
            'codigo_municipio' => '1302603',
            'tribISSQN' => (string) $options['trib_issqn'],
            'tpRetISSQN' => (string) $options['tp_ret_issqn'],
            'aliquota' => $aliquota,
        ],
        'valor_servicos' => $valor,
    ];

    if (manausNacionalCodigoExigeObra($cTribNac)) {
        $payload['servico']['obra'] = [
            'cObra' => 'OBRA-AGROAM-REF',
        ];
    }

    return $payload;
}

function agroamManausReferenceSnapshot(): array
{
    return [
        'municipio' => 'Manaus',
        'codigo_municipio' => '1302603',
        'prestador' => [
            'cnpj' => '01824852000166',
            'inscricao_municipal' => '7823201',
            'simples_nacional' => 'nao_optante',
        ],
        'dps' => [
            'serie' => '70000',
            'numero' => '62',
            'dh_emi' => '2026-04-02T16:43:12-04:00',
        ],
        'servico' => [
            'codigo_tributacao_nacional' => '14.01.01',
            'cTribNac' => '140101',
            'codigo_tributacao_municipal' => '100',
            'aliquota' => '5.00',
            'descricao' => 'revisao',
        ],
    ];
}

function agroamManausPrintResponse(string $mode, FiscalResponse $providerInfo, FiscalResponse $response): void
{
    echo json_encode([
        'mode' => $mode,
        'reference' => agroamManausReferenceSnapshot(),
        'provider' => agroamManausResponseToArray($providerInfo),
        'response' => agroamManausResponseToArray($response),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

function agroamManausResponseToArray(FiscalResponse $response): array
{
    return [
        'success' => $response->isSuccess(),
        'operation' => $response->getOperation(),
        'data' => $response->getData(),
        'error' => $response->getError(),
        'error_code' => $response->getErrorCode(),
        'metadata' => $response->getMetadata(),
    ];
}
