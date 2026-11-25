<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Adapters\GTIN;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use RuntimeException;

final class ConsultaGtinCcg
{
    private const NS_NFE = 'http://www.portalfiscal.inf.br/nfe';
    private const NS_CCG = self::NS_NFE.'/wsdl/ccgConsGtin';
    private const NS_SOAP = 'http://www.w3.org/2003/05/soap-envelope';

    public const ACAO_SOAP = self::NS_CCG.'/ccgConsGTIN';

    public function __construct(private readonly TransporteConsultaGtin $transporte) {}

    /** @return array{status:string,codigo_sefaz:?string,gtin:string,descricao:?string,ncm:?string,cests:list<string>} */
    public function consultar(string $gtin, #[\SensitiveParameter] string $pfx, #[\SensitiveParameter] string $senha): array
    {
        if (! self::valido($gtin)) {
            throw new InvalidArgumentException('Informe um GTIN válido com 8, 12, 13 ou 14 dígitos.');
        }
        if (! self::prefixoConsultavel($gtin)) {
            return $this->resultado('prefixo_nao_suportado', $gtin);
        }

        $envelope = '<soap:Envelope xmlns:soap="'.self::NS_SOAP.'" xmlns:ccg="'.self::NS_CCG.'">'
            .'<soap:Header/><soap:Body><ccg:ccgConsGTIN><ccg:nfeDadosMsg>'
            .'<consGTIN versao="1.00" xmlns="'.self::NS_NFE.'"><GTIN>'.$gtin.'</GTIN></consGTIN>'
            .'</ccg:nfeDadosMsg></ccg:ccgConsGTIN></soap:Body></soap:Envelope>';

        return $this->interpretar($this->transporte->enviar($envelope, $pfx, $senha), $gtin);
    }

    public static function valido(string $gtin): bool
    {
        if (! preg_match('/\A(?:[0-9]{8}|[0-9]{12,14})\z/', $gtin) || trim($gtin, '0') === '') {
            return false;
        }

        $soma = 0;
        for ($i = strlen($gtin) - 2, $peso = 3; $i >= 0; $i--, $peso = 4 - $peso) {
            $soma += (int) $gtin[$i] * $peso;
        }

        return (10 - $soma % 10) % 10 === (int) substr($gtin, -1);
    }

    public static function prefixoConsultavel(string $gtin): bool
    {
        $normalizado = str_pad($gtin, 14, '0', STR_PAD_LEFT);
        $inicio = str_starts_with($normalizado, '000000') ? 6 : 1;

        return in_array(substr($normalizado, $inicio, 3), ['789', '790'], true);
    }

    private function interpretar(string $xml, string $gtin): array
    {
        if ($xml === '' || strlen($xml) > TransporteCurlGtin::LIMITE_RESPOSTA
            || preg_match('/<!DOCTYPE/i', $xml)) {
            throw $this->respostaInvalida();
        }

        $doc = new DOMDocument();
        if (! $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) || $doc->doctype !== null) {
            throw $this->respostaInvalida();
        }

        $raiz = $doc->documentElement;
        if ($raiz?->localName !== 'Envelope' || $raiz->namespaceURI !== self::NS_SOAP) {
            throw $this->respostaInvalida();
        }
        $corpos = $raiz->getElementsByTagNameNS(self::NS_SOAP, 'Body');
        $corpo = $corpos->item(0);
        if ($corpos->length !== 1 || ! $corpo instanceof DOMElement || $corpo->parentNode !== $raiz
            || $corpo->getElementsByTagNameNS(self::NS_SOAP, 'Fault')->length !== 0) {
            throw $this->respostaInvalida();
        }
        $retornos = $corpo->getElementsByTagNameNS(self::NS_NFE, 'retConsGTIN');
        $retorno = $retornos->item(0);
        if ($retornos->length !== 1 || ! $retorno instanceof DOMElement) {
            throw $this->respostaInvalida();
        }

        $codigo = $this->campo($retorno, 'cStat');
        $status = match ($codigo) {
            '9490' => 'encontrado',
            '9491' => 'gtin_invalido',
            '9492' => 'prefixo_nao_suportado',
            '9494' => 'nao_encontrado',
            '9495' => 'situacao_invalida',
            '9496' => 'publicacao_nao_autorizada',
            '9497' => 'ncm_nao_cadastrado',
            '9498' => 'ncm_invalido',
            '280', '281', '282', '283', '284', '285', '286', '9493' => 'certificado_rejeitado',
            '108', '109' => 'indisponivel',
            default => throw $this->respostaInvalida(),
        };

        $resultado = $this->resultado($status, $gtin, $codigo);
        if ($status !== 'encontrado') {
            return $resultado;
        }

        $retornado = $this->campo($retorno, 'GTIN');
        if ($retornado !== null && (! self::valido($retornado)
            || str_pad($retornado, 14, '0', STR_PAD_LEFT) !== str_pad($gtin, 14, '0', STR_PAD_LEFT))) {
            throw $this->respostaInvalida();
        }

        $descricao = $this->campo($retorno, 'xProd');
        $ncm = $this->campo($retorno, 'NCM');
        $cests = $this->campos($retorno, 'CEST');
        if (($descricao !== null && strlen($descricao) > 2000)
            || ($ncm !== null && ! preg_match('/\A[0-9]{8}\z/', $ncm))
            || count($cests) > 3) {
            throw $this->respostaInvalida();
        }
        foreach ($cests as $cest) {
            if (! preg_match('/\A[0-9]{7}\z/', $cest)) {
                throw $this->respostaInvalida();
            }
        }

        $resultado['descricao'] = $descricao;
        $resultado['ncm'] = $ncm;
        $resultado['cests'] = array_values(array_unique($cests));

        return $resultado;
    }

    private function campo(DOMElement $retorno, string $nome): ?string
    {
        $campos = $this->campos($retorno, $nome);
        if (count($campos) > 1) {
            throw $this->respostaInvalida();
        }

        return ($campos[0] ?? '') === '' ? null : $campos[0];
    }

    /** @return list<string> */
    private function campos(DOMElement $retorno, string $nome): array
    {
        $campos = [];
        foreach ($retorno->childNodes as $filho) {
            if ($filho instanceof DOMElement && $filho->namespaceURI === self::NS_NFE && $filho->localName === $nome) {
                foreach ($filho->childNodes as $valor) {
                    if ($valor instanceof DOMElement) {
                        throw $this->respostaInvalida();
                    }
                }
                $campos[] = trim($filho->textContent);
            }
        }

        return $campos;
    }

    private function resultado(string $status, string $gtin, ?string $codigo = null): array
    {
        return [
            'status' => $status,
            'codigo_sefaz' => $codigo,
            'gtin' => $gtin,
            'descricao' => null,
            'ncm' => null,
            'cests' => [],
        ];
    }

    private function respostaInvalida(): RuntimeException
    {
        return new RuntimeException('Resposta inesperada do Cadastro Centralizado de GTIN.');
    }
}
