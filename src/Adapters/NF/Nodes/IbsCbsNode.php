<?php

namespace sabbajohn\FiscalCore\Adapters\NF\Nodes;

use NFePHP\NFe\Make;
use sabbajohn\FiscalCore\Adapters\NF\Core\NotaNodeInterface;

class IbsCbsNode implements NotaNodeInterface
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(
        private int $item,
        private array $data,
    ) {
    }

    public function addToMake(Make $make): void
    {
        $base = $this->basePayload();
        if ($base === null) {
            return;
        }

        $make->tagIBSCBS((object) $base);
        $hasRegularBase = array_key_exists('vBC', $base);

        if ($hasRegularBase) {
            $regular = $this->regularPayload();
            if ($regular !== null) {
                $make->tagIBSCBSTribRegular((object) $regular);
            }

            $compraGov = $this->compraGovPayload();
            if ($compraGov !== null) {
                $make->taggTribCompraGov((object) $compraGov);
            }
        }

        $creditoPresumido = $this->creditoPresumidoPayload();
        if ($creditoPresumido !== null) {
            $make->taggCredPresOper((object) $creditoPresumido);
        }

        $transferenciaCredito = $this->transferenciaCreditoPayload();
        if ($transferenciaCredito !== null) {
            $make->taggTransfCred((object) $transferenciaCredito);
        }

        $creditoPresumidoZfm = $this->creditoPresumidoZfmPayload();
        if ($creditoPresumidoZfm !== null) {
            $make->taggCredPresIBSZFM((object) $creditoPresumidoZfm);
        }

        $ajusteCompetencia = $this->ajusteCompetenciaPayload();
        if ($ajusteCompetencia !== null) {
            $make->taggAjusteCompet((object) $ajusteCompetencia);
        }

        $estornoCredito = $this->estornoCreditoPayload();
        if ($estornoCredito !== null) {
            $make->taggEstornoCred((object) $estornoCredito);
        }

        $mono = $hasRegularBase ? null : $this->monoPayload();
        if ($mono !== null) {
            $make->tagIBSCBSMono((object) $mono);
        }

        $dfeReferenciado = $this->dfeReferenciadoPayload();
        if ($dfeReferenciado !== null) {
            $make->tagDFeReferenciado((object) $dfeReferenciado);
        }
    }

    public function validate(): bool
    {
        if ($this->data === []) {
            return true;
        }

        if ($this->stringValue(['CST', 'cst']) === null) {
            throw new \InvalidArgumentException('CST do IBS/CBS e obrigatorio');
        }

        if ($this->stringValue(['cClassTrib', 'classe', 'classificacao', 'codigo_classificacao']) === null) {
            throw new \InvalidArgumentException('Classe tributaria do IBS/CBS e obrigatoria');
        }

        return true;
    }

    public function getNodeType(): string
    {
        return 'ibs_cbs';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function basePayload(): ?array
    {
        $cst = $this->stringValue(['CST', 'cst']);
        $classe = $this->stringValue(['cClassTrib', 'classe', 'classificacao', 'codigo_classificacao']);
        if ($cst === null || $classe === null) {
            return null;
        }

        $payload = [
            'item' => $this->item,
            'CST' => $this->digits($cst, 3),
            'cClassTrib' => $this->digits($classe, 6),
        ];

        $indDoacao = $this->stringValue(['indDoacao', 'indicador_doacao']);
        if ($indDoacao !== null) {
            $payload['indDoacao'] = $indDoacao;
        }

        $base = $this->numberValue(['vBC', 'base_calculo']);
        if ($base === null) {
            return $payload;
        }

        $payload['vBC'] = $base;
        $payload['gIBSUF_pIBSUF'] = $this->numberValue(['gIBSUF_pIBSUF', 'gIBSUF.pIBSUF', 'ibs_uf.aliquota']) ?? 0.0;
        $payload['gIBSUF_pDif'] = $this->numberValue(['gIBSUF_pDif', 'gIBSUF.gDif.pDif', 'ibs_uf.dif.percentual']);
        $payload['gIBSUF_vDif'] = $this->numberValue(['gIBSUF_vDif', 'gIBSUF.gDif.vDif', 'ibs_uf.dif.valor']);
        $payload['gIBSUF_vDevTrib'] = $this->numberValue(['gIBSUF_vDevTrib', 'gIBSUF.gDevTrib.vDevTrib', 'ibs_uf.devolucao']);
        $payload['gIBSUF_pRedAliq'] = $this->numberValue(['gIBSUF_pRedAliq', 'gIBSUF.gRed.pRedAliq', 'ibs_uf.reducao.percentual']);
        $payload['gIBSUF_pAliqEfet'] = $this->numberValue(['gIBSUF_pAliqEfet', 'gIBSUF.gRed.pAliqEfet', 'ibs_uf.reducao.aliquota_efetiva']) ?? $payload['gIBSUF_pIBSUF'];
        $payload['gIBSUF_vIBSUF'] = $this->numberValue(['gIBSUF_vIBSUF', 'gIBSUF.vIBSUF', 'ibs_uf.valor']) ?? 0.0;

        $payload['gIBSMun_pIBSMun'] = $this->numberValue(['gIBSMun_pIBSMun', 'gIBSMun.pIBSMun', 'ibs_mun.aliquota']) ?? 0.0;
        $payload['gIBSMun_pDif'] = $this->numberValue(['gIBSMun_pDif', 'gIBSMun.gDif.pDif', 'ibs_mun.dif.percentual']);
        $payload['gIBSMun_vDif'] = $this->numberValue(['gIBSMun_vDif', 'gIBSMun.gDif.vDif', 'ibs_mun.dif.valor']);
        $payload['gIBSMun_vDevTrib'] = $this->numberValue(['gIBSMun_vDevTrib', 'gIBSMun.gDevTrib.vDevTrib', 'ibs_mun.devolucao']);
        $payload['gIBSMun_pRedAliq'] = $this->numberValue(['gIBSMun_pRedAliq', 'gIBSMun.gRed.pRedAliq', 'ibs_mun.reducao.percentual']);
        $payload['gIBSMun_pAliqEfet'] = $this->numberValue(['gIBSMun_pAliqEfet', 'gIBSMun.gRed.pAliqEfet', 'ibs_mun.reducao.aliquota_efetiva']) ?? $payload['gIBSMun_pIBSMun'];
        $payload['gIBSMun_vIBSMun'] = $this->numberValue(['gIBSMun_vIBSMun', 'gIBSMun.vIBSMun', 'ibs_mun.valor']) ?? 0.0;

        $payload['vIBS'] = $this->numberValue(['vIBS', 'ibs.valor']) ?? ($payload['gIBSUF_vIBSUF'] + $payload['gIBSMun_vIBSMun']);
        $payload['gCBS_pCBS'] = $this->numberValue(['gCBS_pCBS', 'gCBS.pCBS', 'cbs.aliquota']) ?? 0.0;
        $payload['gCBS_pDif'] = $this->numberValue(['gCBS_pDif', 'gCBS.gDif.pDif', 'cbs.dif.percentual']);
        $payload['gCBS_vDif'] = $this->numberValue(['gCBS_vDif', 'gCBS.gDif.vDif', 'cbs.dif.valor']);
        $payload['gCBS_vDevTrib'] = $this->numberValue(['gCBS_vDevTrib', 'gCBS.gDevTrib.vDevTrib', 'cbs.devolucao']);
        $payload['gCBS_pRedAliq'] = $this->numberValue(['gCBS_pRedAliq', 'gCBS.gRed.pRedAliq', 'cbs.reducao.percentual']);
        $payload['gCBS_pAliqEfet'] = $this->numberValue(['gCBS_pAliqEfet', 'gCBS.gRed.pAliqEfet', 'cbs.reducao.aliquota_efetiva']) ?? $payload['gCBS_pCBS'];
        $payload['gCBS_vCBS'] = $this->numberValue(['gCBS_vCBS', 'gCBS.vCBS', 'cbs.valor']) ?? 0.0;

        return $this->withoutNulls($payload);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function regularPayload(): ?array
    {
        $regular = $this->arrayValue(['gTribRegular', 'regular']);
        if ($regular === []) {
            return null;
        }

        $cst = $this->stringValueFrom($regular, ['CSTReg', 'cstReg', 'CST', 'cst']);
        $classe = $this->stringValueFrom($regular, ['cClassTribReg', 'cClassReg', 'cClassTrib', 'classe', 'classificacao']);
        if ($cst === null || $classe === null) {
            return null;
        }

        return [
            'item' => $this->item,
            'CSTReg' => $this->digits($cst, 3),
            'cClassTribReg' => $this->digits($classe, 6),
            'pAliqEfetRegIBSUF' => $this->numberValueFrom($regular, ['pAliqEfetRegIBSUF', 'ibs_uf.aliquota']) ?? 0.0,
            'vTribRegIBSUF' => $this->numberValueFrom($regular, ['vTribRegIBSUF', 'ibs_uf.valor']) ?? 0.0,
            'pAliqEfetRegIBSMun' => $this->numberValueFrom($regular, ['pAliqEfetRegIBSMun', 'ibs_mun.aliquota']) ?? 0.0,
            'vTribRegIBSMun' => $this->numberValueFrom($regular, ['vTribRegIBSMun', 'ibs_mun.valor']) ?? 0.0,
            'pAliqEfetRegCBS' => $this->numberValueFrom($regular, ['pAliqEfetRegCBS', 'cbs.aliquota']) ?? 0.0,
            'vTribRegCBS' => $this->numberValueFrom($regular, ['vTribRegCBS', 'cbs.valor']) ?? 0.0,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function compraGovPayload(): ?array
    {
        $compraGov = $this->arrayValue(['gTribCompraGov', 'compra_gov']);
        if ($compraGov === [] || ! $this->hasAnyNumericValue($compraGov)) {
            return null;
        }

        return [
            'item' => $this->item,
            'pAliqIBSUF' => $this->numberValueFrom($compraGov, ['pAliqIBSUF', 'ibs_uf.aliquota']) ?? 0.0,
            'vTribIBSUF' => $this->numberValueFrom($compraGov, ['vTribIBSUF', 'ibs_uf.valor']) ?? 0.0,
            'pAliqIBSMun' => $this->numberValueFrom($compraGov, ['pAliqIBSMun', 'ibs_mun.aliquota']) ?? 0.0,
            'vTribIBSMun' => $this->numberValueFrom($compraGov, ['vTribIBSMun', 'ibs_mun.valor']) ?? 0.0,
            'pAliqCBS' => $this->numberValueFrom($compraGov, ['pAliqCBS', 'cbs.aliquota']) ?? 0.0,
            'vTribCBS' => $this->numberValueFrom($compraGov, ['vTribCBS', 'cbs.valor']) ?? 0.0,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function creditoPresumidoPayload(): ?array
    {
        $presumido = $this->arrayValue(['gCredPresOper', 'presumido']);
        if ($presumido === []) {
            return null;
        }

        $ibs = $this->arrayValueFrom($presumido, ['ibs']);
        $cbs = $this->arrayValueFrom($presumido, ['cbs']);
        $codigo = $this->stringValueFrom($presumido, ['cCredPres', 'codigo'])
            ?? $this->stringValueFrom($ibs, ['codigo'])
            ?? $this->stringValueFrom($cbs, ['codigo']);

        if ($codigo === null) {
            return null;
        }

        return $this->withoutNulls([
            'item' => $this->item,
            'vBCCredPres' => $this->numberValueFrom($presumido, ['vBCCredPres', 'base_calculo'])
                ?? $this->numberValue(['base_calculo'])
                ?? 0.0,
            'cCredPres' => $this->digits($codigo, 2),
            'ibs_pCredPres' => $this->numberValueFrom($ibs, ['pCredPres', 'aliquota']),
            'ibs_vCredPres' => $this->numberValueFrom($ibs, ['vCredPres', 'valor']),
            'ibs_vCredPresCondSus' => $this->numberValueFrom($ibs, ['vCredPresCondSus', 'suspenso']),
            'cbs_pCredPres' => $this->numberValueFrom($cbs, ['pCredPres', 'aliquota']),
            'cbs_vCredPres' => $this->numberValueFrom($cbs, ['vCredPres', 'valor']),
            'cbs_vCredPresCondSus' => $this->numberValueFrom($cbs, ['vCredPresCondSus', 'suspenso']),
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function monoPayload(): ?array
    {
        $mono = $this->arrayValue(['gIBSCBSMono', 'mono']);
        if ($mono === [] || ! $this->hasAnyPositiveNumericValue($mono)) {
            return null;
        }

        $padrao = $this->arrayValueFrom($mono, ['padrao', 'gMonoPadrao']);
        $reten = $this->arrayValueFrom($mono, ['reten', 'gMonoReten']);
        $retido = $this->arrayValueFrom($mono, ['retido', 'gMonoRet']);
        $dif = $this->arrayValueFrom($mono, ['dif', 'gMonoDif']);

        return $this->withoutNulls([
            'item' => $this->item,
            'qBCMono' => $this->numberValueFrom($padrao, ['qBCMono', 'base_calculo_qtd']),
            'adRemIBS' => $this->numberValueFrom($padrao, ['adRemIBS', 'ibs.aliquota']),
            'adRemCBS' => $this->numberValueFrom($padrao, ['adRemCBS', 'cbs.aliquota']),
            'vIBSMono' => $this->numberValueFrom($padrao, ['vIBSMono', 'ibs.valor']),
            'vCBSMono' => $this->numberValueFrom($padrao, ['vCBSMono', 'cbs.valor']),
            'qBCMonoReten' => $this->numberValueFrom($reten, ['qBCMonoReten', 'base_calculo_qtd']),
            'adRemIBSReten' => $this->numberValueFrom($reten, ['adRemIBSReten', 'ibs.aliquota']),
            'vIBSMonoReten' => $this->numberValueFrom($reten, ['vIBSMonoReten', 'ibs.valor']),
            'adRemCBSReten' => $this->numberValueFrom($reten, ['adRemCBSReten', 'cbs.aliquota']),
            'vCBSMonoReten' => $this->numberValueFrom($reten, ['vCBSMonoReten', 'cbs.valor']),
            'qBCMonoRet' => $this->numberValueFrom($retido, ['qBCMonoRet', 'base_calculo_qtd']),
            'adRemIBSRet' => $this->numberValueFrom($retido, ['adRemIBSRet', 'ibs.aliquota']),
            'vIBSMonoRet' => $this->numberValueFrom($retido, ['vIBSMonoRet', 'ibs.valor']),
            'adRemCBSRet' => $this->numberValueFrom($retido, ['adRemCBSRet', 'cbs.aliquota']),
            'vCBSMonoRet' => $this->numberValueFrom($retido, ['vCBSMonoRet', 'cbs.valor']),
            'pDifIBS' => $this->numberValueFrom($dif, ['pDifIBS', 'ibs.percentual']),
            'vIBSMonoDif' => $this->numberValueFrom($dif, ['vIBSMonoDif', 'ibs.valor']),
            'pDifCBS' => $this->numberValueFrom($dif, ['pDifCBS', 'cbs.percentual']),
            'vCBSMonoDif' => $this->numberValueFrom($dif, ['vCBSMonoDif', 'cbs.valor']),
            'vTotIBSMonoItem' => $this->numberValueFrom($mono, ['vTotIBSMonoItem', 'ibs.total', 'total.ibs']),
            'vTotCBSMonoItem' => $this->numberValueFrom($mono, ['vTotCBSMonoItem', 'cbs.total', 'total.cbs']),
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function transferenciaCreditoPayload(): ?array
    {
        $transferencia = $this->arrayValue(['gTransfCred', 'transferencia_credito', 'transferencia', 'transf_cred']);
        if ($transferencia === [] || ! $this->hasAnyNumericValue($transferencia)) {
            return null;
        }

        return [
            'item' => $this->item,
            'vIBS' => $this->numberValueFrom($transferencia, ['vIBS', 'ibs.valor', 'ibs']) ?? 0.0,
            'vCBS' => $this->numberValueFrom($transferencia, ['vCBS', 'cbs.valor', 'cbs']) ?? 0.0,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function creditoPresumidoZfmPayload(): ?array
    {
        $zfm = $this->arrayValue(['gCredPresIBSZFM', 'credito_presumido_zfm', 'presumido_zfm', 'zfm']);
        if ($zfm === []) {
            return null;
        }

        $tipo = $this->stringValueFrom($zfm, ['tpCredPresIBSZFM', 'tipo', 'codigo']);
        $valor = $this->numberValueFrom($zfm, ['vCredPresIBSZFM', 'valor']);
        if ($tipo === null || $valor === null) {
            return null;
        }

        return $this->withoutNulls([
            'item' => $this->item,
            'competApur' => $this->stringValueFrom($zfm, ['competApur', 'competencia', 'periodo_apuracao']),
            'tpCredPresIBSZFM' => $tipo,
            'vCredPresIBSZFM' => $valor,
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function ajusteCompetenciaPayload(): ?array
    {
        $ajuste = $this->arrayValue(['gAjusteCompet', 'ajuste_competencia', 'ajuste']);
        if ($ajuste === [] || ! $this->hasAnyNumericValue($ajuste)) {
            return null;
        }

        $competencia = $this->stringValueFrom($ajuste, ['competApur', 'competencia', 'periodo_apuracao']);
        if ($competencia === null) {
            return null;
        }

        return [
            'item' => $this->item,
            'competApur' => $competencia,
            'vIBS' => $this->numberValueFrom($ajuste, ['vIBS', 'ibs.valor', 'ibs']) ?? 0.0,
            'vCBS' => $this->numberValueFrom($ajuste, ['vCBS', 'cbs.valor', 'cbs']) ?? 0.0,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function estornoCreditoPayload(): ?array
    {
        $estorno = $this->arrayValue(['gEstornoCred', 'estorno_credito', 'estorno']);
        if ($estorno === [] || ! $this->hasAnyNumericValue($estorno)) {
            return null;
        }

        return [
            'item' => $this->item,
            'vIBSEstCred' => $this->numberValueFrom($estorno, ['vIBSEstCred', 'ibs.valor', 'ibs']) ?? 0.0,
            'vCBSEstCred' => $this->numberValueFrom($estorno, ['vCBSEstCred', 'cbs.valor', 'cbs']) ?? 0.0,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function dfeReferenciadoPayload(): ?array
    {
        $referencia = $this->arrayValue(['DFeReferenciado', 'dfe_referenciado', 'referencia_dfe']);
        if ($referencia === [] || array_is_list($referencia)) {
            $referencias = $this->arrayValue(['DFeReferenciados', 'dfe_referenciados', 'referencias_dfe']);
            $referencia = is_array($referencias[0] ?? null) ? $referencias[0] : [];
        }

        if ($referencia === []) {
            return null;
        }

        $chave = $this->stringValueFrom($referencia, ['chaveAcesso', 'chave_acesso', 'chave']);
        if ($chave === null) {
            return null;
        }

        return $this->withoutNulls([
            'item' => $this->item,
            'chaveAcesso' => $this->digits($chave, 44),
            'nItem' => $this->numberValueFrom($referencia, ['nItem', 'item_referenciado']),
        ]);
    }

    /**
     * @param list<string> $paths
     */
    private function stringValue(array $paths): ?string
    {
        return $this->stringValueFrom($this->data, $paths);
    }

    /**
     * @param array<string,mixed> $source
     * @param list<string> $paths
     */
    private function stringValueFrom(array $source, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = $this->valueAtPath($source, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param list<string> $paths
     */
    private function numberValue(array $paths): ?float
    {
        return $this->numberValueFrom($this->data, $paths);
    }

    /**
     * @param array<string,mixed> $source
     * @param list<string> $paths
     */
    private function numberValueFrom(array $source, array $paths): ?float
    {
        foreach ($paths as $path) {
            $value = $this->valueAtPath($source, $path);
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * @param list<string> $paths
     * @return array<string,mixed>
     */
    private function arrayValue(array $paths): array
    {
        return $this->arrayValueFrom($this->data, $paths);
    }

    /**
     * @param array<string,mixed> $source
     * @param list<string> $paths
     * @return array<string,mixed>
     */
    private function arrayValueFrom(array $source, array $paths): array
    {
        foreach ($paths as $path) {
            $value = $this->valueAtPath($source, $path);
            if (is_array($value)) {
                return $value;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function hasAnyNumericValue(array $payload): bool
    {
        foreach ($payload as $value) {
            if (is_array($value) && $this->hasAnyNumericValue($value)) {
                return true;
            }

            if (is_numeric($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function hasAnyPositiveNumericValue(array $payload): bool
    {
        foreach ($payload as $value) {
            if (is_array($value) && $this->hasAnyPositiveNumericValue($value)) {
                return true;
            }

            if (is_numeric($value) && (float) $value > 0.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function withoutNulls(array $payload): array
    {
        return array_filter($payload, static fn (mixed $value): bool => $value !== null);
    }

    private function digits(string $value, int $length): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return str_pad(substr($digits, 0, $length), $length, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string,mixed> $source
     */
    private function valueAtPath(array $source, string $path): mixed
    {
        if (array_key_exists($path, $source)) {
            return $source[$path];
        }

        $value = $source;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
