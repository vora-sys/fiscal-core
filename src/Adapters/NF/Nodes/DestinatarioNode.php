<?php

namespace sabbajohn\FiscalCore\Adapters\NF\Nodes;

use sabbajohn\FiscalCore\Adapters\NF\Core\NotaNodeInterface;
use sabbajohn\FiscalCore\Adapters\NF\DTO\DestinatarioDTO;
use NFePHP\NFe\Make;

/**
 * Node para tag <dest> (Destinatário)
 */
class DestinatarioNode implements NotaNodeInterface
{
    public function __construct(private DestinatarioDTO $dto) {}

    public function addToMake(Make $make): void
    {
        $dest = [
            'xNome' => mb_substr(trim($this->dto->nome), 0, 60),
            'indIEDest' => $this->dto->indIEDest,
        ];

        // CPF ou CNPJ
        if (strlen($this->dto->cpfCnpj) === 11) {
            $dest['CPF'] = $this->dto->cpfCnpj;
        } else {
            $dest['CNPJ'] = $this->dto->cpfCnpj;
        }

        if (
            $this->dto->indIEDest === 1
            && $this->dto->inscricaoEstadual !== null
            && $this->dto->inscricaoEstadual !== ''
        ) {
            $dest['IE'] = $this->dto->inscricaoEstadual;
        }

        if ($this->dto->email) {
            $dest['email'] = $this->dto->email;
        }

        $make->tagdest((object) $dest);

        // Endereço do destinatário é uma tag separada em NFePHP: <enderDest>.
        if ($this->dto->logradouro) {
            $enderDest = [
                'xLgr' => mb_substr(trim($this->dto->logradouro), 0, 60),
                'nro' => $this->dto->numero,
                'xCpl' => $this->dto->complemento,
                'xBairro' => $this->dto->bairro,
                'cMun' => $this->dto->codigoMunicipio,
                'xMun' => mb_substr(trim($this->dto->nomeMunicipio), 0, 60),
                'UF' => $this->dto->uf,
                'CEP' => $this->dto->cep,
                'cPais' => $this->dto->codigoPais,
                'xPais' => mb_substr(trim($this->dto->nomePais), 0, 60),
                'fone' => $this->dto->telefone,
            ];
            $make->tagenderDest((object) $enderDest);
        }
    }

    public function validate(): bool
    {
        // Validação CPF (11) ou CNPJ (14)
        $len = strlen($this->dto->cpfCnpj);
        if (! in_array($len, [11, 14])) {
            throw new \InvalidArgumentException('CPF/CNPJ inválido');
        }

        if (empty($this->dto->nome)) {
            throw new \InvalidArgumentException('Nome do destinatário é obrigatório');
        }

        // indIEDest: 1=Contribuinte, 2=Isento, 9=Não contribuinte
        if (! in_array($this->dto->indIEDest, [1, 2, 9])) {
            throw new \InvalidArgumentException('indIEDest inválido');
        }

        if ($this->dto->indIEDest === 1 && empty($this->dto->inscricaoEstadual)) {
            throw new \InvalidArgumentException('IE do destinatário é obrigatória quando indIEDest=1');
        }

        if ($this->dto->indIEDest !== 1 && ! empty($this->dto->inscricaoEstadual)) {
            throw new \InvalidArgumentException('IE do destinatário só pode ser informada quando indIEDest=1');
        }

        return true;
    }

    public function getNodeType(): string
    {
        return 'destinatario';
    }
}
