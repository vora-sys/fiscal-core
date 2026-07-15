<?php

namespace sabbajohn\FiscalCore\Adapters\NF\Nodes;

use NFePHP\NFe\Make;
use sabbajohn\FiscalCore\Adapters\NF\Core\NotaNodeInterface;
use sabbajohn\FiscalCore\Adapters\NF\DTO\EmitenteDTO;

/**
 * Node para tag <emit> (Emitente)
 */
class EmitenteNode implements NotaNodeInterface
{
    public function __construct(private EmitenteDTO $dto) {}

    public function addToMake(Make $make): void
    {
        $emit = (object) [
            'CNPJ' => $this->dto->cnpj,
            'xNome' => $this->dto->razaoSocial,
            'xFant' => $this->dto->nomeFantasia,
            'IE' => $this->dto->inscricaoEstadual,
            'IM' => $this->dto->inscricaoMunicipal,
            'CNAE' => $this->dto->cnae,
            'CRT' => $this->dto->crt,
        ];
        $make->tagemit($emit);

        $enderEmit = (object) [
            'xLgr' => $this->dto->logradouro,
            'nro' => $this->dto->numero,
            'xCpl' => $this->dto->complemento,
            'xBairro' => $this->dto->bairro,
            'cMun' => $this->dto->codigoMunicipio,
            'xMun' => $this->dto->nomeMunicipio,
            'UF' => $this->dto->uf,
            'CEP' => $this->dto->cep,
            'cPais' => $this->dto->codigoPais,
            'xPais' => $this->dto->nomePais,
            'fone' => $this->dto->telefone ?? '',
        ];
        $make->tagenderEmit($enderEmit);
    }

    public function validate(): bool
    {
        // Validação CNPJ (14 dígitos)
        if (! preg_match('/^\d{14}$/', $this->dto->cnpj)) {
            throw new \InvalidArgumentException('CNPJ inválido');
        }

        if (empty($this->dto->razaoSocial)) {
            throw new \InvalidArgumentException('Razão social é obrigatória');
        }

        if (empty($this->dto->inscricaoEstadual)) {
            throw new \InvalidArgumentException('Inscrição estadual é obrigatória');
        }

        if (! in_array($this->dto->crt, [1, 2, 3])) {
            throw new \InvalidArgumentException('CRT inválido (deve ser 1, 2 ou 3)');
        }

        return true;
    }

    public function getNodeType(): string
    {
        return 'emitente';
    }
}
