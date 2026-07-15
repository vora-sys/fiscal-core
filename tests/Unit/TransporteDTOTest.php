<?php

namespace sabbajohn\FiscalCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NF\DTO\TransporteDTO;

class TransporteDTOTest extends TestCase
{
    public function test_criar_transporte_sem_frete()
    {
        $transporte = TransporteDTO::semFrete();

        $this->assertEquals(9, $transporte->modFrete);
        $this->assertNull($transporte->cnpjCpf);
        $this->assertNull($transporte->nome);
    }

    public function test_criar_transporte_por_conta_emitente()
    {
        $transporte = TransporteDTO::porContaEmitente(
            cnpjCpf: '12345678000195',
            nome: 'Transportadora ABC',
            inscricaoEstadual: '123456789',
            endereco: 'Rua Test, 123',
            nomeMunicipio: 'São Paulo',
            uf: 'SP'
        );

        $this->assertEquals(0, $transporte->modFrete);
        $this->assertEquals('12345678000195', $transporte->cnpjCpf);
        $this->assertEquals('Transportadora ABC', $transporte->nome);
        $this->assertEquals('SP', $transporte->uf);
    }

    public function test_criar_transporte_por_conta_destinatario()
    {
        $transporte = TransporteDTO::porContaDestinatario();

        $this->assertEquals(1, $transporte->modFrete);
    }

    public function test_adicionar_veiculo()
    {
        $transporte = TransporteDTO::semFrete()
            ->comVeiculo('ABC1234', 'SP', 'RNTC123');

        $this->assertEquals('ABC1234', $transporte->placa);
        $this->assertEquals('SP', $transporte->ufVeiculo);
        $this->assertEquals('RNTC123', $transporte->rntc);
    }

    public function test_adicionar_volumes()
    {
        $volumes = [
            ['qVol' => 10, 'esp' => 'Caixa', 'pesoL' => 100.5, 'pesoB' => 110.0],
        ];

        $transporte = TransporteDTO::semFrete()
            ->comVolumes($volumes);

        $this->assertEquals($volumes, $transporte->volumes);
    }

    public function test_adicionar_lacres()
    {
        $lacres = ['LAC001', 'LAC002'];

        $transporte = TransporteDTO::semFrete()
            ->comLacres($lacres);

        $this->assertCount(2, $transporte->lacres);
        $this->assertEquals('LAC001', $transporte->lacres[0]['nLacre']);
        $this->assertEquals('LAC002', $transporte->lacres[1]['nLacre']);
    }
}
