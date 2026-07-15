<?php

namespace Tests\Unit\DTO;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NF\DTO\EmitenteDTO;

class EmitenteDTOTest extends TestCase
{
    public function test_criar_emitente()
    {
        $dto = new EmitenteDTO(
            cnpj: '12345678000190',
            razaoSocial: 'EMPRESA TESTE LTDA',
            nomeFantasia: 'EMPRESA TESTE',
            inscricaoEstadual: '1234567890',
            logradouro: 'RUA TESTE',
            numero: '123',
            bairro: 'CENTRO',
            codigoMunicipio: '4106902',
            nomeMunicipio: 'CURITIBA',
            uf: 'PR',
            cep: '80000000',
            crt: 1
        );

        $this->assertEquals('12345678000190', $dto->cnpj);
        $this->assertEquals('EMPRESA TESTE LTDA', $dto->razaoSocial);
        $this->assertEquals('CURITIBA', $dto->nomeMunicipio);
        $this->assertEquals(1, $dto->crt);
    }

    public function test_valores_padrao()
    {
        $dto = new EmitenteDTO(
            '12345678000190', 'EMPRESA', '', '123',
            'RUA', '1', 'BAIRRO', '123', 'CIDADE', 'UF', '12345'
        );

        $this->assertEquals('1058', $dto->codigoPais);
        $this->assertEquals('BRASIL', $dto->nomePais);
        $this->assertEquals(1, $dto->crt);
    }

    public function test_campos_opcionais()
    {
        $dto = new EmitenteDTO(
            '12345678000190', 'EMPRESA', '', '123',
            'RUA', '1', 'BAIRRO', '123', 'CIDADE', 'UF', '12345',
            complemento: 'SALA 10',
            telefone: '4133334444',
            inscricaoMunicipal: '9999',
            cnae: '1234567'
        );

        $this->assertEquals('SALA 10', $dto->complemento);
        $this->assertEquals('4133334444', $dto->telefone);
        $this->assertEquals('9999', $dto->inscricaoMunicipal);
        $this->assertEquals('1234567', $dto->cnae);
    }
}
