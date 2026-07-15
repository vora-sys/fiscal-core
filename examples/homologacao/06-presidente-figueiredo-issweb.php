<?php

declare(strict_types=1);

require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/common.php';

use sabbajohn\FiscalCore\Facade\FiscalFacade;
use sabbajohn\FiscalCore\Providers\NFSe\Municipal\IsswebProvider;
use sabbajohn\FiscalCore\Support\NFSeSoapTransportInterface;
use sabbajohn\FiscalCore\Support\ProviderRegistry;

function presidenteFigueiredoIsswebUsage(string $scriptName): string
{
    return <<<TXT
Uso:
  php {$scriptName} [--send] [--tomador-doc=12345678909] [--tomador-nome="TOMADOR TESTE"] [--numero=123]

Comportamento:
  --send          Envia para o endpoint configurado de homologacao
  --tomador-doc   CPF ou CNPJ do tomador
  --tomador-nome  Nome ou razao social do tomador
  --numero        Numero sequencial do RPS/ID

Sem --send, executa preview local com transporte fake e mostra o XML gerado.
TXT;
}

function presidenteFigueiredoIsswebOptions(array $argv): array
{
    $options = [
        'send' => false,
        'tomador_doc' => '12345678909',
        'tomador_nome' => 'TOMADOR DE TESTE PRESIDENTE FIGUEIREDO',
        'numero' => date('His'),
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
        if (str_starts_with($arg, '--tomador-doc=')) {
            $options['tomador_doc'] = substr($arg, 14);

            continue;
        }
        if (str_starts_with($arg, '--tomador-nome=')) {
            $options['tomador_nome'] = substr($arg, 15);

            continue;
        }
        if (str_starts_with($arg, '--numero=')) {
            $options['numero'] = substr($arg, 9);
        }
    }

    return $options;
}

function presidenteFigueiredoIsswebPayload(array $options): array
{
    return [
        'id' => (string) $options['numero'],
        'rps' => [
            'numero' => (string) $options['numero'],
        ],
        'prestador' => [
            'cnpj' => nfseMunicipalRequiredEnvValue('FISCAL_CNPJ'),
            'inscricaoMunicipal' => nfseMunicipalRequiredEnvValue('FISCAL_IM'),
        ],
        'tomador' => [
            'documento' => (string) $options['tomador_doc'],
            'razao_social' => (string) $options['tomador_nome'],
            'email' => 'homologacao@example.com',
            'endereco' => [
                'uf' => 'AM',
                'codigo_municipio' => '1303536',
                'logradouro' => 'Avenida Amazonino Mendes',
                'numero' => '100',
                'bairro' => 'Centro',
                'cep' => '69735-000',
            ],
        ],
        'servico' => [
            'codigo' => '101',
            'descricao' => 'Servico de homologacao ISSWEB para Presidente Figueiredo.',
            'discriminacao' => 'Servico de homologacao ISSWEB para Presidente Figueiredo.',
            'tipo_documento' => '001',
            'local_prestacao' => [
                'tipo' => '1',
                'uf' => 'AM',
                'codigo_municipio' => '1303536',
                'cep' => '69735-000',
            ],
        ],
        'valor_servicos' => 10.00,
    ];
}

$options = presidenteFigueiredoIsswebOptions($argv);
if (($options['help'] ?? false) === true) {
    echo presidenteFigueiredoIsswebUsage(basename((string) $argv[0])).PHP_EOL;
    exit(0);
}

$projectRoot = dirname(__DIR__, 2);
$envOverrides = nfseMunicipalBuildEnvOverrides('presidente-figueiredo', 'homologacao', $projectRoot, [
    'FISCAL_CNPJ' => nfseMunicipalRequiredEnvValue('FISCAL_CNPJ'),
    'FISCAL_RAZAO_SOCIAL' => nfseMunicipalRequiredEnvValue('FISCAL_RAZAO_SOCIAL'),
    'FISCAL_UF' => nfseMunicipalEnvValue('FISCAL_UF') ?? 'AM',
]);
nfseMunicipalApplyEnvOverrides($envOverrides);

$payload = presidenteFigueiredoIsswebPayload($options);

if (($options['send'] ?? false) !== true) {
    $config = ProviderRegistry::getInstance()->getConfigForMunicipio('presidente-figueiredo');
    $config['prestador'] = $payload['prestador'];
    $config['auth']['chave'] = nfseMunicipalRequiredEnvValue('NFSE_ISSWEB_CHAVE');
    $config['soap_transport'] = new class implements NFSeSoapTransportInterface
    {
        public function send(string $endpoint, string $envelope, array $options = []): array
        {
            return [
                'request_xml' => $envelope,
                'response_xml' => '<?xml version="1.0" encoding="UTF-8"?><Retorno><NotaFiscal><ID>1</ID><NumeroNF>9999</NumeroNF><ChaveValidacao>AB12-C3456</ChaveValidacao><Lote>1</Lote></NotaFiscal></Retorno>',
                'status_code' => 200,
                'headers' => [],
            ];
        }
    };

    $provider = new IsswebProvider($config);
    $provider->emitir($payload);

    echo json_encode([
        'mode' => 'preview',
        'payload' => $payload,
        'artifacts' => $provider->getLastOperationArtifacts(),
        'parsed_response' => $provider->getLastResponseData(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

$fiscal = new FiscalFacade;
$nfse = $fiscal->nfse('presidente-figueiredo');
$resultado = $nfse->emitirCompleto($payload);

echo $resultado->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
