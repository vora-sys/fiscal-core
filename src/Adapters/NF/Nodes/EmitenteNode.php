<?php

namespace sabbajohn\FiscalCore\Adapters\NF\Nodes;

use sabbajohn\FiscalCore\Adapters\NF\Core\NotaNodeInterface;
use sabbajohn\FiscalCore\Adapters\NF\DTO\EmitenteDTO;
use NFePHP\NFe\Make;

/**
 * Node para tag <emit> (Emitente)
 */
class EmitenteNode implements NotaNodeInterface
{
    public function __construct(private EmitenteDTO $dto) {}

    public function addToMake(Make $make): void
    {
        $emit = (object) [
            'xNome' => mb_substr(trim($this->dto->razaoSocial), 0, 60),
            'xFant' => mb_substr(trim($this->dto->nomeFantasia), 0, 60),
            'IE' => $this->dto->inscricaoEstadual,
            'IM' => $this->dto->inscricaoMunicipal,
            'CNAE' => $this->dto->cnae,
            'CRT' => $this->dto->crt,
        ];
        $document = preg_replace('/\D+/', '', $this->dto->cnpj) ?? '';
        if (strlen($document) === 11) {
            $emit->CPF = $document;
        } else {
            $emit->CNPJ = $document;
        }
        $make->tagemit($emit);

        $enderEmit = (object) [
            'xLgr' => mb_substr(trim($this->dto->logradouro), 0, 60),
            'nro' => $this->dto->numero,
            'xCpl' => mb_substr(trim($this->dto->complemento), 0, 60),
            'xBairro' => $this->dto->bairro,
            'cMun' => $this->dto->codigoMunicipio,
            'xMun' => mb_substr(trim($this->dto->nomeMunicipio), 0, 60),
            'UF' => $this->dto->uf,
            'CEP' => $this->dto->cep,
            'cPais' => $this->dto->codigoPais,
            'xPais' => mb_substr(trim($this->dto->nomePais), 0, 60),
            'fone' => $this->dto->telefone ?? '',
        ];
        $make->tagenderEmit($enderEmit);
    }

    public function validate(): bool
    {
        if (! preg_match('/^\d{11}(\d{3})?$/', preg_replace('/\D+/', '', $this->dto->cnpj) ?? '')) {
            throw new \InvalidArgumentException('CPF ou CNPJ do emitente inválido');
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
