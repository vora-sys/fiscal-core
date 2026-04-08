<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/examples/homologacao/common.php';
require_once dirname(__DIR__, 3) . '/examples/homologacao/manaus_nacional_common.php';

final class ManausNacionalCommonTest extends TestCase
{
    /** @var string[] */
    private array $managedEnvKeys = [
        'FISCAL_CNPJ',
        'FISCAL_IM',
        'FISCAL_RAZAO_SOCIAL',
        'FISCAL_UF',
    ];

    protected function setUp(): void
    {
        $this->clearEnvironment();
        $this->setEnvironment('FISCAL_CNPJ', '83188342000104');
        $this->setEnvironment('FISCAL_IM', '4007197');
        $this->setEnvironment('FISCAL_RAZAO_SOCIAL', 'FREELINE INFORMATICA LTDA');
        $this->setEnvironment('FISCAL_UF', 'AM');
    }

    protected function tearDown(): void
    {
        $this->clearEnvironment();
    }

    public function test_parse_options_defaults_to_preview_mode(): void
    {
        $options = manausNacionalParseOptions(['05-manaus-operacoes-nacionais.php']);

        $this->assertFalse($options['send']);
        $this->assertSame('12345678909', $options['tomador_doc']);
        $this->assertSame('TOMADOR DE TESTE MANAUS', $options['tomador_nome']);
        $this->assertSame('10.00', $options['valor']);
        $this->assertSame('010101', $options['c_trib_nac']);
        $this->assertFalse(manausNacionalHasOperationFlags($options));
    }

    public function test_parse_options_detects_operational_flags(): void
    {
        $options = manausNacionalParseOptions([
            '05-manaus-operacoes-nacionais.php',
            '--send',
            '--listar-codigos',
            '--buscar-codigo=informatica',
            '--codigo-prefixo=01',
            '--limite=15',
            '--consultar-rps-numero=321',
            '--consultar-rps-serie=2',
            '--consultar-rps-tipo=3',
            '--competencia=2026-04-03',
            '--c-trib-nac=010107',
        ]);

        $this->assertTrue($options['send']);
        $this->assertTrue($options['listar_codigos']);
        $this->assertSame('informatica', $options['buscar_codigo']);
        $this->assertSame('01', $options['codigo_prefixo']);
        $this->assertSame('15', $options['limite']);
        $this->assertSame('321', $options['consultar_rps_numero']);
        $this->assertSame('2', $options['consultar_rps_serie']);
        $this->assertSame('3', $options['consultar_rps_tipo']);
        $this->assertSame('2026-04-03', $options['competencia']);
        $this->assertSame('010107', $options['c_trib_nac']);
        $this->assertTrue(manausNacionalHasOperationFlags($options));
    }

    public function test_listar_codigos_usa_tabela_nacional_local(): void
    {
        $result = manausNacionalListarCodigos([
            'buscar_codigo' => 'informatica',
            'codigo_prefixo' => '01',
            'limite' => '3',
        ], dirname(__DIR__, 3));

        $this->assertSame('manaus', $result['municipio']);
        $this->assertSame('1302603', $result['codigo_municipio']);
        $this->assertSame(2, $result['metadata']['total_retornado']);
        $this->assertGreaterThanOrEqual(2, $result['metadata']['total_encontrado']);
        $this->assertSame('010601', $result['codigos'][0]['codigo']);
        $this->assertStringContainsString('informática', mb_strtolower($result['codigos'][0]['descricao']));
    }

    public function test_build_payload_uses_manaus_homologation_defaults(): void
    {
        $payload = manausNacionalBuildPayload([
            'competencia' => '2026-04-03',
            'c_trib_nac' => '010107',
            'aliquota' => '0.035',
            'valor' => '125.55',
            'tomador_doc' => '12345678909',
            'tomador_nome' => 'TOMADOR DE TESTE MANAUS',
        ]);

        $this->assertSame('2', $payload['tpAmb']);
        $this->assertSame('2026-04-03', $payload['dCompet']);
        $this->assertSame('2026-04-03T10:00:00-04:00', $payload['dhEmi']);
        $this->assertMatchesRegularExpression('/^DPS\\d{42}$/', $payload['id']);
        $this->assertSame('1302603', $payload['cLocEmi']);
        $this->assertSame('83188342000104', $payload['prestador']['cnpj']);
        $this->assertSame('4007197', $payload['prestador']['inscricaoMunicipal']);
        $this->assertSame('FREELINE INFORMATICA LTDA', $payload['prestador']['razaoSocial']);
        $this->assertSame('1302603', $payload['prestador']['codigoMunicipio']);
        $this->assertSame('010107', $payload['servico']['codigo']);
        $this->assertSame('010107', $payload['servico']['cTribNac']);
        $this->assertSame('1302603', $payload['servico']['cLocPrestacao']);
        $this->assertSame('1302603', $payload['servico']['codigo_municipio']);
        $this->assertSame(0.035, $payload['servico']['aliquota']);
        $this->assertSame(125.55, $payload['valor_servicos']);
        $this->assertSame(15, strlen($payload['nDPS']));
    }

    public function test_build_payload_adds_obra_for_codes_that_require_it(): void
    {
        $payload = manausNacionalBuildPayload([
            'competencia' => '2026-04-03',
            'c_trib_nac' => '070201',
            'obra_codigo' => 'OBRA-TESTE-070201',
        ]);

        $this->assertSame('070201', $payload['servico']['cTribNac']);
        $this->assertSame('OBRA-TESTE-070201', $payload['servico']['obra']['cObra']);
    }

    private function setEnvironment(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function clearEnvironment(): void
    {
        foreach ($this->managedEnvKeys as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }
}
