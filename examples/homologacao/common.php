<?php

declare(strict_types=1);

use freeline\FiscalCore\Support\NFSeMunicipalHomologationService;

function nfseMunicipalUsage(string $scriptName, string $municipio, string $ambiente = 'homologacao'): string
{
    $defaults = nfseMunicipalDefaultTomador($municipio);

    return <<<TXT
Uso:
  php {$scriptName} [--tomador-doc=00980556236] [--tomador-cep={$defaults['cep']}] [--send] [--debug-http]

Comportamento:
  --tomador-doc   CPF ou CNPJ do tomador
  --tomador-cep   CEP do tomador para preencher logradouro, bairro e municipio via consulta de CEP
  --send          Envia de verdade para {$ambiente}
  --debug-http    Liga log HTTP mascarado do provider

Defaults deste script:
  documento: {$defaults['documento']}
  nome: {$defaults['razao_social']}
  cep: {$defaults['cep']}

Sem --send, o script executa somente preview seguro.
Script preparado para {$municipio} em {$ambiente}.
TXT;
}

function nfseMunicipalDefaultTomador(string $municipio): array
{
    $documento = '00980556236';
    $nome = 'JOHNNATHAN VICTOR GONCALVES SABBA';

    return match (strtolower(trim($municipio))) {
        'belem' => [
            'documento' => $documento,
            'razao_social' => $nome,
            'cep' => '66065112',
            'endereco' => [
                'numero' => 'S/N',
            ],
        ],
        'joinville' => [
            'documento' => $documento,
            'razao_social' => $nome,
            'cep' => '89220650',
            'endereco' => [
                'numero' => 'S/N',
            ],
        ],
        default => [
            'documento' => $documento,
            'razao_social' => $nome,
            'cep' => '',
            'endereco' => [
                'numero' => 'S/N',
            ],
        ],
    };
}

function nfseMunicipalParseOptions(array $argv): array
{
    $options = [
        'send' => false,
        'debug_http' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--send') {
            $options['send'] = true;
            continue;
        }

        if ($arg === '--debug-http') {
            $options['debug_http'] = true;
            continue;
        }

        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }

        if (str_starts_with($arg, '--tomador-cnpj=')) {
            $options['tomador_documento'] = substr($arg, 15);
            continue;
        }

        if (str_starts_with($arg, '--tomador-doc=')) {
            $options['tomador_documento'] = substr($arg, 14);
            continue;
        }

        if (str_starts_with($arg, '--tomador-cep=')) {
            $options['tomador_cep'] = substr($arg, 14);
        }
    }

    return $options;
}

function nfseMunicipalPrintResult(array $result): void
{
    echo "Modo: " . strtoupper((string) $result['mode']) . PHP_EOL;
    echo "Municipio: " . $result['provider']['municipio'] . PHP_EOL;
    echo "Provider: " . $result['provider']['class'] . PHP_EOL;
    echo "WSDL: " . $result['provider']['wsdl'] . PHP_EOL;
    echo "Certificado: " . $result['certificate']['cnpj'] . ' - ' . $result['certificate']['razao_social'] . PHP_EOL;
    echo "Valido ate: " . $result['certificate']['valid_to'] . PHP_EOL;
    echo "Prestador IM: " . $result['prestador']['inscricaoMunicipal'] . PHP_EOL;
    echo "Tomador: " . $result['tomador']['razao_social'] . ' (' . $result['tomador']['documento'] . ')' . PHP_EOL;

    if (($result['warnings'] ?? []) !== []) {
        echo "Avisos:" . PHP_EOL;
        foreach ($result['warnings'] as $warning) {
            echo " - {$warning}" . PHP_EOL;
        }
    }

    if (($result['resolved_paths'] ?? []) !== []) {
        echo "Paths resolvidos:" . PHP_EOL;
        foreach ($result['resolved_paths'] as $key => $value) {
            echo " - {$key}: {$value}" . PHP_EOL;
        }
    }

    echo "Payload:\n";
    echo json_encode($result['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    echo "Request XML:\n";
    echo $result['request_xml'] . PHP_EOL;
    echo "Resposta parseada:\n";
    echo json_encode($result['parsed_response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

function nfseMunicipalRunScript(string $municipio, array $argv, array $envOverrides, array $scriptOptions = []): int
{
    $options = nfseMunicipalParseOptions($argv);
    $defaults = nfseMunicipalDefaultTomador($municipio);
    $ambiente = (string) ($scriptOptions['ambiente'] ?? ($envOverrides['FISCAL_ENVIRONMENT'] ?? 'homologacao'));
    if (($options['help'] ?? false) === true) {
        echo nfseMunicipalUsage(basename((string) $argv[0]), $municipio, $ambiente) . PHP_EOL;
        return 0;
    }

    $projectRoot = dirname(__DIR__, 2);
    $service = new NFSeMunicipalHomologationService($projectRoot);

    $simples = $_ENV['FISCAL_SIMPLES_NACIONAL'] ?? getenv('FISCAL_SIMPLES_NACIONAL');
    $serviceOptions = [
        'debug_http' => $options['debug_http'],
        'env_overrides' => $envOverrides,
        'tomador_defaults' => $defaults,
        'allow_production' => in_array(strtolower($ambiente), ['producao', 'production', 'prod'], true),
    ];

    if (is_array($scriptOptions['payload_overrides'] ?? null)) {
        $serviceOptions['payload_overrides'] = $scriptOptions['payload_overrides'];
    }

    if (is_array($scriptOptions['prestador_options'] ?? null)) {
        $serviceOptions['prestador_options'] = $scriptOptions['prestador_options'];
    }
    if (isset($options['tomador_cep']) && $options['tomador_cep'] !== '') {
        $serviceOptions['tomador_defaults']['cep'] = $options['tomador_cep'];
    }
    if ($simples !== false && $simples !== null && $simples !== '') {
        $serviceOptions['prestador_options'] = [
            'simples_nacional' => $simples,
        ];
    }

    $tomadorDocumento = (string) ($options['tomador_documento'] ?? $defaults['documento']);

    try {
        $result = $options['send']
            ? $service->send($municipio, $tomadorDocumento, $serviceOptions)
            : $service->preview($municipio, $tomadorDocumento, $serviceOptions);

        nfseMunicipalPrintResult($result);

        if (!$options['send']) {
            echo "Preview seguro concluido. Nenhuma requisicao real foi enviada." . PHP_EOL;
        }

        return 0;
    } catch (Throwable $e) {
        fwrite(STDERR, 'Erro: ' . $e->getMessage() . PHP_EOL);
        return 1;
    }
}

function nfseConsultaRunScript(string $municipio, array $argv, array $envOverrides, array $rps)
{
    // Implementation for consulta script
}
