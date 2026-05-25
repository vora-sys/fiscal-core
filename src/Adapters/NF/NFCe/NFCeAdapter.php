<?php

namespace sabbajohn\FiscalCore\Adapters\NF\NFCe;

use sabbajohn\FiscalCore\Contracts\NotaFiscalInterface;
use sabbajohn\FiscalCore\Adapters\NF\Builder\NotaFiscalBuilder;
use sabbajohn\FiscalCore\Adapters\NF\Core\NotaFiscal;
use NFePHP\NFe\Tools;

/**
 * Adapter para NFCe (modelo 65)
 * Integrado com sistema Composite + Builder
 * Requer configuração de CSC/CSRT no Tools
 */
class NFCeAdapter implements NotaFiscalInterface
{
	private Tools $tools;
	private ?string $lastSignedXml = null;
	private ?string $lastResponseXml = null;

	public function __construct(Tools $tools)
	{
		$this->tools = $tools;
	}

	/**
	 * Emite uma NFCe a partir de array de dados
	 * Usa o Builder para construir a nota de forma type-safe
	 * 
	 * @param array $dados Dados da nota fiscal de consumidor
	 * @return string XML de retorno da SEFAZ
	 * @throws \Exception Se houver erro na construção ou envio
	 */
	public function emitir(array $dados): string
	{
		$this->tools->model(65);

		// Garante que é modelo 65 (NFCe)
		if (!isset($dados['identificacao']['mod'])) {
			$dados['identificacao']['mod'] = 65;
		}

		$dados = $this->removeSupplementalInfoForAutomaticQRCode($dados);

		// Constrói a nota usando o Builder
		$nota = NotaFiscalBuilder::fromArray($dados)->build();

		// Gera o XML uma única vez; toXml() já valida a nota e o Make internamente.
		$xml = $nota->toXml();
		
		// Assina o XML
		$xmlAssinado = $this->tools->signNFe($xml);

		$lote = is_array($dados['lote'] ?? null) ? $dados['lote'] : [];
		$idLote = preg_replace('/\D/', '', (string) ($lote['idLote'] ?? '')) ?: '1';
		$indSinc = (int) ($lote['indSinc'] ?? 1);
		if (!in_array($indSinc, [0, 1], true)) {
			$indSinc = 1;
		}
		
		// Para NFCe, signNFe() adiciona o QR Code quando o Tools tem CSC/CSCid.
		
		// Envia para SEFAZ
		$this->lastSignedXml = $xmlAssinado;
		$this->lastResponseXml = $this->tools->sefazEnviaLote([$xmlAssinado], $idLote, $indSinc);

		return $this->lastResponseXml;
	}

	public function getLastSignedXml(): ?string
	{
		return $this->lastSignedXml;
	}

	public function getLastResponseXml(): ?string
	{
		return $this->lastResponseXml;
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
		$this->tools->model(65);

		if (!isset($dados['identificacao']['mod'])) {
			$dados['identificacao']['mod'] = 65;
		}
		return NotaFiscalBuilder::fromArray($dados)->build();
	}

	public function consultar(string $chave): string
	{
		$this->tools->model(65);

		return $this->tools->sefazConsultaChave($chave);
	}

	public function cancelar(string $chave, string $motivo, string $protocolo): string
	{
		$this->tools->model(65);

		return $this->tools->sefazCancela($chave, $motivo, $protocolo);
	}

	public function inutilizar(int $ano, int $cnpj, int $modelo, int $serie, int $numeroInicial, int $numeroFinal, string $justificativa): string
	{
		$this->tools->model(65);

		// sped-nfe v5 usa (serie, numeroInicial, numeroFinal, justificativa, tpAmb, ano[2])
		$ano2Digitos = str_pad((string) ($ano % 100), 2, '0', STR_PAD_LEFT);
		return $this->tools->sefazInutiliza($serie, $numeroInicial, $numeroFinal, $justificativa, null, $ano2Digitos);
	}

	public function sefazStatus(string $uf = '', ?int $ambiente = null, bool $ignorarContigencia = true): string
	{
		$this->tools->model(65);

		return $this->tools->sefazStatus($uf, $ambiente, $ignorarContigencia);
	}

	public function consultaNotasEmitidasParaEstabelecimento(int $ultimoNsu=0, int $numNSU=0, ?string $chave=null, string $fonte='AN'): string
    {
        return $this->tools->sefazDistDFe($ultimoNsu, $numNSU, $chave, $fonte);
    }

	/**
	 * Para NFC-e, a NFePHP gera a tag infNFeSupl no signNFe().
	 * Qualquer valor preexistente impediria o recálculo canônico do QR Code.
	 */
	private function removeSupplementalInfoForAutomaticQRCode(array $dados): array
	{
		if ((int) ($dados['identificacao']['mod'] ?? 65) !== 65) {
			return $dados;
		}

		unset($dados['infoSuplementar']);

		return $dados;
	}
}
