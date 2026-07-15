<?php

namespace sabbajohn\FiscalCore\Adapters\NF\Core;

use NFePHP\NFe\Make;
use sabbajohn\FiscalCore\Adapters\NF\Nodes\IdentificacaoNode;
use sabbajohn\FiscalCore\Support\ConfigManager;
use sabbajohn\FiscalCore\Support\NFeCompatibility;

/**
 * Composite Root - Representa uma NFe/NFCe completa
 * Agrega todos os nodes da estrutura hierárquica
 */
class NotaFiscal
{
    /** @var NotaNodeInterface[] */
    private array $nodes = [];

    private ?Make $make = null;

    private ?string $xmlVersion = null;

    private ?string $schema = null;

    public function __construct(?string $xmlVersion = null, ?string $schema = null)
    {
        $this->xmlVersion = $xmlVersion;
        $this->schema = $schema;
    }

    public function setLayout(?string $xmlVersion = null, ?string $schema = null): self
    {
        if ($this->make !== null) {
            throw new \LogicException('Layout da nota nao pode ser alterado depois da criacao do Make');
        }

        $this->xmlVersion = $xmlVersion;
        $this->schema = $schema;

        return $this;
    }

    /**
     * Adiciona um node à nota
     */
    public function addNode(NotaNodeInterface $node): self
    {
        $tipo = $node->getNodeType();

        // Nodes repetíveis (itens) precisam ser acumulados para não sobrescrever.
        if (in_array($tipo, ['produto', 'imposto', 'imposto_seletivo', 'ibs_cbs'], true)) {
            if (! isset($this->nodes[$tipo])) {
                $this->nodes[$tipo] = [];
            }
            if (! is_array($this->nodes[$tipo])) {
                $this->nodes[$tipo] = [$this->nodes[$tipo]];
            }
            $this->nodes[$tipo][] = $node;

            return $this;
        }

        $this->nodes[$tipo] = $node;

        return $this;
    }

    /**
     * Valida todos os nodes da nota
     */
    public function validate(): bool
    {
        foreach ($this->nodes as $node) {
            if (is_array($node)) {
                foreach ($node as $item) {
                    if ($item instanceof NotaNodeInterface) {
                        $item->validate();
                    }
                }

                continue;
            }

            if ($node instanceof NotaNodeInterface) {
                $node->validate();
            }
        }

        // Validações estruturais
        if (! isset($this->nodes['identificacao'])) {
            throw new \InvalidArgumentException('Identificação é obrigatória');
        }

        if (! isset($this->nodes['emitente'])) {
            throw new \InvalidArgumentException('Emitente é obrigatório');
        }

        return true;
    }

    /**
     * Constrói o objeto Make do NFePHP com todos os nodes
     * Adiciona todos os nodes na ordem correta exigida pelo NFePHP
     */
    public function toMake(): Make
    {
        $this->validate();

        if ($this->make === null) {
            $this->make = NFeCompatibility::createMake($this->resolveSchema());

            // PASSO 0: Criar tag <infNFe> com chave da nota (OBRIGATÓRIO PRIMEIRO!)
            // O NFePHP precisa dessa tag antes de qualquer outra
            if (isset($this->nodes['identificacao'])) {
                // Criar tag infNFe
                // Deixar o NFePHP gerar a chave automaticamente passando null
                // Ele irá gerar baseado nos dados da tagide()
                $infNFe = new \stdClass;
                $infNFe->versao = $this->resolveXmlVersion();

                $this->make->taginfNFe($infNFe);
            }

            // ORDEM OBRIGATÓRIA DO NFEPHP:
            // 1. Identificação (ide)
            // 2. Emitente (emit + enderEmit)
            // 3. Destinatário (dest + enderDest) - opcional
            // 4. Produtos (det[] -> prod + imposto)
            // 5. Totais (total -> ICMSTot)
            // 6. Transporte (transp)
            // 7. Cobrança (cobr) - opcional
            // 8. Pagamento (pag)
            // 9. Informações Adicionais (infAdic) - opcional
            // 10. Informações Suplementares (infNFeSupl) - NFC-e
            // 11. Responsável Técnico (infRespTec) - opcional

            $ordem = [
                'identificacao',
                'emitente',
                'destinatario',
                'produto',      // Array de produtos
                'imposto',      // Array de impostos (vinculado aos produtos)
                'imposto_seletivo',
                'ibs_cbs',      // Array de IBS/CBS por item
                'totais',
                'transporte',
                'cobranca',
                'pagamento',
                'infoAdicional',
                'infoSuplementar',
                'responsavelTecnico',
            ];

            foreach ($ordem as $tipo) {
                if (! isset($this->nodes[$tipo])) {
                    continue;
                }

                $node = $this->nodes[$tipo];

                // Produtos e impostos são arrays
                if (is_array($node)) {
                    foreach ($node as $item) {
                        $item->addToMake($this->make);
                    }
                } else {
                    $node->addToMake($this->make);
                }
            }
        }

        return $this->make;
    }

    /**
     * Gera chave de acesso da NFe (44 dígitos)
     * Formato: cUF(2) + AAMM(4) + CNPJ(14) + mod(2) + serie(3) + nNF(9) + tpEmis(1) + cNF(8) + DV(1)
     *
     * @param  object  $idDTO  DTO de identificação
     * @return string Chave de 44 dígitos
     */
    private function gerarChaveAcesso($idDTO): string
    {
        // Se já tem chave, usar ela
        if (! empty($idDTO->chNFe)) {
            return preg_replace('/[^0-9]/', '', $idDTO->chNFe);
        }

        // Pegar CNPJ do emitente
        $emitenteNode = $this->nodes['emitente'] ?? null;
        $cnpj = '00000000000000';

        if ($emitenteNode) {
            $reflection = new \ReflectionClass($emitenteNode);
            $dtoProp = $reflection->getProperty('dto');
            $dtoProp->setAccessible(true);
            $emitenteDTO = $dtoProp->getValue($emitenteNode);
            $cnpj = preg_replace('/[^0-9]/', '', $emitenteDTO->cnpj);
        }

        // Extrair AAMM da data de emissão
        $dhEmi = $idDTO->dhEmi;
        $aamm = date('ym', strtotime($dhEmi));

        // Montar chave (43 primeiros dígitos)
        $chave43 = sprintf(
            '%02d%04s%014s%02d%03d%09d%01d%08d',
            $idDTO->cUF,
            $aamm,
            $cnpj,
            $idDTO->mod,
            $idDTO->serie,
            $idDTO->nNF,
            $idDTO->tpEmis ?? 1,
            $idDTO->cNF ?? rand(1, 99999999)
        );

        // Calcular dígito verificador
        $dv = $this->calcularDV($chave43);

        return $chave43.$dv;
    }

    /**
     * Calcula dígito verificador da chave usando módulo 11
     */
    private function calcularDV(string $chave): int
    {
        $multiplicadores = [4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $soma = 0;

        for ($i = 0; $i < 43; $i++) {
            $soma += ((int) $chave[$i]) * $multiplicadores[$i];
        }

        $resto = $soma % 11;

        return ($resto == 0 || $resto == 1) ? 0 : (11 - $resto);
    }

    /**
     * Gera o XML completo da NFe/NFCe
     *
     * Este método orquestra a geração do XML chamando o NFePHP na ordem correta:
     * 1. Cria a tag <infNFe> com a chave da nota
     * 2. Adiciona todos os nodes via toMake()
     * 3. Monta e retorna o XML final
     *
     * @return string XML completo da nota fiscal
     *
     * @throws \InvalidArgumentException Se faltar dados obrigatórios
     * @throws \RuntimeException Se houver erro na geração do XML
     */
    public function toXml(): string
    {
        $make = $this->toMake();

        // Verificar erros do Make antes de gerar XML
        $errors = $make->getErrors();
        if (! empty($errors)) {
            $errorMsg = "Erros no Make do NFePHP:\n".implode("\n", $errors);
            throw new \RuntimeException($errorMsg);
        }

        // Gerar XML usando método do NFePHP
        try {
            return $make->getXML();
        } catch (\Exception $e) {
            throw new \RuntimeException(
                'Erro ao gerar XML da nota fiscal: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Retorna o objeto Make (para operações adicionais)
     */
    public function getMake(): Make
    {
        return $this->toMake();
    }

    /**
     * Retorna todos os nodes da nota
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    /**
     * Verifica se um node específico existe
     */
    public function hasNode(string $tipo): bool
    {
        return isset($this->nodes[$tipo]);
    }

    private function resolveXmlVersion(): string
    {
        if ($this->xmlVersion !== null && trim($this->xmlVersion) !== '') {
            return NFeCompatibility::xmlVersion($this->xmlVersion);
        }

        $config = ConfigManager::getInstance();

        return NFeCompatibility::xmlVersionForModel(
            $this->documentModel(),
            (string) $config->get('versao_nfe'),
            (string) $config->get('versao_nfce')
        );
    }

    private function resolveSchema(): string
    {
        if ($this->schema !== null && trim($this->schema) !== '') {
            return NFeCompatibility::schema($this->schema);
        }

        if (isset($this->nodes['ibs_cbs']) || isset($this->nodes['imposto_seletivo'])) {
            return NFeCompatibility::schema('IBSCBS');
        }

        $config = ConfigManager::getInstance();
        $schema = $this->documentModel() === 65
            ? ($config->get('schema_nfce') ?: $config->get('schemas'))
            : ($config->get('schema_nfe') ?: $config->get('schemas'));

        return NFeCompatibility::schema((string) $schema);
    }

    private function documentModel(): int
    {
        $node = $this->nodes['identificacao'] ?? null;

        if ($node instanceof IdentificacaoNode) {
            return $node->getDto()->mod;
        }

        return 55;
    }
}
