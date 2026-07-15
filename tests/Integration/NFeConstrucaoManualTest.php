<?php

namespace Tests\Integration;

use NFePHP\NFe\Make;
use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NF\Core\NotaFiscal;
use sabbajohn\FiscalCore\Adapters\NF\DTO\CofinsDTO;
use sabbajohn\FiscalCore\Adapters\NF\DTO\DestinatarioDTO;
use sabbajohn\FiscalCore\Adapters\NF\DTO\EmitenteDTO;
use sabbajohn\FiscalCore\Adapters\NF\DTO\IcmsDTO;
use sabbajohn\FiscalCore\Adapters\NF\DTO\IdentificacaoDTO;
use sabbajohn\FiscalCore\Adapters\NF\DTO\PisDTO;
use sabbajohn\FiscalCore\Adapters\NF\DTO\ProdutoDTO;
use sabbajohn\FiscalCore\Adapters\NF\Nodes\DestinatarioNode;
use sabbajohn\FiscalCore\Adapters\NF\Nodes\EmitenteNode;
use sabbajohn\FiscalCore\Adapters\NF\Nodes\IdentificacaoNode;
use sabbajohn\FiscalCore\Adapters\NF\Nodes\ImpostoNode;
use sabbajohn\FiscalCore\Adapters\NF\Nodes\ProdutoNode;

/**
 * Teste de integração NFe completa com impostos
 * Construção manual usando factory methods
 */
class NFeConstrucaoManualTest extends TestCase
{
    public function test_criar_n_fe_manualmente_com_impostos_completos()
    {
        $nota = new NotaFiscal;

        // Identificação
        $identificacao = IdentificacaoDTO::forNFe(
            cUF: 41,
            natOp: 'VENDA PARA COMERCIO',
            nNF: 1001,
            cMunFG: 4106902,
            idDest: 1
        );
        $nota->addNode(new IdentificacaoNode($identificacao));

        // Emitente
        $emitente = new EmitenteDTO(
            cnpj: '11223344000155',
            razaoSocial: 'INDUSTRIA MANUAL LTDA',
            nomeFantasia: 'MANUAL IND',
            inscricaoEstadual: '1122334455',
            logradouro: 'RUA INDUSTRIAL',
            numero: '500',
            bairro: 'INDUSTRIAL',
            codigoMunicipio: '4106902',
            nomeMunicipio: 'CURITIBA',
            uf: 'PR',
            cep: '81000000',
            crt: 3
        );
        $nota->addNode(new EmitenteNode($emitente));

        // Destinatário
        $destinatario = new DestinatarioDTO(
            cpfCnpj: '99887766000144',
            nome: 'COMERCIO DESTINO MANUAL LTDA',
            logradouro: 'AV DESTINO',
            numero: '100',
            bairro: 'COMERCIAL',
            codigoMunicipio: '4106902',
            nomeMunicipio: 'CURITIBA',
            uf: 'PR',
            cep: '80000010',
            inscricaoEstadual: '9988776655',
            indIEDest: 1
        );
        $nota->addNode(new DestinatarioNode($destinatario));

        // Produto
        $produto = ProdutoDTO::simple(
            item: 1,
            codigo: 'IND999',
            descricao: 'PRODUTO INDUSTRIAL MANUAL',
            ncm: '11223344',
            cfop: '5102',
            quantidade: 50,
            valorUnitario: 100.00
        );
        $nota->addNode(new ProdutoNode($produto));

        // Impostos
        $icms = IcmsDTO::icms00(
            vBC: 5000.00,
            pICMS: 18.00,
            vICMS: 900.00
        );
        $pis = PisDTO::naoCumulativo(
            vBC: 5000.00,
            pPIS: 1.65,
            vPIS: 82.50
        );
        $cofins = CofinsDTO::naoCumulativo(
            vBC: 5000.00,
            pCOFINS: 7.60,
            vCOFINS: 380.00
        );
        $nota->addNode(new ImpostoNode(1, $icms, $pis, $cofins));

        // Validar
        $this->assertTrue($nota->validate());

        // Verificar nodes
        $this->assertTrue($nota->hasNode('identificacao'));
        $this->assertTrue($nota->hasNode('emitente'));
        $this->assertTrue($nota->hasNode('destinatario'));
        $this->assertTrue($nota->hasNode('produto'));
        $this->assertTrue($nota->hasNode('imposto'));

        // Gerar Make
        $make = $nota->getMake();
        $this->assertInstanceOf(Make::class, $make);

        // Nota: XML completo requer tags adicionais (totais, transporte, etc)
    }

    public function test_criar_n_fe_com_simples_nacional()
    {
        $nota = new NotaFiscal;

        // Identificação
        $id = IdentificacaoDTO::forNFe(41, 'VENDA', 2002, 4106902, 1);
        $nota->addNode(new IdentificacaoNode($id));

        // Emitente Simples Nacional
        $emit = new EmitenteDTO(
            '55667788000199', 'EMPRESA SIMPLES LTDA', 'SIMPLES',
            '5566778899', 'RUA SIMPLES', '10', 'CENTRO',
            '4106902', 'CURITIBA', 'PR', '80000011',
            crt: 1  // Simples Nacional
        );
        $nota->addNode(new EmitenteNode($emit));

        // Destinatário
        $dest = new DestinatarioDTO(
            '11122233000144', 'CLIENTE SIMPLES LTDA', 'RUA CLIENTE',
            '20', 'CENTRO', '4106902', 'CURITIBA', 'PR', '80000012',
            inscricaoEstadual: '1112223344', indIEDest: 1
        );
        $nota->addNode(new DestinatarioNode($dest));

        // Produto
        $prod = ProdutoDTO::simple(1, 'SIMP001', 'PRODUTO SIMPLES', '99887766', '5102', 10, 50.00);
        $nota->addNode(new ProdutoNode($prod));

        // ICMS Simples Nacional com crédito
        $icms = IcmsDTO::simplesNacionalComCredito(
            pCredSN: 1.86,
            vCredICMSSN: 9.30
        );
        $nota->addNode(new ImpostoNode(1, $icms));

        // Validar
        $this->assertTrue($nota->validate());
        $this->assertCount(5, $nota->getNodes());
    }

    public function test_fluxo_completo_n_fe()
    {
        // 1. Criar nota
        $nota = new NotaFiscal;

        // 2. Adicionar componentes fluentemente
        $nota
            ->addNode(new IdentificacaoNode(
                IdentificacaoDTO::forNFe(41, 'VENDA FLUENTE', 3003, 4106902, 1)
            ))
            ->addNode(new EmitenteNode(new EmitenteDTO(
                '22334455000166', 'FLUENTE LTDA', 'FLUENTE', '2233445566',
                'RUA FLUENTE', '30', 'CENTRO', '4106902', 'CURITIBA', 'PR', '80000013'
            )))
            ->addNode(new DestinatarioNode(new DestinatarioDTO(
                '66778899000133', 'DESTINO FLUENTE', 'RUA DEST', '40', 'CENTRO',
                '4106902', 'CURITIBA', 'PR', '80000014',
                inscricaoEstadual: '6677889911',
                indIEDest: 1
            )))
            ->addNode(new ProdutoNode(
                ProdutoDTO::simple(1, 'FLU001', 'PRODUTO FLUENTE', '55667788', '5102', 20, 25.00)
            ))
            ->addNode(new ImpostoNode(1, IcmsDTO::icmsIsento()));

        // 3. Validar
        $this->assertTrue($nota->validate());

        // 4. Obter Make
        $make = $nota->getMake();
        $this->assertInstanceOf(Make::class, $make);

        // Nota: Interface fluente funcionando corretamente
    }
}
