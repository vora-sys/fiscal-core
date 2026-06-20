<?php

namespace sabbajohn\FiscalCore\Adapters\NF\Nodes;

use sabbajohn\FiscalCore\Adapters\NF\Core\NotaNodeInterface;
use sabbajohn\FiscalCore\Adapters\NF\DTO\TransporteDTO;
use NFePHP\NFe\Make;

use sabbajohn\FiscalCore\Adapters\NF\Helpers\StdClassBuilder;

/**
 * Node para dados de transporte
 * Encapsula TransporteDTO e adiciona à tag <transp>
 */
class TransporteNode implements NotaNodeInterface
{
    public function __construct(
        private TransporteDTO $transporte
    ) {}
    
    public function getNodeType(): string
    {
        return 'transporte';
    }
    
    public function validate(): bool
    {
        // Se modal não for "sem frete" e tiver transportadora, validar dados
        if ($this->transporte->modFrete !== 9 && $this->transporte->cnpjCpf) {
            if (!$this->transporte->nome) {
                throw new \InvalidArgumentException('Nome da transportadora é obrigatório quando CNPJ/CPF informado');
            }
            
            // Validar CNPJ/CPF
            $doc = preg_replace('/[^0-9]/', '', $this->transporte->cnpjCpf);
            if (strlen($doc) !== 11 && strlen($doc) !== 14) {
                throw new \InvalidArgumentException('CNPJ/CPF da transportadora inválido');
            }
        }
        
        // Validar placa se informada
        if ($this->transporte->placa) {
            if (!preg_match('/^[A-Z]{3}\d{4}$|^[A-Z]{3}\d[A-Z]\d{2}$/', $this->transporte->placa)) {
                throw new \InvalidArgumentException('Placa do veículo inválida (formato: ABC1234 ou ABC1D23)');
            }
            
            if (!$this->transporte->ufVeiculo) {
                throw new \InvalidArgumentException('UF do veículo é obrigatória quando placa informada');
            }
        }
        
        return true;
    }
    
    public function addToMake(Make $make): void
    {
        // Adicionar modal de frete
        $make->tagtransp(StdClassBuilder::create([
            'modFrete' => $this->transporte->modFrete,
        ]));
        
        // Se tiver transportadora, adicionar dados
        if ($this->transporte->cnpjCpf && $this->transporte->nome) {
            $doc = preg_replace('/[^0-9]/', '', $this->transporte->cnpjCpf);
            
            $make->tagtransporta(StdClassBuilder::create([
                'CNPJ' => strlen($doc) === 14 ? $doc : null,
                'CPF' => strlen($doc) === 11 ? $doc : null,
                'xNome' => $this->transporte->nome,
                'IE' => $this->transporte->inscricaoEstadual,
                'xEnder' => $this->transporte->endereco,
                'xMun' => $this->transporte->nomeMunicipio,
                'UF' => $this->transporte->uf,
            ]));
        }
        
        // Se tiver veículo, adicionar dados
        if ($this->transporte->placa) {
            $make->tagveicTransp(StdClassBuilder::create([
                'placa' => $this->transporte->placa,
                'UF' => $this->transporte->ufVeiculo,
                'RNTC' => $this->transporte->rntc,
            ]));
        }
        
        // Se tiver reboque, adicionar
        if ($this->transporte->reboque) {
            foreach ($this->transporte->reboque as $reboque) {
                $make->tagreboque(StdClassBuilder::create([
                    'placa' => $reboque['placa'] ?? '',
                    'UF' => $reboque['uf'] ?? '',
                    'RNTC' => $reboque['rntc'] ?? null,
                ]));
            }
        }
        
        // Se tiver volumes, adicionar
        if ($this->transporte->volumes) {
            foreach ($this->transporte->volumes as $index => $volume) {
                $item = is_int($index) ? $index + 1 : 1;
                $make->tagvol(StdClassBuilder::create([
                    'item' => $item,
                    'qVol' => $volume['qVol'] ?? null,
                    'esp' => $volume['esp'] ?? null,
                    'marca' => $volume['marca'] ?? null,
                    'nVol' => $volume['nVol'] ?? null,
                    'pesoL' => $volume['pesoL'] ?? null,
                    'pesoB' => $volume['pesoB'] ?? null,
                ]));

                foreach (($volume['lacres'] ?? []) as $lacre) {
                    $make->taglacres(StdClassBuilder::create([
                        'item' => $item,
                        'nLacre' => is_array($lacre) ? ($lacre['nLacre'] ?? null) : $lacre,
                    ]));
                }
            }
        }

        if ($this->transporte->lacres) {
            foreach ($this->transporte->lacres as $lacre) {
                $make->taglacres(StdClassBuilder::create([
                    'item' => 1,
                    'nLacre' => is_array($lacre) ? ($lacre['nLacre'] ?? null) : $lacre,
                ]));
            }
        }
    }
    
    /**
     * Retorna o DTO encapsulado
     */
    public function getTransporte(): TransporteDTO
    {
        return $this->transporte;
    }
}
