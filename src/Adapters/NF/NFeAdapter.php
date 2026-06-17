<?php

namespace sabbajohn\FiscalCore\Adapters\NF;

use sabbajohn\FiscalCore\Contracts\NotaFiscalInterface;
use sabbajohn\FiscalCore\Adapters\NF\Builder\NotaFiscalBuilder;
use sabbajohn\FiscalCore\Adapters\NF\Core\NotaFiscal;
use sabbajohn\FiscalCore\Support\ManifestationType;
use sabbajohn\FiscalCore\Support\SefazAdvancedMethodRegistry;
use NFePHP\NFe\Tools;

/**
 * Adapter para NFe (modelo 55)
 * Integrado com sistema Composite + Builder
 */
class NFeAdapter implements NotaFiscalInterface
{
    private Tools $tools;
    private ?string $lastSignedXml = null;
    private ?string $lastResponseXml = null;

    public function __construct(Tools $tools)
    {
        $this->tools = $tools;
    }

    /**
     * Emite uma NFe a partir de array de dados
     * Usa o Builder para construir a nota de forma type-safe
     * 
     * @param array $dados Dados da nota fiscal
     * @return string XML de retorno da SEFAZ
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

        $lote = is_array($dados['lote'] ?? null) ? $dados['lote'] : [];
        $idLote = preg_replace('/\D/', '', (string) ($lote['idLote'] ?? '')) ?: '1';
        $indSinc = (int) ($lote['indSinc'] ?? 1);
        if (!in_array($indSinc, [0, 1], true)) {
            $indSinc = 1;
        }
        
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

    public function getLastRequestXml(): ?string
    {
        return $this->tools->lastRequest !== '' ? $this->tools->lastRequest : null;
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
        return $this->captureResponse($this->tools->sefazConsultaChave($chave));
    }

    public function cancelar(string $chave, string $motivo, string $protocolo): string
    {
        return $this->captureResponse($this->tools->sefazCancela($chave, $motivo, $protocolo));
    }

    public function inutilizar(int $ano, int $cnpj, int $modelo, int $serie, int $numeroInicial, int $numeroFinal, string $justificativa): string
    {
        // sped-nfe v5 usa (serie, numeroInicial, numeroFinal, justificativa, tpAmb, ano[2])
        $ano2Digitos = str_pad((string) ($ano % 100), 2, '0', STR_PAD_LEFT);
        return $this->captureResponse(
            $this->tools->sefazInutiliza($serie, $numeroInicial, $numeroFinal, $justificativa, null, $ano2Digitos)
        );
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
        return $this->captureResponse($this->tools->sefazDistDFe($ultimoNsu, $numNSU, $chave, $fonte));
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

        return $this->captureResponse($this->tools->sefazManifesta(
            $chave,
            $manifestationType->eventCode(),
            $justificativa,
            $sequencia
        ));
    }

    public function downloadNFe(string $chave): string
    {
        return $this->captureResponse($this->tools->sefazDownload($chave));
    }

    public function sefazStatus(string $uf = '', ?int $ambiente = null, bool $ignorarContigencia = true): string
    {
        return $this->captureResponse($this->tools->sefazStatus($uf, $ambiente, $ignorarContigencia));
    }

    public function consultarRecibo(string $recibo, ?int $ambiente = null): string
    {
        return $this->captureResponse($this->tools->sefazConsultaRecibo($recibo, $ambiente));
    }

    public function consultarCadastroContribuinte(
        string $uf,
        string $cnpj = '',
        string $iest = '',
        string $cpf = ''
    ): string {
        return $this->captureResponse($this->tools->sefazCadastro($uf, $cnpj, $iest, $cpf));
    }

    public function cartaCorrecao(
        string $chave,
        string $correcao,
        int $sequencia = 1,
        ?\DateTimeInterface $dhEvento = null,
        ?string $lote = null
    ): string {
        return $this->captureResponse($this->tools->sefazCCe($chave, $correcao, $sequencia, $dhEvento, $lote));
    }

    public function manifestarDestinatarioLote(
        array|\stdClass $eventos,
        ?\DateTimeInterface $dhEvento = null,
        ?string $lote = null
    ): string {
        return $this->captureResponse(
            $this->tools->sefazManifestaLote($this->buildManifestationBatch($eventos), $dhEvento, $lote)
        );
    }

    public function registrarEventoSefaz(
        string $uf,
        string $chave,
        int $tipoEvento,
        int $sequencia = 1,
        string $tagAdicional = '',
        ?\DateTimeInterface $dhEvento = null,
        ?string $lote = null
    ): string {
        return $this->captureResponse(
            $this->tools->sefazEvento($uf, $chave, $tipoEvento, $sequencia, $tagAdicional, $dhEvento, $lote)
        );
    }

    public function registrarEventoSefazLote(
        string $uf,
        array|\stdClass $eventos,
        ?\DateTimeInterface $dhEvento = null,
        ?string $lote = null
    ): string {
        return $this->captureResponse(
            $this->tools->sefazEventoLote($uf, $this->buildEventBatch($eventos), $dhEvento, $lote)
        );
    }

    public function registrarEventoAvancado(string $metodo, array|\stdClass $dados, array $opcoes = []): string
    {
        if (!SefazAdvancedMethodRegistry::isAllowedForModel($metodo, 55)) {
            throw new \InvalidArgumentException("Método SEFAZ não suportado para NFe: {$metodo}");
        }

        $std = $this->toStdClass($dados);
        $strategy = SefazAdvancedMethodRegistry::strategy($metodo);

        return $this->captureResponse(match ($strategy) {
            'std_event' => $this->tools->{$metodo}(
                $std,
                $opcoes['dhEvento'] ?? $opcoes['dh_evento'] ?? null,
                $opcoes['lote'] ?? null
            ),
            'std_ver_aplic' => $this->tools->{$metodo}($std, $opcoes['verAplic'] ?? $opcoes['ver_aplic'] ?? null),
            'epp' => $this->tools->sefazEPP(
                (string) ($std->chNFe ?? $std->chave ?? ''),
                (string) ($std->nProt ?? $std->protocolo ?? ''),
                is_array($std->itens ?? null) ? $std->itens : [],
                (int) ($std->tipo ?? 1),
                (int) ($std->nSeqEvento ?? $std->sequencia ?? 1),
                $opcoes['dhEvento'] ?? $opcoes['dh_evento'] ?? null,
                $opcoes['lote'] ?? null
            ),
            'ecpp' => $this->tools->sefazECPP(
                (string) ($std->chNFe ?? $std->chave ?? ''),
                (string) ($std->nProt ?? $std->protocolo ?? ''),
                (int) ($std->tipo ?? 1),
                (int) ($std->nSeqEvento ?? $std->sequencia ?? 1),
                $opcoes['dhEvento'] ?? $opcoes['dh_evento'] ?? null,
                $opcoes['lote'] ?? null
            ),
            default => throw new \InvalidArgumentException("Estratégia SEFAZ não suportada: {$metodo}"),
        });
    }

    /**
     * @return array{response_xml:string,xml:string}
     */
    public function registrarEpec(string $xml, ?string $verAplic = null): array
    {
        $xmlContingencia = $xml;
        $response = $this->tools->sefazEPEC($xmlContingencia, $verAplic);

        return [
            'response_xml' => $this->captureResponse($response),
            'xml' => $xmlContingencia,
        ];
    }

    public function validarXmlSchemaSefaz(string $xml): bool
    {
        return $this->tools->sefazValidate($xml);
    }

    private function captureResponse(string $response): string
    {
        $this->lastResponseXml = $response;

        return $response;
    }

    private function buildManifestationBatch(array|\stdClass $eventos): \stdClass
    {
        if ($eventos instanceof \stdClass) {
            return $eventos;
        }

        $std = new \stdClass();
        $std->evento = [];

        foreach ($eventos as $evento) {
            $item = $this->toStdClass($evento);
            $tipo = $item->tipo ?? $item->tpEvento ?? null;
            if (is_string($tipo) && !ctype_digit($tipo)) {
                $tipo = ManifestationType::fromValue($tipo)->eventCode();
            } elseif ($tipo instanceof ManifestationType) {
                $tipo = $tipo->eventCode();
            }

            $evt = new \stdClass();
            $evt->chNFe = (string) ($item->chNFe ?? $item->chave ?? '');
            $evt->tpEvento = (int) $tipo;
            $evt->nSeqEvento = (int) ($item->nSeqEvento ?? $item->sequencia ?? 1);
            $evt->xJust = (string) ($item->xJust ?? $item->justificativa ?? '');
            $std->evento[] = $evt;
        }

        return $std;
    }

    private function buildEventBatch(array|\stdClass $eventos): \stdClass
    {
        if ($eventos instanceof \stdClass) {
            return $eventos;
        }

        $std = new \stdClass();
        $std->evento = [];

        foreach ($eventos as $evento) {
            $item = $this->toStdClass($evento);
            $evt = new \stdClass();
            $evt->chave = (string) ($item->chave ?? $item->chNFe ?? '');
            $evt->tpEvento = (int) ($item->tpEvento ?? $item->tipo_evento ?? $item->tipoEvento ?? 0);
            $evt->nSeqEvento = (int) ($item->nSeqEvento ?? $item->sequencia ?? 1);
            $evt->tagAdic = (string) ($item->tagAdic ?? $item->tagAdicional ?? $item->tag_adicional ?? '');
            $std->evento[] = $evt;
        }

        return $std;
    }

    private function toStdClass(array|\stdClass $data): \stdClass
    {
        if ($data instanceof \stdClass) {
            return $data;
        }

        return json_decode(json_encode($data, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }
}
