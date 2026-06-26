<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/common.php';

use sabbajohn\FiscalCore\Facade\NFSeFacade;

$options = nfseJoinvilleNacionalParseOptions($argv);
if (($options['help'] ?? false) === true) {
    echo nfseJoinvilleNacionalUsage(basename((string) $argv[0])) . PHP_EOL;
    exit(0);
}

nfseMunicipalApplyEnvOverrides(nfseJoinvilleNacionalEnvOverrides(dirname(__DIR__, 2)));

$nfse = new NFSeFacade('joinville');
$providerInfo = $nfse->getProviderInfo();
$payload = nfseJoinvilleNacionalPayload($options);

if (($options['send'] ?? false) !== true) {
    $layout = $nfse->validarLayoutDps($payload, false);
    $xml = $nfse->gerarXmlDpsPreview($payload);

    echo json_encode([
        'mode' => 'preview',
        'provider' => $providerInfo->toArray(),
        'layout' => $layout->toArray(),
        'payload' => $payload,
        'xml_preview' => $xml->getData('xml'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$resultado = $nfse->emitirCompleto($payload);
echo $resultado->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

function nfseJoinvilleNacionalUsage(string $scriptName): string
{
    return <<<TXT
Uso:
  php {$scriptName} [--send] [--tomador-doc=12345678909] [--tomador-nome="TOMADOR TESTE"]
                     [--valor=10.00] [--competencia=2026-07-20] [--c-trib-nac=010101] [--aliquota=0.02]

Comportamento:
  Sem --send, o script executa somente preview seguro da DPS nacional.
  --send envia de verdade para a API nacional configurada para Joinville em homologacao.
TXT;
}

function nfseJoinvilleNacionalParseOptions(array $argv): array
{
    $options = [
        'send' => false,
        'tomador_doc' => '12345678909',
        'tomador_nome' => 'TOMADOR DE TESTE JOINVILLE',
        'valor' => '10.00',
        'competencia' => '2026-07-20',
        'c_trib_nac' => '010101',
        'aliquota' => '0.02',
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

        foreach ([
            '--tomador-doc=' => 'tomador_doc',
            '--tomador-nome=' => 'tomador_nome',
            '--valor=' => 'valor',
            '--competencia=' => 'competencia',
            '--c-trib-nac=' => 'c_trib_nac',
            '--aliquota=' => 'aliquota',
        ] as $prefix => $key) {
            if (str_starts_with($arg, $prefix)) {
                $options[$key] = substr($arg, strlen($prefix));
                continue 2;
            }
        }
    }

    return $options;
}

function nfseJoinvilleNacionalEnvOverrides(string $projectRoot): array
{
    return array_merge(
        nfseMunicipalBuildEnvOverrides('joinville', 'homologacao', $projectRoot),
        [
            'FISCAL_CNPJ' => nfseMunicipalRequiredEnvValue('FISCAL_CNPJ'),
            'FISCAL_RAZAO_SOCIAL' => nfseMunicipalRequiredEnvValue('FISCAL_RAZAO_SOCIAL'),
            'FISCAL_UF' => nfseMunicipalEnvValue('FISCAL_UF') ?? 'SC',
        ]
    );
}

function nfseJoinvilleNacionalPayload(array $options): array
{
    $cnpj = preg_replace('/\D+/', '', nfseMunicipalRequiredEnvValue('FISCAL_CNPJ')) ?? '';
    $inscricaoMunicipal = nfseMunicipalRequiredEnvValue('FISCAL_IM');
    $razaoSocial = nfseMunicipalRequiredEnvValue('FISCAL_RAZAO_SOCIAL');
    $competencia = (string) ($options['competencia'] ?? '2026-07-20');
    $numero = str_pad(date('His'), 15, '0', STR_PAD_LEFT);
    $serie = str_pad('1', 5, '0', STR_PAD_LEFT);
    $cTribNac = (string) ($options['c_trib_nac'] ?? '010101');

    return [
        'id' => 'DPS42091022' . $cnpj . $serie . $numero,
        'tpAmb' => '2',
        'dhEmi' => $competencia . 'T10:00:00-03:00',
        'verAplic' => 'fiscal-core-examples',
        'serie' => '1',
        'nDPS' => $numero,
        'dCompet' => $competencia,
        'tpEmit' => '1',
        'cLocEmi' => '4209102',
        'prestador' => [
            'cnpj' => $cnpj,
            'inscricaoMunicipal' => $inscricaoMunicipal,
            'razaoSocial' => $razaoSocial,
            'codigoMunicipio' => '4209102',
            'opSimpNac' => '1',
            'regEspTrib' => '0',
        ],
        'tomador' => [
            'documento' => (string) ($options['tomador_doc'] ?? '12345678909'),
            'razaoSocial' => (string) ($options['tomador_nome'] ?? 'TOMADOR DE TESTE JOINVILLE'),
        ],
        'servico' => [
            'codigo' => $cTribNac,
            'cTribNac' => $cTribNac,
            'cNBS' => '120018900',
            'descricao' => 'Servico de homologacao NFSe nacional para Joinville.',
            'cLocPrestacao' => '4209102',
            'codigo_municipio' => '4209102',
            'tribISSQN' => '1',
            'tpRetISSQN' => '1',
            'aliquota' => (float) ($options['aliquota'] ?? '0.02'),
        ],
        'valor_servicos' => (float) ($options['valor'] ?? '10.00'),
    ];
}
