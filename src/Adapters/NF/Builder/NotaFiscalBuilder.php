<?php

namespace sabbajohn\FiscalCore\Adapters\NF\Builder;

use sabbajohn\FiscalCore\Adapters\NF\Core\NotaFiscal;
use sabbajohn\FiscalCore\Adapters\NF\XmlParser;
use sabbajohn\FiscalCore\Adapters\NF\DTO\{
    IdentificacaoDTO,
    EmitenteDTO,
    DestinatarioDTO,
    ProdutoDTO,
    IcmsDTO,
    PisDTO,
    CofinsDTO,
    PagamentoDTO,
    TotaisDTO,
    TransporteDTO,
    CobrancaDTO,
    InfoAdicionalDTO,
    ResponsavelTecnicoDTO,
    InfoSuplementarDTO
};
use sabbajohn\FiscalCore\Adapters\NF\Nodes\{
    IdentificacaoNode,
    EmitenteNode,
    DestinatarioNode,
    ProdutoNode,
    ImpostoNode,
    ImpostoSeletivoNode,
    IbsCbsNode,
    PagamentoNode,
    TotaisNode,
    TransporteNode,
    CobrancaNode,
    InfoAdicionalNode,
    ResponsavelTecnicoNode,
    InfoSuplementarNode
};

/**
 * Builder para construir NotaFiscal a partir de arrays/JSON/XML
 * Traduz dados brutos em DTOs e Nodes
 */
class NotaFiscalBuilder
{
    private NotaFiscal $nota;
    
    private function __construct()
    {
        $this->nota = new NotaFiscal();
    }
    
    /**
     * Cria um builder a partir de XML (string ou arquivo)
     * 
     * @param string $xmlContent Conteúdo XML ou caminho do arquivo
     * @param bool $isFile Se true, $xmlContent é tratado como caminho de arquivo
     */
    public static function fromXml(string $xmlContent, bool $isFile = false): self
    {
        if ($isFile) {
            if (!file_exists($xmlContent)) {
                throw new \InvalidArgumentException("Arquivo XML não encontrado: {$xmlContent}");
            }
            $xmlContent = file_get_contents($xmlContent);
        }
        
        $parser = new XmlParser($xmlContent);
        $data = $parser->toArray();
        
        return self::fromArray($data);
    }
    
    /**
     * Cria um builder a partir de um array de dados
     * 
     * Exemplo de estrutura:
     * [
     *   'identificacao' => [...],
     *   'emitente' => [...],
     *   'destinatario' => [...],
     *   'itens' => [
     *     [
     *       'produto' => [...],
     *       'impostos' => [...],
     *     ]
     *   ],
     *   'pagamentos' => [...],
     * ]
     */
    public static function fromArray(array $data): self
    {
        $builder = new self();

        $layout = self::extractLayoutConfig($data);
        if ($layout !== []) {
            $builder->setLayout($layout['xml_version'] ?? null, $layout['schema'] ?? null);
        }
        
        // Identificação
        if (isset($data['identificacao'])) {
            $builder->setIdentificacao($data['identificacao']);
        }
        
        // Emitente
        if (isset($data['emitente'])) {
            $builder->setEmitente($data['emitente']);
        }
        
        // Destinatário
        if (isset($data['destinatario'])) {
            $builder->setDestinatario($data['destinatario']);
        }
        
        // Itens (produtos + impostos)
        if (isset($data['itens'])) {
            foreach ($data['itens'] as $index => $item) {
                $builder->addItem($item, $index + 1);
            }
        }
        
        // Pagamentos
        if (isset($data['pagamentos'])) {
            $builder->setPagamentos($data['pagamentos']);
        }
        
        // Totais
        if (isset($data['totais'])) {
            $builder->setTotais($data['totais']);
        }
        
        // Transporte
        if (isset($data['transporte'])) {
            $builder->setTransporte($data['transporte']);
        }
        
        // Cobrança
        if (isset($data['cobranca'])) {
            $builder->setCobranca($data['cobranca']);
        }
        
        // Informações adicionais
        if (isset($data['infoAdicional'])) {
            $builder->setInfoAdicional($data['infoAdicional']);
        }
        
        // Responsável técnico
        if (isset($data['responsavelTecnico'])) {
            $builder->setResponsavelTecnico($data['responsavelTecnico']);
        }
        
        // Info suplementar (NFCe)
        if (isset($data['infoSuplementar'])) {
            $builder->setInfoSuplementar($data['infoSuplementar']);
        }
        
        return $builder;
    }

    public function setLayout(?string $xmlVersion = null, ?string $schema = null): self
    {
        $this->nota->setLayout($xmlVersion, $schema);

        return $this;
    }
    
    /**
     * Define a identificação da nota
     */
    public function setIdentificacao(array $data): self
    {
        $dto = new IdentificacaoDTO(
            cUF: $data['cUF'],
            cNF: $data['cNF'],
            natOp: $data['natOp'],
            mod: $data['mod'] ?? 55,
            serie: $data['serie'] ?? 1,
            nNF: $data['nNF'],
            dhEmi: $data['dhEmi'] ?? date('Y-m-d\TH:i:sP'),
            dhSaiEnt: $data['dhSaiEnt'] ?? null,
            tpNF: $data['tpNF'] ?? 1,
            idDest: $data['idDest'] ?? 1,
            cMunFG: $data['cMunFG'],
            tpImp: $data['tpImp'] ?? 1,
            tpEmis: $data['tpEmis'] ?? 1,
            cDV: $data['cDV'] ?? 0,
            tpAmb: $data['tpAmb'] ?? 2,
            finNFe: $data['finNFe'] ?? 1,
            indFinal: $data['indFinal'] ?? 1,
            indPres: $data['indPres'] ?? 1,
            procEmi: $data['procEmi'] ?? 0,
            verProc: $data['verProc'] ?? '1.0.0',
            indIntermed: isset($data['indIntermed']) ? (int)$data['indIntermed'] : null,
        );
        
        $this->nota->addNode(new IdentificacaoNode($dto));
        return $this;
    }
    
    /**
     * Define o emitente
     */
    public function setEmitente(array $data): self
    {
        $dto = new EmitenteDTO(
            cnpj: $data['cnpj'],
            razaoSocial: $data['razaoSocial'],
            nomeFantasia: $data['nomeFantasia'] ?? '',
            inscricaoEstadual: $data['inscricaoEstadual'],
            logradouro: $data['logradouro'],
            numero: $data['numero'],
            bairro: $data['bairro'],
            codigoMunicipio: $data['codigoMunicipio'],
            nomeMunicipio: $data['nomeMunicipio'] ?? $data['municipio'],
            uf: $data['uf'],
            cep: $data['cep'],
            codigoPais: $data['codigoPais'] ?? '1058',
            nomePais: $data['nomePais'] ?? $data['pais'] ?? 'BRASIL',
            complemento: $data['complemento'] ?? null,
            telefone: $data['telefone'] ?? null,
            inscricaoMunicipal: $data['inscricaoMunicipal'] ?? null,
            cnae: $data['cnae'] ?? null,
            crt: $data['crt'] ?? 1,
        );
        
        $this->nota->addNode(new EmitenteNode($dto));
        return $this;
    }
    
    /**
     * Define o destinatário
     */
    public function setDestinatario(array $data): self
    {
        $dto = new DestinatarioDTO(
            cpfCnpj: $data['cpfCnpj'],
            nome: $data['nome'],
            logradouro: $data['logradouro'] ?? '',
            numero: $data['numero'] ?? 'SN',
            bairro: $data['bairro'] ?? '',
            codigoMunicipio: $data['codigoMunicipio'] ?? '9999999',
            nomeMunicipio: $data['nomeMunicipio'] ?? $data['municipio'] ?? 'EXTERIOR',
            uf: $data['uf'] ?? 'EX',
            cep: $data['cep'] ?? '',
            codigoPais: $data['codigoPais'] ?? '1058',
            nomePais: $data['nomePais'] ?? $data['pais'] ?? 'BRASIL',
            inscricaoEstadual: $data['inscricaoEstadual'] ?? null,
            complemento: $data['complemento'] ?? null,
            telefone: $data['telefone'] ?? null,
            email: $data['email'] ?? null,
            indIEDest: $data['indIEDest'] ?? 9,
        );
        
        $this->nota->addNode(new DestinatarioNode($dto));
        return $this;
    }
    
    /**
     * Adiciona um item (produto + impostos)
     */
    public function addItem(array $item, int $numeroItem): self
    {
        // Produto
        $produto = $item['produto'];
        $produtoDto = new ProdutoDTO(
            item: $numeroItem,
            codigo: $produto['codigo'],
            cean: $produto['cean'] ?? $produto['cEAN'] ?? 'SEM GTIN',
            descricao: $produto['descricao'],
            ncm: $produto['ncm'],
            cfop: $produto['cfop'],
            unidadeComercial: $produto['unidadeComercial'] ?? $produto['unidade'],
            quantidadeComercial: $produto['quantidadeComercial'] ?? $produto['quantidade'],
            valorUnitario: $produto['valorUnitario'],
            valorTotal: $produto['valorTotal'],
            ceanTributavel: $produto['ceanTributavel'] ?? $produto['cEANTrib'] ?? $produto['cean'] ?? $produto['cEAN'] ?? 'SEM GTIN',
            unidadeTributavel: $produto['unidadeTributavel'] ?? $produto['unidadeComercial'] ?? $produto['unidade'],
            quantidadeTributavel: $produto['quantidadeTributavel'] ?? $produto['quantidadeComercial'] ?? $produto['quantidade'],
            valorUnitarioTributavel: $produto['valorUnitarioTributavel'] ?? $produto['valorUnitario'],
            indTot: $produto['indTot'] ?? 1,
            cest: $produto['cest'] ?? null,
        );
        
        $this->nota->addNode(new ProdutoNode($produtoDto));
        
        // Impostos
        if (isset($item['impostos'])) {
            $impostos = $item['impostos'];
            
            // ICMS
            $icmsData = $impostos['icms'];
            $icmsDto = new IcmsDTO(
                cst: $icmsData['cst'],
                orig: $icmsData['orig'] ?? 0,
                vBC: $icmsData['vBC'] ?? null,
                pICMS: $icmsData['pICMS'] ?? null,
                vICMS: $icmsData['vICMS'] ?? null,
                pCredSN: $icmsData['pCredSN'] ?? null,
                vCredICMSSN: $icmsData['vCredICMSSN'] ?? null,
                modBC: $icmsData['modBC'] ?? null,
                pRedBC: $icmsData['pRedBC'] ?? null,
                motDesICMS: $icmsData['motDesICMS'] ?? null,
            );
            
            // PIS
            $pisDto = null;
            if (isset($impostos['pis'])) {
                $pisData = $impostos['pis'];
                $pisDto = new PisDTO(
                    cst: $pisData['cst'],
                    vBC: $pisData['vBC'] ?? null,
                    pPIS: $pisData['pPIS'] ?? null,
                    vPIS: $pisData['vPIS'] ?? null,
                );
            }
            
            // COFINS
            $cofinsDto = null;
            if (isset($impostos['cofins'])) {
                $cofinsData = $impostos['cofins'];
                $cofinsDto = new CofinsDTO(
                    cst: $cofinsData['cst'],
                    vBC: $cofinsData['vBC'] ?? null,
                    pCOFINS: $cofinsData['pCOFINS'] ?? null,
                    vCOFINS: $cofinsData['vCOFINS'] ?? null,
                );
            }
            
            $this->nota->addNode(new ImpostoNode(
                $numeroItem,
                $icmsDto,
                $pisDto,
                $cofinsDto,
                isset($impostos['vTotTrib']) ? (float) $impostos['vTotTrib'] : null,
            ));

            $impostoSeletivo = $impostos['is'] ?? $impostos['imposto_seletivo'] ?? null;
            if (is_array($impostoSeletivo) && $impostoSeletivo !== []) {
                $this->nota->addNode(new ImpostoSeletivoNode($numeroItem, $impostoSeletivo));
            }

            if (isset($impostos['ibs_cbs']) && is_array($impostos['ibs_cbs']) && $impostos['ibs_cbs'] !== []) {
                $this->nota->addNode(new IbsCbsNode($numeroItem, $impostos['ibs_cbs']));
            }
        }
        
        return $this;
    }
    
    /**
     * Define as formas de pagamento
     */
    public function setPagamentos(array $pagamentosData): self
    {
        $pagamentos = [];
        
        foreach ($pagamentosData as $pag) {
            $pagamentos[] = new PagamentoDTO(
                tPag: $pag['tPag'],
                vPag: $pag['vPag'],
                tpIntegra: $pag['tpIntegra'] ?? null,
                cnpj: $pag['cnpj'] ?? null,
                tBand: $pag['tBand'] ?? null,
                cAut: $pag['cAut'] ?? null,
            );
        }
        
        $this->nota->addNode(new PagamentoNode(...$pagamentos));
        return $this;
    }
    
    /**
     * Define os totais da nota
     */
    public function setTotais(array $data): self
    {
        $dto = new TotaisDTO(
            vBC: $data['vBC'] ?? 0.00,
            vBCST: $data['vBCST'] ?? 0.00,
            vICMS: $data['vICMS'] ?? 0.00,
            vST: $data['vST'] ?? 0.00,
            vICMSDeson: $data['vICMSDeson'] ?? 0.00,
            vFCP: $data['vFCP'] ?? 0.00,
            vFCPST: $data['vFCPST'] ?? 0.00,
            vFCPSTRet: $data['vFCPSTRet'] ?? 0.00,
            vPIS: $data['vPIS'] ?? 0.00,
            vCOFINS: $data['vCOFINS'] ?? 0.00,
            vII: $data['vII'] ?? 0.00,
            vIPI: $data['vIPI'] ?? 0.00,
            vIPIDevol: $data['vIPIDevol'] ?? 0.00,
            vProd: $data['vProd'] ?? 0.00,
            vServ: $data['vServ'] ?? 0.00,
            vFrete: $data['vFrete'] ?? 0.00,
            vSeg: $data['vSeg'] ?? 0.00,
            vDesc: $data['vDesc'] ?? 0.00,
            vOutro: $data['vOutro'] ?? 0.00,
            vNF: $data['vNF'] ?? 0.00,
            vTotTrib: $data['vTotTrib'] ?? 0.00,
            vFCPUFDest: $data['vFCPUFDest'] ?? 0.00,
            vICMSUFDest: $data['vICMSUFDest'] ?? 0.00,
            vICMSUFRemet: $data['vICMSUFRemet'] ?? 0.00,
            vRetPIS: $data['vRetPIS'] ?? 0.00,
            vRetCOFINS: $data['vRetCOFINS'] ?? 0.00,
            vRetCSLL: $data['vRetCSLL'] ?? 0.00,
            vBCIRRF: $data['vBCIRRF'] ?? 0.00,
            vIRRF: $data['vIRRF'] ?? 0.00,
            vBCRetPrev: $data['vBCRetPrev'] ?? 0.00,
            vRetPrev: $data['vRetPrev'] ?? 0.00,
        );
        
        $this->nota->addNode(new TotaisNode($dto));
        return $this;
    }
    
    /**
     * Define dados de transporte
     */
    public function setTransporte(array $data): self
    {
        $dto = new TransporteDTO(
            modFrete: $data['modFrete'],
            cnpjCpf: $data['cnpjCpf'] ?? null,
            nome: $data['nome'] ?? null,
            inscricaoEstadual: $data['inscricaoEstadual'] ?? null,
            endereco: $data['endereco'] ?? null,
            nomeMunicipio: $data['nomeMunicipio'] ?? null,
            uf: $data['uf'] ?? null,
            placa: $data['placa'] ?? null,
            ufVeiculo: $data['ufVeiculo'] ?? null,
            rntc: $data['rntc'] ?? null,
            reboque: $data['reboque'] ?? null,
            volumes: $data['volumes'] ?? null,
            lacres: $data['lacres'] ?? null,
        );
        
        $this->nota->addNode(new TransporteNode($dto));
        return $this;
    }
    
    /**
     * Define dados de cobrança
     */
    public function setCobranca(array $data): self
    {
        $dto = new CobrancaDTO(
            numeroFatura: $data['numeroFatura'] ?? null,
            valorOriginal: $data['valorOriginal'] ?? null,
            valorDesconto: $data['valorDesconto'] ?? null,
            valorLiquido: $data['valorLiquido'] ?? null,
            duplicatas: $data['duplicatas'] ?? [],
        );
        
        $this->nota->addNode(new CobrancaNode($dto));
        return $this;
    }
    
    /**
     * Define informações adicionais
     */
    public function setInfoAdicional(array $data): self
    {
        $dto = new InfoAdicionalDTO(
            infAdFisco: $data['infAdFisco'] ?? null,
            infCpl: $data['infCpl'] ?? null,
            obsCont: $data['obsCont'] ?? [],
            obsFisco: $data['obsFisco'] ?? [],
        );
        
        $this->nota->addNode(new InfoAdicionalNode($dto));
        return $this;
    }
    
    /**
     * Define responsável técnico
     */
    public function setResponsavelTecnico(array $data): self
    {
        $dto = new ResponsavelTecnicoDTO(
            cnpj: $data['cnpj'],
            xContato: $data['xContato'],
            email: $data['email'],
            fone: $data['fone'],
            idCSRT: $data['idCSRT'] ?? null,
            hashCSRT: $data['hashCSRT'] ?? null,
        );
        
        $this->nota->addNode(new ResponsavelTecnicoNode($dto));
        return $this;
    }
    
    /**
     * Define informações suplementares (NFCe)
     */
    public function setInfoSuplementar(array $data): self
    {
        $dto = new InfoSuplementarDTO(
            qrCode: $data['qrCode'],
            urlChave: $data['urlChave'] ?? null,
        );
        
        $this->nota->addNode(new InfoSuplementarNode($dto));
        return $this;
    }
    
    /**
     * Retorna a NotaFiscal construída
     */
    public function build(): NotaFiscal
    {
        return $this->nota;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{xml_version?:string|null,schema?:string|null}
     */
    private static function extractLayoutConfig(array $data): array
    {
        $layout = [];
        foreach (['layout', 'compatibilidade', 'compatibility'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $layout = $data[$key];
                break;
            }
        }

        if ($layout === []) {
            return [];
        }

        return [
            'xml_version' => $layout['xml_version']
                ?? $layout['versao_xml']
                ?? $layout['versao']
                ?? $layout['version']
                ?? null,
            'schema' => $layout['schema']
                ?? $layout['schemas']
                ?? $layout['layout_schema']
                ?? null,
        ];
    }
}
