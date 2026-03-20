<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use freeline\FiscalCore\Facade\FiscalFacade;

$fiscal = new FiscalFacade();
$nfse = $fiscal->nfse('belem');

$payload = [
    'id' => 'RPS-BELEM-' . date('YmdHis') . '-1',
    'lote' => [
        'id' => 'LOTE-BELEM-' . date('YmdHis'),
        'numero' => date('His'),
    ],
    'rps' => [
        'id' => 'RPS-BELEM-' . date('YmdHis') . '-RAW',
        'numero' => date('His'),
        'serie' => 'RPS',
        'tipo' => '1',
        'data_emissao' => date('Y-m-d'),
        'status' => '1',
    ],
    'competencia' => date('Y-m-d'),
    'prestador' => [
        'mei' => false,
        'simples_nacional' => true,
        'regime_tributario' => 'simples nacional',
        'incentivo_fiscal' => false,
    ],
    'tomador' => [
        'documento' => '00980556236',
        'razao_social' => 'JOHNNATHAN VICTOR GONCALVES SABBA',
        'endereco' => [
            'logradouro' => 'Avenida Jose Bonifacio',
            'numero' => 'S/N',
            'bairro' => 'Guama',
            'codigo_municipio' => '1501402',
            'uf' => 'PA',
            'cep' => '66065112',
        ],
    ],
    'servico' => [
        'codigo' => '0107',
        'item_lista_servico' => '0107',
        'codigo_cnae' => '620910000',
        'descricao' => 'Servicos de tecnologia da informacao em homologacao.',
        'discriminacao' => 'Servicos de tecnologia da informacao em homologacao.',
        'codigo_municipio' => '1501402',
        'aliquota' => 0.05,
        'iss_retido' => false,
        'exigibilidade_iss' => '1',
    ],
    'valor_servicos' => 10.00,
];

$resultado = $nfse->emitirCompleto($payload);

echo $resultado->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;


