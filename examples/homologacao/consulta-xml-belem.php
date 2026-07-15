<?php

declare(strict_types=1);

require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/common.php';

use sabbajohn\FiscalCore\Facade\FiscalFacade;

function belemXmlUsage(string $scriptName): string
{
    return <<<TXT
Uso:
  php {$scriptName} --chave=15014021241954766000192000000000109126034063508531 --protocolo=SEU_PROTOCOLO [--im=4007197]
  php {$scriptName} --chave=15014021241954766000192000000000109126034063508531 --rps-numero=SEU_RPS [--rps-serie=RPS] [--rps-tipo=1] [--im=4007197]
  php {$scriptName} --chave=15014021241954766000192000000000109126034063508531 --source-file=/caminho/retorno.xml

Observacoes:
  - Este script esta configurado para ambiente de producao.
  - IM padrao configurada para Faives: 4007197.
  - A consulta por chave usa o fluxo municipal de Belem derivando o NumeroNfse a partir da chave de 50 digitos.
  - Tambem continuam disponiveis os fluxos por protocolo/lote, RPS ou arquivo.
  - Quando a prefeitura retornar a NFSe/CompNfse no XML, este script extrai e salva o XML da nota.
TXT;
}

function belemXmlParseOptions(array $argv): array
{
    $options = [
        'im' => '4007197',
        'rps_serie' => 'RPS',
        'rps_tipo' => '1',
        'output' => __DIR__.'/../../tmp/belem-nfse-consulta-producao.xml',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;

            continue;
        }

        if (str_starts_with($arg, '--chave=')) {
            $options['chave'] = substr($arg, 8);

            continue;
        }

        if (str_starts_with($arg, '--im=')) {
            $options['im'] = substr($arg, 5);

            continue;
        }

        if (str_starts_with($arg, '--protocolo=')) {
            $options['protocolo'] = substr($arg, 12);

            continue;
        }

        if (str_starts_with($arg, '--rps-numero=')) {
            $options['rps_numero'] = substr($arg, 13);

            continue;
        }

        if (str_starts_with($arg, '--rps-serie=')) {
            $options['rps_serie'] = substr($arg, 12);

            continue;
        }

        if (str_starts_with($arg, '--rps-tipo=')) {
            $options['rps_tipo'] = substr($arg, 11);

            continue;
        }

        if (str_starts_with($arg, '--source-file=')) {
            $options['source_file'] = substr($arg, 14);

            continue;
        }

        if (str_starts_with($arg, '--output=')) {
            $options['output'] = substr($arg, 9);
        }
    }

    return $options;
}

function belemExtractNfseXml(string $rawXml): ?string
{
    $rawXml = trim($rawXml);
    if ($rawXml === '') {
        return null;
    }

    $dom = new DOMDocument;
    if (! @$dom->loadXML($rawXml)) {
        return null;
    }

    $xpath = new DOMXPath($dom);
    foreach (['CompNfse', 'Nfse', 'InfNfse'] as $nodeName) {
        $node = $xpath->query("//*[local-name()='{$nodeName}']")->item(0);
        if ($node instanceof DOMNode) {
            return $dom->saveXML($node) ?: null;
        }
    }

    return null;
}

function belemNormalizeChave(string $chave): string
{
    return preg_replace('/\D+/', '', $chave) ?? '';
}

function belemIsValidNfseChave(string $chave): bool
{
    return preg_match('/^\d{50}$/', $chave) === 1;
}

$options = belemXmlParseOptions($argv);
if (($options['help'] ?? false) === true) {
    echo belemXmlUsage(basename((string) $argv[0])).PHP_EOL;
    exit(0);
}

$chave = belemNormalizeChave((string) ($options['chave'] ?? '15014021241954766000192000000000109126034063508531'));
if (! belemIsValidNfseChave($chave)) {
    throw new RuntimeException('Informe uma chave de acesso valida de NFS-e com 50 digitos em --chave.');
}

$inscricaoMunicipal = preg_replace('/\D+/', '', (string) ($options['im'] ?? '')) ?? '';
if ($inscricaoMunicipal === '') {
    throw new RuntimeException('Informe uma inscricao municipal valida em --im.');
}

$projectRoot = dirname(__DIR__, 2);
$envOverrides = nfseMunicipalBuildEnvOverrides('belem', 'producao', $projectRoot, [
    'FISCAL_IM' => $inscricaoMunicipal,
]);
nfseMunicipalApplyEnvOverrides($envOverrides);

$fiscal = new FiscalFacade;
$nfse = $fiscal->nfse('belem');
$providerInfo = $nfse->getProviderInfo();

if (! $providerInfo->isSuccess()) {
    throw new RuntimeException('Erro ao inicializar o provider de Belem: '.$providerInfo->getError());
}

$rawXml = null;
$consulta = null;
$fonte = null;

if (trim((string) ($options['source_file'] ?? '')) !== '') {
    $sourceFile = trim((string) $options['source_file']);
    if (! is_file($sourceFile)) {
        throw new RuntimeException('Arquivo informado em --source-file nao encontrado.');
    }

    $rawXml = (string) file_get_contents($sourceFile);
    $fonte = 'arquivo';
} else {
    if (trim((string) ($options['protocolo'] ?? '')) !== '' || trim((string) ($options['rps_numero'] ?? '')) !== '') {
        $criterios = [];

        if (trim((string) ($options['protocolo'] ?? '')) !== '') {
            $criterios['protocolo'] = trim((string) $options['protocolo']);
            $fonte = 'lote';
        }

        if (trim((string) ($options['rps_numero'] ?? '')) !== '') {
            $criterios['rps'] = [
                'numero' => trim((string) $options['rps_numero']),
                'serie' => trim((string) ($options['rps_serie'] ?? 'RPS')) ?: 'RPS',
                'tipo' => trim((string) ($options['rps_tipo'] ?? '1')) ?: '1',
            ];
            $fonte = $fonte ?? 'rps';
        }

        $consulta = $nfse->consultarDisponibilidade($criterios);
        if (! $consulta->isSuccess()) {
            throw new RuntimeException('Falha na consulta de Belém: '.($consulta->getError() ?? 'erro desconhecido'));
        }

        $consultaData = $consulta->getData();
        $parsedResponse = is_array($consultaData['consulta']['parsed_response'] ?? null)
            ? $consultaData['consulta']['parsed_response']
            : [];

        $rawXml = trim((string) ($parsedResponse['raw_xml'] ?? ''));
    } else {
        $consulta = $nfse->consultar($chave);
        if (! $consulta->isSuccess()) {
            throw new RuntimeException('Falha na consulta de Belém por chave: '.($consulta->getError() ?? 'erro desconhecido'));
        }

        $fonte = 'chave';
        $documento = is_array($consulta->getData('documento') ?? null)
            ? $consulta->getData('documento')
            : [];
        $raw = is_array($consulta->getData('raw') ?? null)
            ? $consulta->getData('raw')
            : [];
        $rawXml = trim((string) ($documento['xml'] ?? $raw['response_xml'] ?? ''));
    }
}

$nfseXml = belemExtractNfseXml((string) $rawXml);
if ($nfseXml === null) {
    throw new RuntimeException(
        'A resposta nao contem o XML da NFS-e em CompNfse/Nfse/InfNfse. Em Belem isso normalmente indica que a nota ainda nao esta disponivel ou que a consulta precisa de outro identificador.'
    );
}

$output = trim((string) ($options['output'] ?? ''));
if ($output === '') {
    throw new RuntimeException('Caminho de saida invalido em --output.');
}

$outputDir = dirname($output);
if (! is_dir($outputDir) && ! mkdir($outputDir, 0777, true) && ! is_dir($outputDir)) {
    throw new RuntimeException('Nao foi possivel criar o diretorio de saida: '.$outputDir);
}

file_put_contents($output, $nfseXml);

echo 'Ambiente: producao'.PHP_EOL;
echo 'Municipio: belem'.PHP_EOL;
echo 'IM prestador: '.$inscricaoMunicipal.PHP_EOL;
echo 'Chave alvo: '.$chave.PHP_EOL;
echo 'Fonte da consulta: '.($fonte ?? 'desconhecida').PHP_EOL;
echo 'XML salvo em: '.$output.PHP_EOL;

if ($consulta !== null) {
    echo 'Resumo da consulta:'.PHP_EOL;
    echo $consulta->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
}
