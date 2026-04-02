<?php

namespace freeline\FiscalCore\Adapters\NF\NFCe;

use freeline\FiscalCore\Contracts\NotaFiscalInterface;
use freeline\FiscalCore\Adapters\NF\Builder\NotaFiscalBuilder;
use freeline\FiscalCore\Adapters\NF\Core\NotaFiscal;
use NFePHP\NFe\Tools;

/**
 * Adapter para NFCe (modelo 65)
 * Integrado com sistema Composite + Builder
 * Requer configuração de CSC/CSRT no Tools
 */
class NFCeAdapter implements NotaFiscalInterface
{
	private Tools $tools;

	public function __construct(Tools $tools)
	{
		$this->tools = $tools;
	}

	/**
	 * Emite uma NFCe a partir de array de dados
	 * Usa o Builder para construir a nota de forma type-safe
	 * 
	 * @param array $dados Dados da nota fiscal de consumidor
	 * @return string Resposta da SEFAZ (XML do protocolo)
	 * @throws \Exception Se houver erro na construção ou envio
	 */
	public function emitir(array $dados): string
	{
		// Garante que é modelo 65 (NFCe)
		if (!isset($dados['identificacao']['mod'])) {
			$dados['identificacao']['mod'] = 65;
		}

		// Constrói a nota usando o Builder
		$nota = NotaFiscalBuilder::fromArray($dados)->build();

		// Gera o XML uma única vez; toXml() já valida a nota e o Make internamente.
		$xml = $nota->toXml();
		
		// Assina o XML
		$xmlAssinado = $this->tools->signNFe($xml);
		
		// Para NFCe, precisa adicionar QR Code antes do envio
		// O Tools deve estar configurado com CSC/CSRT
		
		// Envia para SEFAZ
		return $this->tools->sefazEnviaLote([$xmlAssinado]);
	}

	/**
	 * Construtor fluente para NFCe
	 * Retorna NotaFiscalBuilder para construção incremental
	 */
	public static function builder(): NotaFiscalBuilder
	{
		return new NotaFiscalBuilder();
	}

	/**
	 * Cria NFCe a partir de array e retorna o objeto NotaFiscal
	 * Útil para manipulação antes do envio
	 */
	public function criarNota(array $dados): NotaFiscal
	{
		if (!isset($dados['identificacao']['mod'])) {
			$dados['identificacao']['mod'] = 65;
		}
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

	public function sefazStatus(string $uf = '', ?int $ambiente = null, bool $ignorarContigencia = true): string
	{
		return $this->tools->sefazStatus($uf, $ambiente, $ignorarContigencia);
	}

	public function consultaNotasEmitidasParaEstabelecimento(int $ultimoNsu=0, int $numNSU=0, ?string $chave=null, string $fonte='AN'): string
    {
        return $this->tools->sefazDistDFe($ultimoNsu, $numNSU, $chave, $fonte);
    }
}
