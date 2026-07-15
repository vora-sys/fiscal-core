<?php

namespace sabbajohn\FiscalCore\Adapters\NF;

use sabbajohn\FiscalCore\Adapters\NF\DTO\CobrancaDTO;
use sabbajohn\FiscalCore\Adapters\NF\DTO\DestinatarioDTO;
use sabbajohn\FiscalCore\Adapters\NF\DTO\EmitenteDTO;
use sabbajohn\FiscalCore\Adapters\NF\DTO\IdentificacaoDTO;
use sabbajohn\FiscalCore\Adapters\NF\DTO\InfoAdicionalDTO;
use sabbajohn\FiscalCore\Adapters\NF\DTO\InfoSuplementarDTO;
use sabbajohn\FiscalCore\Adapters\NF\DTO\PagamentoDTO;
use sabbajohn\FiscalCore\Adapters\NF\DTO\ProdutoDTO;
use sabbajohn\FiscalCore\Adapters\NF\DTO\ResponsavelTecnicoDTO;
use sabbajohn\FiscalCore\Adapters\NF\DTO\TotaisDTO;
use sabbajohn\FiscalCore\Adapters\NF\DTO\TransporteDTO;

/**
 * Parser de XML NFe/NFCe para estrutura de dados
 * Extrai informações do XML e converte para DTOs
 */
class XmlParser
{
    private \SimpleXMLElement $xml;

    private \SimpleXMLElement $infNFe;

    public function __construct(string $xmlContent)
    {
        // Remove namespace para facilitar parsing
        $xmlContent = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $xmlContent);
        $this->xml = new \SimpleXMLElement($xmlContent);

        // Localiza infNFe (pode estar em NFe/infNFe ou nfeProc/NFe/infNFe)
        if (isset($this->xml->infNFe)) {
            $this->infNFe = $this->xml->infNFe;
        } elseif (isset($this->xml->NFe->infNFe)) {
            $this->infNFe = $this->xml->NFe->infNFe;
        } else {
            throw new \InvalidArgumentException('XML inválido: tag infNFe não encontrada');
        }
    }

    /**
     * Extrai dados de identificação (tag <ide>)
     */
    public function parseIdentificacao(): IdentificacaoDTO
    {
        $ide = $this->infNFe->ide;

        return new IdentificacaoDTO(
            cUF: (int) $ide->cUF,
            cNF: (int) ($ide->cNF ?? 0),
            natOp: (string) $ide->natOp,
            mod: (int) $ide->mod,
            serie: (int) $ide->serie,
            nNF: (int) $ide->nNF,
            dhEmi: (string) $ide->dhEmi,
            tpNF: (int) $ide->tpNF,
            idDest: (int) $ide->idDest,
            cMunFG: (int) $ide->cMunFG,
            tpImp: (int) $ide->tpImp,
            tpEmis: (int) $ide->tpEmis,
            cDV: (int) ($ide->cDV ?? 0),
            tpAmb: (int) $ide->tpAmb,
            finNFe: (int) $ide->finNFe,
            indFinal: (int) $ide->indFinal,
            indPres: (int) $ide->indPres,
            procEmi: (int) ($ide->procEmi ?? 0),
            verProc: (string) ($ide->verProc ?? '1.0.0'),
            dhSaiEnt: isset($ide->dhSaiEnt) ? (string) $ide->dhSaiEnt : null,
            indIntermed: isset($ide->indIntermed) ? (int) $ide->indIntermed : null,
        );
    }

    /**
     * Extrai dados do emitente (tag <emit>)
     */
    public function parseEmitente(): EmitenteDTO
    {
        $emit = $this->infNFe->emit;
        $enderEmit = $emit->enderEmit;

        return new EmitenteDTO(
            cnpj: (string) $emit->CNPJ,
            razaoSocial: (string) $emit->xNome,
            nomeFantasia: (string) ($emit->xFant ?? ''),
            inscricaoEstadual: (string) $emit->IE,
            logradouro: (string) $enderEmit->xLgr,
            numero: (string) $enderEmit->nro,
            bairro: (string) $enderEmit->xBairro,
            codigoMunicipio: (string) $enderEmit->cMun,
            nomeMunicipio: (string) $enderEmit->xMun,
            uf: (string) $enderEmit->UF,
            cep: (string) $enderEmit->CEP,
            codigoPais: (string) ($enderEmit->cPais ?? '1058'),
            nomePais: (string) ($enderEmit->xPais ?? 'BRASIL'),
            telefone: isset($enderEmit->fone) ? (string) $enderEmit->fone : null,
            complemento: isset($enderEmit->xCpl) ? (string) $enderEmit->xCpl : null,
            crt: (int) $emit->CRT,
        );
    }

    /**
     * Extrai dados do destinatário (tag <dest>)
     */
    public function parseDestinatario(): ?DestinatarioDTO
    {
        if (! isset($this->infNFe->dest)) {
            return null;
        }

        $dest = $this->infNFe->dest;
        $cpfCnpj = isset($dest->CPF) ? (string) $dest->CPF : (string) $dest->CNPJ;

        // Endereço pode ser opcional - usar strings vazias como fallback
        $logradouro = '';
        $numero = '';
        $bairro = '';
        $codigoMunicipio = '';
        $nomeMunicipio = '';
        $uf = '';
        $cep = '';
        $complemento = null;

        if (isset($dest->enderDest)) {
            $enderDest = $dest->enderDest;
            $logradouro = (string) $enderDest->xLgr;
            $numero = (string) $enderDest->nro;
            $bairro = (string) $enderDest->xBairro;
            $codigoMunicipio = (string) $enderDest->cMun;
            $nomeMunicipio = (string) $enderDest->xMun;
            $uf = (string) $enderDest->UF;
            $cep = (string) $enderDest->CEP;
            $complemento = isset($enderDest->xCpl) ? (string) $enderDest->xCpl : null;
        }

        return new DestinatarioDTO(
            cpfCnpj: $cpfCnpj,
            nome: (string) $dest->xNome,
            logradouro: $logradouro,
            numero: $numero,
            bairro: $bairro,
            codigoMunicipio: $codigoMunicipio,
            nomeMunicipio: $nomeMunicipio,
            uf: $uf,
            cep: $cep,
            codigoPais: isset($dest->enderDest->cPais) ? (string) $dest->enderDest->cPais : '1058',
            nomePais: isset($dest->enderDest->xPais) ? (string) $dest->enderDest->xPais : 'BRASIL',
            telefone: isset($dest->enderDest->fone) ? (string) $dest->enderDest->fone : null,
            email: isset($dest->email) ? (string) $dest->email : null,
            inscricaoEstadual: isset($dest->IE) ? (string) $dest->IE : null,
            complemento: $complemento,
            indIEDest: isset($dest->indIEDest) ? (int) $dest->indIEDest : 9,
        );
    }

    /**
     * Extrai todos os produtos/itens (tag <det>)
     */
    public function parseProdutos(): array
    {
        $produtos = [];

        foreach ($this->infNFe->det as $det) {
            $prod = $det->prod;

            $produtos[] = new ProdutoDTO(
                item: (int) $det['nItem'],
                codigo: (string) $prod->cProd,
                descricao: (string) $prod->xProd,
                ncm: (string) $prod->NCM,
                cfop: (string) $prod->CFOP,
                unidadeComercial: (string) $prod->uCom,
                quantidadeComercial: (float) $prod->qCom,
                valorUnitario: (float) $prod->vUnCom,
                valorTotal: (float) $prod->vProd,
                unidadeTributavel: (string) ($prod->uTrib ?? $prod->uCom),
                quantidadeTributavel: (float) ($prod->qTrib ?? $prod->qCom),
                valorUnitarioTributavel: (float) ($prod->vUnTrib ?? $prod->vUnCom),
                cean: isset($prod->cEAN) ? (string) $prod->cEAN : 'SEM GTIN',
                ceanTributavel: isset($prod->cEANTrib) ? (string) $prod->cEANTrib : 'SEM GTIN',
                indTot: isset($prod->indTot) ? (int) $prod->indTot : 1,
                cest: isset($prod->CEST) ? (string) $prod->CEST : null,
            );
        }

        return $produtos;
    }

    /**
     * Extrai impostos de um item específico
     */
    public function parseImpostos(int $item): array
    {
        $det = $this->infNFe->det[$item - 1];
        $imposto = $det->imposto;

        $impostos = [];

        // ICMS
        if (isset($imposto->ICMS)) {
            $icms = $imposto->ICMS;

            // Detecta o tipo de ICMS
            $icmsData = null;
            if (isset($icms->ICMSSN101) || isset($icms->ICMSSN102) || isset($icms->ICMSSN103) ||
                isset($icms->ICMSSN201) || isset($icms->ICMSSN202) || isset($icms->ICMSSN203) ||
                isset($icms->ICMSSN300) || isset($icms->ICMSSN400) || isset($icms->ICMSSN500) ||
                isset($icms->ICMSSN900)) {

                // Simples Nacional - pega o primeiro que encontrar
                foreach ($icms->children() as $icmsNode) {
                    $icmsData = $icmsNode;
                    break;
                }

                $impostos['icms'] = [
                    'cst' => (string) $icmsData->CSOSN,
                    'orig' => (int) $icmsData->orig,
                    'pCredSN' => isset($icmsData->pCredSN) ? (float) $icmsData->pCredSN : null,
                    'vCredICMSSN' => isset($icmsData->vCredICMSSN) ? (float) $icmsData->vCredICMSSN : null,
                ];
            } else {
                // Regime normal - pega o primeiro que encontrar
                foreach ($icms->children() as $icmsNode) {
                    $icmsData = $icmsNode;
                    break;
                }

                $impostos['icms'] = [
                    'cst' => (string) $icmsData->CST,
                    'orig' => (int) $icmsData->orig,
                    'modBC' => isset($icmsData->modBC) ? (int) $icmsData->modBC : null,
                    'vBC' => isset($icmsData->vBC) ? (float) $icmsData->vBC : null,
                    'pICMS' => isset($icmsData->pICMS) ? (float) $icmsData->pICMS : null,
                    'vICMS' => isset($icmsData->vICMS) ? (float) $icmsData->vICMS : null,
                    'pRedBC' => isset($icmsData->pRedBC) ? (float) $icmsData->pRedBC : null,
                ];
            }
        }

        // PIS
        if (isset($imposto->PIS)) {
            $pis = $imposto->PIS;

            // Pega o primeiro node filho
            foreach ($pis->children() as $pisNode) {
                $impostos['pis'] = [
                    'cst' => (string) $pisNode->CST,
                    'vBC' => isset($pisNode->vBC) ? (float) $pisNode->vBC : null,
                    'pPIS' => isset($pisNode->pPIS) ? (float) $pisNode->pPIS : null,
                    'vPIS' => isset($pisNode->vPIS) ? (float) $pisNode->vPIS : null,
                ];
                break;
            }
        }

        // COFINS
        if (isset($imposto->COFINS)) {
            $cofins = $imposto->COFINS;

            // Pega o primeiro node filho
            foreach ($cofins->children() as $cofinsNode) {
                $impostos['cofins'] = [
                    'cst' => (string) $cofinsNode->CST,
                    'vBC' => isset($cofinsNode->vBC) ? (float) $cofinsNode->vBC : null,
                    'pCOFINS' => isset($cofinsNode->pCOFINS) ? (float) $cofinsNode->pCOFINS : null,
                    'vCOFINS' => isset($cofinsNode->vCOFINS) ? (float) $cofinsNode->vCOFINS : null,
                ];
                break;
            }
        }

        return $impostos;
    }

    /**
     * Extrai formas de pagamento (tag <pag>)
     */
    public function parsePagamentos(): array
    {
        $pagamentos = [];

        if (! isset($this->infNFe->pag)) {
            return $pagamentos;
        }

        // Pode ter múltiplas formas de pagamento
        $pags = $this->infNFe->pag;

        // Se tiver apenas uma tag <detPag>, ela vem diretamente
        // Se tiver múltiplas, vem como array
        if (isset($pags->detPag)) {
            if (is_array($pags->detPag)) {
                foreach ($pags->detPag as $detPag) {
                    $pagamentos[] = $this->parsePagamento($detPag);
                }
            } else {
                $pagamentos[] = $this->parsePagamento($pags->detPag);
            }
        }

        return $pagamentos;
    }

    /**
     * Parse de um pagamento individual
     */
    private function parsePagamento(\SimpleXMLElement $detPag): PagamentoDTO
    {
        return new PagamentoDTO(
            tPag: (string) $detPag->tPag,
            vPag: (float) $detPag->vPag,
            tpIntegra: isset($detPag->card->tpIntegra) ? (int) $detPag->card->tpIntegra : null,
            cnpj: isset($detPag->card->CNPJ) ? (string) $detPag->card->CNPJ : null,
            tBand: isset($detPag->card->tBand) ? (string) $detPag->card->tBand : null,
            cAut: isset($detPag->card->cAut) ? (string) $detPag->card->cAut : null,
        );
    }

    /**
     * Extrai dados de totais (tag <total><ICMSTot>)
     */
    public function parseTotais(): TotaisDTO
    {
        $icmsTot = $this->infNFe->total->ICMSTot;

        return new TotaisDTO(
            vBC: (float) ($icmsTot->vBC ?? 0),
            vBCST: (float) ($icmsTot->vBCST ?? 0),
            vICMS: (float) ($icmsTot->vICMS ?? 0),
            vST: (float) ($icmsTot->vST ?? 0),
            vICMSDeson: (float) ($icmsTot->vICMSDeson ?? 0),
            vFCP: (float) ($icmsTot->vFCP ?? 0),
            vFCPST: (float) ($icmsTot->vFCPST ?? 0),
            vFCPSTRet: (float) ($icmsTot->vFCPSTRet ?? 0),
            vPIS: (float) ($icmsTot->vPIS ?? 0),
            vCOFINS: (float) ($icmsTot->vCOFINS ?? 0),
            vII: (float) ($icmsTot->vII ?? 0),
            vIPI: (float) ($icmsTot->vIPI ?? 0),
            vIPIDevol: (float) ($icmsTot->vIPIDevol ?? 0),
            vProd: (float) ($icmsTot->vProd ?? 0),
            vServ: (float) ($icmsTot->vServ ?? 0),
            vFrete: (float) ($icmsTot->vFrete ?? 0),
            vSeg: (float) ($icmsTot->vSeg ?? 0),
            vDesc: (float) ($icmsTot->vDesc ?? 0),
            vOutro: (float) ($icmsTot->vOutro ?? 0),
            vNF: (float) ($icmsTot->vNF ?? 0),
            vTotTrib: (float) ($icmsTot->vTotTrib ?? 0),
        );
    }

    /**
     * Extrai dados de transporte (tag <transp>)
     */
    public function parseTransporte(): ?TransporteDTO
    {
        if (! isset($this->infNFe->transp)) {
            return null;
        }

        $transp = $this->infNFe->transp;

        return new TransporteDTO(
            modFrete: (int) $transp->modFrete,
            cnpjCpf: isset($transp->transporta) ? (string) ($transp->transporta->CNPJ ?? $transp->transporta->CPF ?? null) : null,
            nome: isset($transp->transporta->xNome) ? (string) $transp->transporta->xNome : null,
            inscricaoEstadual: isset($transp->transporta->IE) ? (string) $transp->transporta->IE : null,
            endereco: isset($transp->transporta->xEnder) ? (string) $transp->transporta->xEnder : null,
            nomeMunicipio: isset($transp->transporta->xMun) ? (string) $transp->transporta->xMun : null,
            uf: isset($transp->transporta->UF) ? (string) $transp->transporta->UF : null,
        );
    }

    /**
     * Extrai dados de cobrança (tag <cobr>)
     */
    public function parseCobranca(): ?CobrancaDTO
    {
        if (! isset($this->infNFe->cobr)) {
            return null;
        }

        $cobr = $this->infNFe->cobr;
        $duplicatas = [];

        // Extrair duplicatas se existirem
        if (isset($cobr->dup)) {
            if (is_array($cobr->dup)) {
                foreach ($cobr->dup as $dup) {
                    $duplicatas[] = [
                        'nDup' => (string) $dup->nDup,
                        'dVenc' => isset($dup->dVenc) ? (string) $dup->dVenc : null,
                        'vDup' => (float) $dup->vDup,
                    ];
                }
            } else {
                $duplicatas[] = [
                    'nDup' => (string) $cobr->dup->nDup,
                    'dVenc' => isset($cobr->dup->dVenc) ? (string) $cobr->dup->dVenc : null,
                    'vDup' => (float) $cobr->dup->vDup,
                ];
            }
        }

        return new CobrancaDTO(
            numeroFatura: isset($cobr->fat->nFat) ? (string) $cobr->fat->nFat : null,
            valorOriginal: isset($cobr->fat->vOrig) ? (float) $cobr->fat->vOrig : null,
            valorDesconto: isset($cobr->fat->vDesc) ? (float) $cobr->fat->vDesc : null,
            valorLiquido: isset($cobr->fat->vLiq) ? (float) $cobr->fat->vLiq : null,
            duplicatas: $duplicatas,
        );
    }

    /**
     * Extrai informações adicionais (tag <infAdic>)
     */
    public function parseInfoAdicional(): ?InfoAdicionalDTO
    {
        if (! isset($this->infNFe->infAdic)) {
            return null;
        }

        $infAdic = $this->infNFe->infAdic;

        return new InfoAdicionalDTO(
            infAdFisco: isset($infAdic->infAdFisco) ? (string) $infAdic->infAdFisco : null,
            infCpl: isset($infAdic->infCpl) ? (string) $infAdic->infCpl : null,
        );
    }

    /**
     * Extrai responsável técnico (tag <infRespTec>)
     */
    public function parseResponsavelTecnico(): ?ResponsavelTecnicoDTO
    {
        if (! isset($this->infNFe->infRespTec)) {
            return null;
        }

        $resp = $this->infNFe->infRespTec;

        return new ResponsavelTecnicoDTO(
            cnpj: (string) $resp->CNPJ,
            xContato: (string) $resp->xContato,
            email: (string) $resp->email,
            fone: (string) $resp->fone,
            idCSRT: isset($resp->idCSRT) ? (string) $resp->idCSRT : null,
            hashCSRT: isset($resp->hashCSRT) ? (string) $resp->hashCSRT : null,
        );
    }

    /**
     * Extrai informações suplementares NFCe (tag <infNFeSupl>)
     */
    public function parseInfoSuplementar(): ?InfoSuplementarDTO
    {
        // Buscar no nível superior (fora de infNFe)
        $infSupl = null;

        if (isset($this->xml->infNFeSupl)) {
            $infSupl = $this->xml->infNFeSupl;
        } elseif (isset($this->xml->NFe->infNFeSupl)) {
            $infSupl = $this->xml->NFe->infNFeSupl;
        }

        if (! $infSupl) {
            return null;
        }

        return new InfoSuplementarDTO(
            qrCode: (string) $infSupl->qrCode,
            urlChave: isset($infSupl->urlChave) ? (string) $infSupl->urlChave : null,
        );
    }

    /**
     * Converte para array completo (formato compatível com Builder::fromArray)
     */
    public function toArray(): array
    {
        $data = [
            'identificacao' => $this->dtoToArray($this->parseIdentificacao()),
            'emitente' => $this->dtoToArray($this->parseEmitente()),
        ];

        // Destinatário (opcional)
        $destinatario = $this->parseDestinatario();
        if ($destinatario) {
            $data['destinatario'] = $this->dtoToArray($destinatario);
        }

        // Produtos
        $produtos = $this->parseProdutos();
        if (! empty($produtos)) {
            $data['itens'] = [];
            foreach ($produtos as $index => $produto) {
                $item = ['produto' => $this->dtoToArray($produto)];

                // Impostos do item (já retorna array, não precisa converter)
                $impostos = $this->parseImpostos($index + 1);
                if (! empty($impostos)) {
                    $item['impostos'] = $impostos;
                }

                $data['itens'][] = $item;
            }
        }

        // Pagamentos
        $pagamentos = $this->parsePagamentos();
        if (! empty($pagamentos)) {
            $data['pagamentos'] = array_map(fn ($p) => $this->dtoToArray($p), $pagamentos);
        }

        // Totais
        $totais = $this->parseTotais();
        $data['totais'] = $this->dtoToArray($totais);

        // Transporte
        $transporte = $this->parseTransporte();
        if ($transporte) {
            $data['transporte'] = $this->dtoToArray($transporte);
        }

        // Cobrança
        $cobranca = $this->parseCobranca();
        if ($cobranca) {
            $data['cobranca'] = $this->dtoToArray($cobranca);
        }

        // Informações adicionais
        $infoAdicional = $this->parseInfoAdicional();
        if ($infoAdicional) {
            $data['infoAdicional'] = $this->dtoToArray($infoAdicional);
        }

        // Responsável técnico
        $respTecnico = $this->parseResponsavelTecnico();
        if ($respTecnico) {
            $data['responsavelTecnico'] = $this->dtoToArray($respTecnico);
        }

        // Info suplementar (NFCe)
        $infoSupl = $this->parseInfoSuplementar();
        if ($infoSupl) {
            $data['infoSuplementar'] = $this->dtoToArray($infoSupl);
        }

        return $data;
    }

    /**
     * Converte DTO para array associativo, removendo valores null
     */
    private function dtoToArray(object $dto): array
    {
        return array_filter(get_object_vars($dto), fn ($value) => $value !== null);
    }
}
