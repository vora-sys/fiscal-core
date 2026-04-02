<?php

namespace freeline\FiscalCore\Adapters\NF;

use freeline\FiscalCore\Contracts\NotaFiscalInterface;
use freeline\FiscalCore\Adapters\NF\Builder\NotaFiscalBuilder;
use freeline\FiscalCore\Adapters\NF\Core\NotaFiscal;
use freeline\FiscalCore\Support\ManifestationType;
use NFePHP\NFe\Tools;

/**
 * Adapter para NFe (modelo 55)
 * Integrado com sistema Composite + Builder
 */
class NFeAdapter implements NotaFiscalInterface
{
    private Tools $tools;

    public function __construct(Tools $tools)
    {
        $this->tools = $tools;
    }

    /**
     * Emite uma NFe a partir de array de dados
     * Usa o Builder para construir a nota de forma type-safe
     * 
     * @param array $dados Dados da nota fiscal
     * @return string Resposta da SEFAZ (XML do protocolo)
     * @throws \Exception Se houver erro na construção ou envio
     */
    public function emitir(array $dados): string
    {
        // Constrói a nota usando o Builder
        $nota = NotaFiscalBuilder::fromArray($dados)->build();

        // Gera o XML uma única vez; toXml() já valida a nota e o Make internamente.
        $xml = $nota->toXml();
        
        // Assina o XML
        $xmlAssinado = $this->tools->signNFe($xml);
        
        // Envia para SEFAZ
        return $this->tools->sefazEnviaLote([$xmlAssinado]);
    }

    /**
     * Construtor fluente para NFe
     * Retorna NotaFiscalBuilder para construção incremental
     */
    public static function builder(): NotaFiscalBuilder
    {
        return new NotaFiscalBuilder();
    }

    /**
     * Cria NFe a partir de array e retorna o objeto NotaFiscal
     * Útil para manipulação antes do envio
     */
    public function criarNota(array $dados): NotaFiscal
    {
        return NotaFiscalBuilder::fromArray($dados)->build();
    }

    public function consultar(string $chave): string
    {
        return $this->tools->sefazConsultaChave($chave);
    }

    public function cancelar(string $chave, string $motivo, string $protocolo): string
    {
        return $this->tools->sefazCancela($chave, $motivo, $protocolo);
    }

    public function inutilizar(int $ano, int $cnpj, int $modelo, int $serie, int $numeroInicial, int $numeroFinal, string $justificativa): string
    {
        // sped-nfe v5 usa (serie, numeroInicial, numeroFinal, justificativa, tpAmb, ano[2])
        $ano2Digitos = str_pad((string) ($ano % 100), 2, '0', STR_PAD_LEFT);
        return $this->tools->sefazInutiliza($serie, $numeroInicial, $numeroFinal, $justificativa, null, $ano2Digitos);
    }

    /**
     * Consulta notas emitidas para estabelecimento(Notas de entrada)
     * @param int $ultimoNsu
     * @param int $numNSU
     * @param string|null $chave
     * @param string $fonte
     * @return string
     */
    public function consultaNotasEmitidasParaEstabelecimento(int $ultimoNsu=0, int $numNSU=0, ?string $chave=null, string $fonte='AN'): string
    {
        return $this->tools->sefazDistDFe($ultimoNsu, $numNSU, $chave, $fonte);
    }

    public function manifestarDestinatario(
        string $chave,
        ManifestationType|string $tipo,
        string $justificativa = '',
        int $sequencia = 1
    ): string {
        $manifestationType = is_string($tipo) ? ManifestationType::fromValue($tipo) : $tipo;

        if ($manifestationType->requiresJustification() && mb_strlen(trim($justificativa)) < 15) {
            throw new \InvalidArgumentException('Justificativa deve ter pelo menos 15 caracteres para operação não realizada');
        }

        return $this->tools->sefazManifesta(
            $chave,
            $manifestationType->eventCode(),
            $justificativa,
            $sequencia
        );
    }

    public function downloadNFe(string $chave): string
    {
        return $this->tools->sefazDownload($chave);
    }

    public function sefazStatus(string $uf = '', ?int $ambiente = null, bool $ignorarContigencia = true): string
    {
        return $this->tools->sefazStatus($uf, $ambiente, $ignorarContigencia);
    }
}
