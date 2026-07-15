<?php

/**
 * Consultas em APIs públicas brasileiras
 * CEP, CNPJ, Bancos e NCM - sem configuração necessária
 *
 * Separado do contexto fiscal para manter responsabilidades claras
 */

require_once __DIR__.'/../../vendor/autoload.php';

use sabbajohn\FiscalCore\Facade\UtilsFacade;

$utils = new UtilsFacade;

// Consulta CEP
$cep = $utils->consultarCEP('01310-100');
if ($cep->isSuccess()) {
    $dados = $cep->getData();
    echo 'CEP: '.$dados['logradouro'].', '.$dados['bairro']."\n";
}

// Consulta CNPJ
$cnpj = $utils->consultarCNPJ('11222333000181');
if ($cnpj->isSuccess()) {
    $dados = $cnpj->getData();
    echo 'CNPJ: '.$dados['razao_social']."\n";
}

// Lista bancos
$bancos = $utils->listarBancos();
if ($bancos->isSuccess()) {
    $dados = $bancos->getData();
    echo 'Bancos encontrados: '.count($dados)."\n";
    echo 'Banco do Brasil: '.$dados[0]['nome']."\n";
}

// Valida CPF/CNPJ
$cpf = $utils->validarCPF('12345678901');
echo 'CPF válido: '.($cpf->isSuccess() ? 'Sim' : 'Não')."\n";

// Lista feriados do ano
$feriados = $utils->listarFeriados();
if ($feriados->isSuccess()) {
    $dados = $feriados->getData();
    echo 'Feriados '.date('Y').': '.count($dados)."\n";
}
