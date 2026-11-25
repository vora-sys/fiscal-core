<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use sabbajohn\FiscalCore\Adapters\GTIN\ConsultaGtinCcg;
use sabbajohn\FiscalCore\Adapters\GTIN\TransporteConsultaGtin;

final class ConsultaGtinCcgTest extends TestCase
{
    public function test_consulta_preserva_zeros_e_retorna_tres_cests_sem_inferir_tributacao(): void
    {
        $transporte = $this->createMock(TransporteConsultaGtin::class);
        $transporte->expects(self::once())->method('enviar')->with(
            self::callback(static fn (string $xml): bool => str_contains($xml, '<GTIN>07894900011517</GTIN>')
                && str_contains($xml, '<ccg:ccgConsGTIN><ccg:nfeDadosMsg>')),
            'pfx-falso',
            'senha-falsa',
        )->willReturn($this->resposta('9490', '<GTIN>7894900011517</GTIN><xProd>Produto &amp; teste</xProd>'
            .'<NCM>01234567</NCM><CEST>0102030</CEST><CEST>0203040</CEST><CEST>0304050</CEST>'));

        self::assertSame([
            'status' => 'encontrado',
            'codigo_sefaz' => '9490',
            'gtin' => '07894900011517',
            'descricao' => 'Produto & teste',
            'ncm' => '01234567',
            'cests' => ['0102030', '0203040', '0304050'],
        ], (new ConsultaGtinCcg($transporte))->consultar('07894900011517', 'pfx-falso', 'senha-falsa'));
    }

    public function test_gtin_invalido_ou_prefixo_estrangeiro_nao_acessa_o_provedor(): void
    {
        $transporte = $this->createMock(TransporteConsultaGtin::class);
        $transporte->expects(self::never())->method('enviar');
        $consulta = new ConsultaGtinCcg($transporte);
        self::assertSame('prefixo_nao_suportado', $consulta->consultar('4006381333931', '', '')['status']);
        $this->expectException(InvalidArgumentException::class);
        $consulta->consultar('7894900011514', '', '');
    }

    public function test_status_da_sefaz_nao_fabrica_ncm_e_distinguem_indisponibilidade(): void
    {
        $casos = [
            '9494' => 'nao_encontrado', '9495' => 'situacao_invalida', '9496' => 'publicacao_nao_autorizada',
            '9497' => 'ncm_nao_cadastrado', '9498' => 'ncm_invalido', '9493' => 'certificado_rejeitado',
            '108' => 'indisponivel',
        ];

        foreach ($casos as $codigo => $status) {
            $transporte = $this->createStub(TransporteConsultaGtin::class);
            $transporte->method('enviar')->willReturn($this->resposta((string) $codigo, '<NCM>12345678</NCM>'));
            $dados = (new ConsultaGtinCcg($transporte))->consultar('7894900011517', '', '');
            self::assertSame($status, $dados['status']);
            self::assertNull($dados['ncm']);
        }
    }

    public function test_resposta_de_outro_produto_e_recusada(): void
    {
        $transporte = $this->createStub(TransporteConsultaGtin::class);
        $transporte->method('enviar')->willReturn($this->resposta('9490', '<GTIN>4006381333931</GTIN>'));
        $this->expectException(RuntimeException::class);
        (new ConsultaGtinCcg($transporte))->consultar('7894900011517', '', '');
    }

    public function test_resposta_xml_com_doctype_e_recusada(): void
    {
        $transporte = $this->createStub(TransporteConsultaGtin::class);
        $transporte->method('enviar')->willReturn('<!DOCTYPE Envelope []>'.$this->resposta('9490'));
        $this->expectException(RuntimeException::class);
        (new ConsultaGtinCcg($transporte))->consultar('7894900011517', '', '');
    }

    private function resposta(string $codigo, string $campos = ''): string
    {
        return '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            .'<ccg:ccgConsGTINResponse xmlns:ccg="http://www.portalfiscal.inf.br/nfe/wsdl/ccgConsGtin"><ccg:ccgConsGTINResult>'
            .'<retConsGTIN xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00"><cStat>'.$codigo.'</cStat>'.$campos.'</retConsGTIN>'
            .'</ccg:ccgConsGTINResult></ccg:ccgConsGTINResponse></soap:Body></soap:Envelope>';
    }
}
