<?php

namespace sabbajohn\FiscalCore\Services\NFSe;

final class NfseNacionalIssIncidenceResolver
{
    /** @var array<string,string>|null */
    private static ?array $officialRules = null;

    /** @var list<string> */
    private const PRESTATION_LOCATION_CODES = [
        '030401', '030402', '030403', '030501', '070201', '070202', '070401', '070501', '070502',
        '070901', '070902', '071001', '071002', '071101', '071102', '071201', '071601', '071701',
        '071801', '071901', '110101', '110102', '110201', '110301', '110401', '110402', '120101',
        '120201', '120301', '120401', '120501', '120601', '120701', '120801', '120901', '120902',
        '120903', '121001', '121101', '121201', '121401', '121501', '121601', '121701', '160101',
        '160102', '160103', '160104', '160201', '171001', '171002', '220101', '990101',
    ];

    /** @param array<string,mixed> $payload @return array{codigo_municipio:string,fundamento:string} */
    public function resolve(array $payload): array
    {
        $service = is_array($payload['servico'] ?? null) ? $payload['servico'] : [];
        $customer = is_array($payload['tomador'] ?? null) ? $payload['tomador'] : [];
        $issuer = is_array($payload['prestador'] ?? null) ? $payload['prestador'] : [];
        $explicit = $this->digits($service['cLocIncid'] ?? $service['codigoMunicipioIncidencia'] ?? null);
        if (strlen($explicit) === 7) {
            return ['codigo_municipio' => $explicit, 'fundamento' => 'informado_no_payload_interno'];
        }

        $code = substr($this->digits($service['cTribNac'] ?? ''), 0, 6);
        $prestation = $this->digits($service['cLocPrestacao'] ?? $service['codigo_municipio'] ?? null);
        $customerCity = $this->digits($customer['endereco']['codigoMunicipio'] ?? $customer['codigoMunicipio'] ?? null);
        $issuerCity = $this->digits($issuer['codigoMunicipio'] ?? $payload['cLocEmi'] ?? null);

        $rule = $this->officialRule($code);
        if ($rule === 'tomador' && strlen($customerCity) === 7) {
            return ['codigo_municipio' => $customerCity, 'fundamento' => 'estabelecimento_tomador_tabela_mun_incid_info_serv'];
        }
        if (($rule === 'prestacao' || in_array($code, self::PRESTATION_LOCATION_CODES, true)) && strlen($prestation) === 7) {
            return ['codigo_municipio' => $prestation, 'fundamento' => 'local_prestacao_tabela_mun_incid_info_serv'];
        }
        if (strlen($issuerCity) === 7) {
            return ['codigo_municipio' => $issuerCity, 'fundamento' => 'estabelecimento_prestador_tabela_mun_incid_info_serv'];
        }

        return ['codigo_municipio' => $prestation, 'fundamento' => 'fallback_local_prestacao'];
    }

    private function digits(mixed $value): string
    {
        return preg_replace('/\D+/', '', is_scalar($value) ? (string) $value : '') ?? '';
    }

    private function officialRule(string $code): ?string
    {
        if (self::$officialRules === null) {
            self::$officialRules = $this->loadOfficialRules();
        }

        return self::$officialRules[$code] ?? null;
    }

    /** @return array<string,string> */
    private function loadOfficialRules(): array
    {
        $path = dirname(__DIR__, 3).'/resources/nfse/nacional/1.01/anexos/anexo-i-sefin-adn-dps-nfse-snnfse-v1-01-20260209.json';
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [];
        }
        $rules = [];
        $sheets = is_array($decoded['workbook']['sheets'] ?? null) ? $decoded['workbook']['sheets'] : [];
        foreach ($sheets as $sheet) {
            if (! is_array($sheet) || ($sheet['name'] ?? null) !== 'MUN.INCID_INFO.SERV.') {
                continue;
            }
            foreach ((array) ($sheet['rows'] ?? []) as $row) {
                $cells = is_array($row['cells'] ?? null) ? $row['cells'] : [];
                $rawCode = preg_replace('/\D+/', '', (string) ($cells['A'] ?? '')) ?? '';
                if ($rawCode === '' || strlen($rawCode) > 6) {
                    continue;
                }
                $normalized = str_pad($rawCode, 6, '0', STR_PAD_LEFT);
                if (strtoupper(trim((string) ($cells['E'] ?? ''))) === 'X') {
                    $rules[$normalized] = 'tomador';
                } elseif (strtoupper(trim((string) ($cells['D'] ?? ''))) === 'X') {
                    $rules[$normalized] = 'prestacao';
                } elseif (strtoupper(trim((string) ($cells['C'] ?? ''))) === 'X') {
                    $rules[$normalized] = 'prestador';
                }
            }
            break;
        }

        return $rules;
    }
}
