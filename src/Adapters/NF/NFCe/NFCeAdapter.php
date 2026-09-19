<?php

namespace sabbajohn\FiscalCore\Adapters\NF\NFCe;

use sabbajohn\FiscalCore\Adapters\NF\Builder\NotaFiscalBuilder;
use sabbajohn\FiscalCore\Adapters\NF\Core\NotaFiscal;
use sabbajohn\FiscalCore\Contracts\NotaFiscalInterface;
use sabbajohn\FiscalCore\Support\SefazAdvancedMethodRegistry;
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

    /** @var array<string,float> */
    private array $lastEmissionMetrics = [];

    public function __construct(Tools $tools)
    {
        $this->tools = $tools;
    }

    /**
     * Emite uma NFCe a partir de array de dados
     * Usa o Builder para construir a nota de forma type-safe
     *
     * @param  array  $dados  Dados da nota fiscal de consumidor
     * @return string XML de retorno da SEFAZ
     *
     * @throws \Exception Se houver erro na construção ou envio
     */
    public function emitir(array $dados): string
    {
        $emissionStartedAt = hrtime(true);
        $this->lastEmissionMetrics = [];

        try {
            $this->tools->model(65);

            if (! isset($dados['identificacao']['mod'])) {
                $dados['identificacao']['mod'] = 65;
            }

            $dados = $this->removeSupplementalInfoForAutomaticQRCode($dados);

            $xmlStartedAt = hrtime(true);
            try {
                $nota = NotaFiscalBuilder::fromArray($dados)->build();
                $xml = $nota->toXml();
            } finally {
                $this->lastEmissionMetrics['xml_build_ms'] = $this->elapsedMilliseconds($xmlStartedAt);
            }

            $signatureStartedAt = hrtime(true);
            try {
                // Para NFCe, signNFe() também adiciona o QR Code quando há CSC/CSCid.
                $xmlAssinado = $this->tools->signNFe($xml);
            } finally {
                $this->lastEmissionMetrics['signature_ms'] = $this->elapsedMilliseconds($signatureStartedAt);
            }

            $lote = is_array($dados['lote'] ?? null) ? $dados['lote'] : [];
            $idLote = preg_replace('/\D/', '', (string) ($lote['idLote'] ?? '')) ?: '1';
            $indSinc = (int) ($lote['indSinc'] ?? 1);
            if (! in_array($indSinc, [0, 1], true)) {
                $indSinc = 1;
            }

            $this->lastSignedXml = $xmlAssinado;
            $transportStartedAt = hrtime(true);
            try {
                $this->lastResponseXml = $this->tools->sefazEnviaLote([$xmlAssinado], $idLote, $indSinc);
            } finally {
                $this->lastEmissionMetrics['transport_ms'] = $this->elapsedMilliseconds($transportStartedAt);
            }

            return $this->lastResponseXml;
        } finally {
            $this->lastEmissionMetrics['provider_total_ms'] = $this->elapsedMilliseconds($emissionStartedAt);
        }
    }

    public function getLastSignedXml(): ?string
    {
        return $this->lastSignedXml;
    }

    public function getLastResponseXml(): ?string
    {
        return $this->lastResponseXml;
    }

    /** @return array<string,float> */
    public function getLastEmissionMetrics(): array
    {
        return $this->lastEmissionMetrics;
    }

    public function getLastRequestXml(): ?string
    {
        return $this->tools->lastRequest !== '' ? $this->tools->lastRequest : null;
    }

    private function elapsedMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 2);
    }

    /**
     * Construtor fluente para NFCe
     * Retorna NotaFiscalBuilder para construção incremental
     */
    public static function builder(): NotaFiscalBuilder
    {
        return new NotaFiscalBuilder;
    }

    /**
     * Cria NFCe a partir de array e retorna o objeto NotaFiscal
     * Útil para manipulação antes do envio
     */
    public function criarNota(array $dados): NotaFiscal
    {
        $this->tools->model(65);

        if (! isset($dados['identificacao']['mod'])) {
            $dados['identificacao']['mod'] = 65;
        }

        return NotaFiscalBuilder::fromArray($dados)->build();
    }

    public function consultar(string $chave): string
    {
        $this->tools->model(65);

        return $this->captureResponse($this->tools->sefazConsultaChave($chave));
    }

    public function cancelar(string $chave, string $motivo, string $protocolo): string
    {
        $this->tools->model(65);

        return $this->captureResponse($this->tools->sefazCancela($chave, $motivo, $protocolo));
    }

    public function cancelarPorSubstituicao(
        string $chave,
        string $motivo,
        string $protocolo,
        string $chaveSubstituta,
        ?string $verAplic = null,
        ?\DateTimeInterface $dhEvento = null,
        ?string $lote = null
    ): string {
        $this->tools->model(65);

        return $this->captureResponse(
            $this->tools->sefazCancelaPorSubstituicao(
                $chave,
                $motivo,
                $protocolo,
                $chaveSubstituta,
                $verAplic,
                $dhEvento,
                $lote
            )
        );
    }

    public function inutilizar(int $ano, int $cnpj, int $modelo, int $serie, int $numeroInicial, int $numeroFinal, string $justificativa): string
    {
        $this->tools->model(65);

        // sped-nfe v5 usa (serie, numeroInicial, numeroFinal, justificativa, tpAmb, ano[2])
        $ano2Digitos = str_pad((string) ($ano % 100), 2, '0', STR_PAD_LEFT);

        return $this->captureResponse(
            $this->tools->sefazInutiliza($serie, $numeroInicial, $numeroFinal, $justificativa, null, $ano2Digitos)
        );
    }

    public function sefazStatus(string $uf = '', ?int $ambiente = null, bool $ignorarContigencia = true): string
    {
        $this->tools->model(65);

        return $this->captureResponse($this->tools->sefazStatus($uf, $ambiente, $ignorarContigencia));
    }

    public function consultaNotasEmitidasParaEstabelecimento(int $ultimoNsu = 0, int $numNSU = 0, ?string $chave = null, string $fonte = 'AN'): string
    {
        $this->tools->model(65);

        return $this->captureResponse($this->tools->sefazDistDFe($ultimoNsu, $numNSU, $chave, $fonte));
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
        $this->tools->model(65);

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
        $this->tools->model(65);

        return $this->captureResponse(
            $this->tools->sefazEventoLote($uf, $this->buildEventBatch($eventos), $dhEvento, $lote)
        );
    }

    public function registrarEventoAvancado(string $metodo, array|\stdClass $dados, array $opcoes = []): string
    {
        $this->tools->model(65);

        if (! SefazAdvancedMethodRegistry::isAllowedForModel($metodo, 65)) {
            throw new \InvalidArgumentException("Método SEFAZ não suportado para NFCe: {$metodo}");
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
            default => throw new \InvalidArgumentException("Estratégia SEFAZ não suportada: {$metodo}"),
        });
    }

    /**
     * @return array{response_xml:string,xml:string}
     */
    public function registrarEpec(string $xml, ?string $verAplic = null): array
    {
        $this->tools->model(65);

        $xmlContingencia = $xml;
        $response = $this->tools->sefazEpecNfce($xmlContingencia, $verAplic);

        return [
            'response_xml' => $this->captureResponse($response),
            'xml' => $xmlContingencia,
        ];
    }

    public function verificarStatusEpec(string $uf = '', ?int $ambiente = null, bool $ignorarContigencia = true): string
    {
        $this->tools->model(65);

        return $this->captureResponse($this->tools->sefazStatusEpecNfce($uf, $ambiente, $ignorarContigencia));
    }

    public function consultarCsc(int $indOperacao): string
    {
        $this->tools->model(65);

        return $this->captureResponse($this->tools->sefazCsc($indOperacao));
    }

    public function validarXmlSchemaSefaz(string $xml): bool
    {
        $this->tools->model(65);

        return $this->tools->sefazValidate($xml);
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

    private function captureResponse(string $response): string
    {
        $this->lastResponseXml = $response;

        return $response;
    }

    private function buildEventBatch(array|\stdClass $eventos): \stdClass
    {
        if ($eventos instanceof \stdClass) {
            return $eventos;
        }

        $std = new \stdClass;
        $std->evento = [];

        foreach ($eventos as $evento) {
            $item = $this->toStdClass($evento);
            $evt = new \stdClass;
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
