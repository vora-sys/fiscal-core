<?php

namespace sabbajohn\FiscalCore\Support;

final class SefazAdvancedMethodRegistry
{
    /** @var array<string,string> */
    private const STRATEGIES = [
        'sefazAtorInteressado' => 'std_event',
        'sefazComprovanteEntrega' => 'std_event',
        'sefazInsucessoEntrega' => 'std_event',
        'sefazConciliacao' => 'std_event',
        'sefazEPP' => 'epp',
        'sefazECPP' => 'ecpp',
        'sefazInfoPagtoIntegral' => 'std_ver_aplic',
        'sefazSolApropCredPresumido' => 'std_ver_aplic',
        'sefazDestinoConsumoPessoal' => 'std_ver_aplic',
        'sefazAceiteDebito' => 'std_ver_aplic',
        'sefazImobilizacaoItem' => 'std_ver_aplic',
        'sefazApropriacaoCreditoComb' => 'std_ver_aplic',
        'sefazApropriacaoCreditoBens' => 'std_ver_aplic',
        'sefazManifestacaoTransfCredIBS' => 'std_ver_aplic',
        'sefazManifestacaoTransfCredCBS' => 'std_ver_aplic',
        'sefazCancelaEvento' => 'std_ver_aplic',
        'sefazImportacaoZFM' => 'std_ver_aplic',
        'sefazRouboPerdaTransporteAdquirente' => 'std_ver_aplic',
        'sefazRouboPerdaTransporteFornecedor' => 'std_ver_aplic',
        'sefazFornecimentoNaoRealizado' => 'std_ver_aplic',
        'sefazAtualizacaoDataEntrega' => 'std_ver_aplic',
    ];

    /** @var list<string> */
    private const NFE_ONLY = [
        'sefazEPP',
        'sefazECPP',
        'sefazInfoPagtoIntegral',
        'sefazSolApropCredPresumido',
        'sefazDestinoConsumoPessoal',
        'sefazAceiteDebito',
        'sefazImobilizacaoItem',
        'sefazApropriacaoCreditoComb',
        'sefazApropriacaoCreditoBens',
        'sefazManifestacaoTransfCredIBS',
        'sefazManifestacaoTransfCredCBS',
        'sefazCancelaEvento',
        'sefazImportacaoZFM',
        'sefazRouboPerdaTransporteAdquirente',
        'sefazRouboPerdaTransporteFornecedor',
        'sefazFornecimentoNaoRealizado',
        'sefazAtualizacaoDataEntrega',
    ];

    public static function isAllowedForModel(string $method, int $model): bool
    {
        if (! isset(self::STRATEGIES[$method])) {
            return false;
        }

        if ($model === 65 && in_array($method, self::NFE_ONLY, true)) {
            return false;
        }

        return in_array($model, [55, 65], true);
    }

    public static function strategy(string $method): ?string
    {
        return self::STRATEGIES[$method] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function allowedMethodsForModel(int $model): array
    {
        return array_values(array_filter(
            array_keys(self::STRATEGIES),
            static fn (string $method): bool => self::isAllowedForModel($method, $model)
        ));
    }
}
