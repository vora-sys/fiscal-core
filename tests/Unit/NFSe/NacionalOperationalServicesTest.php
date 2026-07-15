<?php

namespace Tests\Unit\NFSe;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Contracts\NfseNacionalCncInterface;
use sabbajohn\FiscalCore\Contracts\NfseNacionalParametrizacaoInterface;
use sabbajohn\FiscalCore\DTO\NFSe\Nacional\NacionalApiResult;
use sabbajohn\FiscalCore\Exceptions\NfseNacionalPreflightException;
use sabbajohn\FiscalCore\Services\NFSe\NacionalCncService;
use sabbajohn\FiscalCore\Services\NFSe\NacionalDistribuicaoService;
use sabbajohn\FiscalCore\Services\NFSe\NacionalEmissionContextResolver;
use sabbajohn\FiscalCore\Services\NFSe\NacionalParametrizacaoService;
use sabbajohn\FiscalCore\Services\NFSe\NacionalRestClient;
use sabbajohn\FiscalCore\Services\NFSe\NfseNacionalIssIncidenceResolver;
use sabbajohn\FiscalCore\Support\Cache\FileCacheStore;

final class NacionalOperationalServicesTest extends TestCase
{
    public function test_parametrizacao_monta_rotas_oficiais_e_aplica_cache_negativo(): void
    {
        $calls = [];
        $client = new NacionalRestClient(10, function (string $method, string $url) use (&$calls): array {
            $calls[] = [$method, $url];

            return str_ends_with($url, '/beneficio')
                ? ['status' => 404, 'body' => '', 'request_id' => 'req-negative']
                : ['status' => 200, 'body' => '{"aliquota":2.5}', 'request_id' => 'req-ok'];
        });
        $service = new NacionalParametrizacaoService('https://param.local', $client, $this->cache());

        $rate = $service->consultarAliquota('4209102', '010701', '2026-07-13');
        $negative = $service->consultarBeneficio('4209102', 'BEN-01', '2026-07-13');
        $cachedNegative = $service->consultarBeneficio('4209102', 'BEN-01', '2026-07-13');

        self::assertSame('encontrado', $rate->status);
        self::assertSame('nao_parametrizado', $negative->status);
        self::assertSame('cache', $cachedNegative->metadata['source']);
        self::assertSame('https://param.local/4209102/01.07.01.000/2026-07-13T00%3A00%3A00Z/aliquota', $calls[0][1]);
        self::assertSame('https://param.local/4209102/BEN-01/2026-07-13T00%3A00%3A00Z/beneficio', $calls[1][1]);
        self::assertCount(2, $calls);
    }

    public function test_cnc_usa_cad_e_seleciona_correspondencia_inequivoca(): void
    {
        $url = null;
        $client = new NacionalRestClient(10, function (string $method, string $requestUrl) use (&$url): array {
            $url = $requestUrl;

            return ['status' => 200, 'body' => json_encode(['dados' => [[
                'codMunicipio' => '4209102', 'inscricaoFederal' => '11222333000181',
                'inscricaoMunicipal' => '1234', 'situacao' => 'ATIVO', 'habilitado' => true,
            ]]]), 'request_id' => 'req-cnc'];
        });
        $service = new NacionalCncService('https://cnc.local', $client, $this->cache());

        $result = $service->consultarCadastroCnc('4209102', '11.222.333/0001-81', '1234');

        self::assertSame('encontrado', $result->status);
        self::assertTrue($result->data['correspondencia_inequivoca']);
        self::assertSame('ATIVO', $result->data['correspondencia']['situacao']);
        self::assertSame('https://cnc.local/cad?codMunicipio=4209102&inscricaoFederal=11222333000181', $url);
    }

    public function test_cnc_consulta_uma_vez_somente_com_municipio_e_inscricao_federal(): void
    {
        $urls = [];
        $client = new NacionalRestClient(10, function (string $method, string $url) use (&$urls): array {
            $urls[] = $url;

            return ['status' => 200, 'body' => json_encode(['dados' => [[
                'codMunicipio' => '1302603',
                'inscricaoFederal' => '01824852000166',
                'inscricaoMunicipal' => '7823201',
                'situacao' => 'ATIVO',
            ]]]), 'request_id' => 'req-cnc'];
        });
        $service = new NacionalCncService('https://cnc.local', $client, $this->cache());

        $result = $service->consultarCadastroCnc('1302603', '01.824.852/0001-66', '7823201');

        self::assertSame('encontrado', $result->status);
        self::assertSame('7823201', $result->metadata['inscricao_municipal_referencia']);
        self::assertCount(1, $urls);
        self::assertSame('https://cnc.local/cad?codMunicipio=1302603&inscricaoFederal=01824852000166', $urls[0]);
    }

    public function test_cnc_consulta_cadastro_sem_im_quando_parametro_opcional_nao_for_informado(): void
    {
        $urls = [];
        $client = new NacionalRestClient(10, function (string $method, string $url) use (&$urls): array {
            $urls[] = $url;

            return ['status' => 200, 'body' => json_encode(['dados' => [[
                'codMunicipio' => '1302603',
                'inscricaoFederal' => '01824852000166',
                'situacao' => 'ATIVO',
            ]]]), 'request_id' => 'req-sem-im'];
        });
        $service = new NacionalCncService('https://cnc.local', $client, $this->cache());

        $result = $service->consultarCadastroCnc('1302603', '01824852000166');

        self::assertSame('encontrado', $result->status);
        self::assertCount(1, $urls);
        self::assertSame(
            'https://cnc.local/cad?codMunicipio=1302603&inscricaoFederal=01824852000166',
            $urls[0],
        );
    }

    public function test_cnc_considera_ims_numericas_equivalentes_com_zeros_a_esquerda(): void
    {
        $client = new NacionalRestClient(10, static fn (): array => [
            'status' => 200,
            'body' => json_encode(['dados' => [[
                'codMunicipio' => '1302603',
                'inscricaoFederal' => '01824852000166',
                'inscricaoMunicipal' => '000000007823201',
            ]]]),
            'request_id' => 'req-equivalent',
        ]);
        $service = new NacionalCncService('https://cnc.local', $client, $this->cache());

        $result = $service->consultarCadastroCnc('1302603', '01824852000166', '7823201');

        self::assertTrue($result->data['correspondencia_inequivoca']);
        self::assertSame('000000007823201', $result->data['correspondencia']['inscricaoMunicipal']);
    }

    public function test_cnc_interpreta_lista_cadastro_municipal_e_inf_cad(): void
    {
        $url = null;
        $client = new NacionalRestClient(10, function (string $method, string $requestUrl) use (&$url): array {
            $url = $requestUrl;

            return ['status' => 200, 'body' => json_encode([
                'ListaCadastroMunicipal' => [[
                    'CodigoMunicipio' => 1302603,
                    'InfCad' => [
                        'Inscricao' => '01824852000832',
                        'TpInscricao' => 'CNPJ',
                        'InscricaoMunicipal' => '      665940001',
                        'RazaoSocial' => 'AGROAM - AGRICOLA AMAZONAS COMERCIAL LTDA',
                        'SituacaoEmissaoNFSe' => 'HABILITADO',
                        'SituacaoCadastral' => 'Ativo',
                    ],
                ]],
                'StatusProcessamento' => 'DOCUMENTOS_LOCALIZADOS',
            ]), 'request_id' => 'req-cnc-real'];
        });
        $service = new NacionalCncService('https://cnc.local', $client, $this->cache());

        $result = $service->consultarCadastroCnc('1302603', '01824852000832', '665940001');

        self::assertSame('encontrado', $result->status);
        self::assertTrue($result->data['correspondencia_inequivoca']);
        self::assertSame('      665940001', $result->data['correspondencia']['InfCad']['InscricaoMunicipal']);
        self::assertSame('https://cnc.local/cad?codMunicipio=1302603&inscricaoFederal=01824852000832', $url);
    }

    public function test_distribuicao_usa_superficie_de_contribuintes(): void
    {
        $urls = [];
        $client = new NacionalRestClient(10, function (string $method, string $url) use (&$urls): array {
            $urls[] = $url;

            return ['status' => 200, 'body' => '{}', 'request_id' => 'req-dist'];
        });
        $service = new NacionalDistribuicaoService('https://adn.local/contribuintes', $client);

        $service->distribuirDfe('00042', '11222333000181');
        $service->consultarEventosDistribuidos('NFS123ABC');

        self::assertSame('https://adn.local/contribuintes/DFe/00042?cnpjConsulta=11222333000181&lote=true', $urls[0]);
        self::assertSame('https://adn.local/contribuintes/NFSe/NFS123ABC/Eventos', $urls[1]);
    }

    public function test_transporte_repete_somente_get_temporariamente_indisponivel(): void
    {
        $attempts = 0;
        $client = new NacionalRestClient(10, function () use (&$attempts): array {
            $attempts++;

            return $attempts < 3
                ? ['status' => 503, 'body' => '', 'request_id' => 'req-retry-'.$attempts]
                : ['status' => 200, 'body' => '{}', 'request_id' => 'req-ok'];
        });

        $response = $client->get('https://api.local/resource');

        self::assertSame(3, $attempts);
        self::assertSame(200, $response['status']);
    }

    public function test_preflight_bloqueia_cnc_inequivocamente_inativo_com_erro_estruturado(): void
    {
        $resolver = new NacionalEmissionContextResolver(
            $this->parametrizacaoStub(),
            new class implements NfseNacionalCncInterface
            {
                public function consultarCadastroCnc(string $municipio, string $inscricaoFederal, ?string $inscricaoMunicipal = null, bool $forceRefresh = false): NacionalApiResult
                {
                    return new NacionalApiResult('encontrado', [
                        'correspondencia' => ['situacao' => 'INATIVO', 'habilitado' => false],
                        'correspondencia_inequivoca' => true,
                    ], metadata: ['stale' => false, 'request_id' => 'req-block']);
                }
            },
            new NfseNacionalIssIncidenceResolver,
        );

        try {
            $resolver->resolve($this->payload());
            self::fail('Era esperado bloqueio CNC.');
        } catch (NfseNacionalPreflightException $e) {
            self::assertSame('NFSE_NACIONAL_CNC_NAO_HABILITADO', $e->details['code']);
            self::assertSame('payload.emitente.cpf_cnpj', $e->details['path']);
            self::assertSame('req-block', $e->details['request_id']);
        }
    }

    public function test_preflight_aplica_representacao_exata_da_im_retornada_pelo_cnc(): void
    {
        $cnc = new class implements NfseNacionalCncInterface
        {
            public ?string $receivedIm = 'not-called';

            public function consultarCadastroCnc(string $municipio, string $inscricaoFederal, ?string $inscricaoMunicipal = null, bool $forceRefresh = false): NacionalApiResult
            {
                $this->receivedIm = $inscricaoMunicipal;

                return new NacionalApiResult('encontrado', [
                    'correspondencia' => [
                        'InfCad' => [
                            'InscricaoMunicipal' => '        7823201',
                            'SituacaoEmissaoNFSe' => 'HABILITADO',
                        ],
                    ],
                    'correspondencia_inequivoca' => true,
                ], metadata: ['stale' => false, 'request_id' => 'req-im']);
            }
        };
        $payload = $this->payload();
        $payload['prestador']['inscricaoMunicipal'] = '7823201';

        $result = (new NacionalEmissionContextResolver(
            $this->parametrizacaoStub(),
            $cnc,
            new NfseNacionalIssIncidenceResolver,
        ))->resolve($payload);

        self::assertSame('7823201', $cnc->receivedIm);
        self::assertSame('        7823201', $result['payload']['prestador']['inscricaoMunicipal']);
        self::assertSame('        7823201', $result['payload']['prestador']['IM']);
        self::assertTrue($result['payload']['prestador']['enviarIM']);
        self::assertSame('prestador.inscricaoMunicipal', $result['context']['decisions'][0]['field']);

        $legacyContext = $result['context'];
        $legacyContext['decisions'] = [];
        $reused = (new NacionalEmissionContextResolver(
            $this->parametrizacaoStub(),
            $cnc,
            new NfseNacionalIssIncidenceResolver,
        ))->reuse($payload, $legacyContext);

        self::assertSame('        7823201', $reused['payload']['prestador']['inscricaoMunicipal']);
        self::assertTrue($reused['payload']['prestador']['enviarIM']);
    }

    public function test_preflight_omite_im_quando_cnc_confirma_ausencia_de_informacoes_complementares(): void
    {
        $payload = $this->payload();
        $resolver = new NacionalEmissionContextResolver(
            $this->parametrizacaoStub(),
            new class implements NfseNacionalCncInterface
            {
                public function consultarCadastroCnc(string $municipio, string $inscricaoFederal, ?string $inscricaoMunicipal = null, bool $forceRefresh = false): NacionalApiResult
                {
                    return new NacionalApiResult('nao_parametrizado', [
                        'cadastros' => [],
                        'correspondencia' => null,
                        'quantidade_correspondencias' => 0,
                        'correspondencia_inequivoca' => false,
                    ], metadata: ['source' => 'remote', 'stale' => false, 'request_id' => 'req-sem-cadastro']);
                }
            },
            new NfseNacionalIssIncidenceResolver,
        );

        $result = $resolver->resolve($payload);

        self::assertFalse($result['payload']['prestador']['enviarIM']);
        self::assertSame('prestador.enviarIM', $result['context']['decisions'][0]['field']);
        self::assertFalse($result['context']['decisions'][0]['value']);
        self::assertSame('sem_informacoes_complementares', $result['context']['decisions'][0]['reason']);

        $legacyContext = $result['context'];
        $legacyContext['decisions'] = [];
        $reused = $resolver->reuse($payload, $legacyContext);

        self::assertNotNull($reused);
        self::assertFalse($reused['payload']['prestador']['enviarIM']);
    }

    public function test_preflight_bloqueia_transmissao_quando_im_nao_identifica_registro_unico_no_cnc(): void
    {
        $resolver = new NacionalEmissionContextResolver(
            $this->parametrizacaoStub(),
            new class implements NfseNacionalCncInterface
            {
                public function consultarCadastroCnc(string $municipio, string $inscricaoFederal, ?string $inscricaoMunicipal = null, bool $forceRefresh = false): NacionalApiResult
                {
                    return new NacionalApiResult('encontrado', [
                        'cadastros' => [['InfCad' => ['InscricaoMunicipal' => '123']]],
                        'correspondencia' => null,
                        'correspondencia_inequivoca' => false,
                    ], metadata: ['stale' => false, 'request_id' => 'req-im-ambigua']);
                }
            },
            new NfseNacionalIssIncidenceResolver,
        );

        try {
            $resolver->resolve($this->payload());
            self::fail('Era esperado bloqueio antes da transmissão.');
        } catch (NfseNacionalPreflightException $e) {
            self::assertSame('NFSE_NACIONAL_CNC_IM_NAO_CONFIRMADA', $e->details['code']);
            self::assertSame('payload.emitente.inscricao_municipal', $e->details['path']);
        }
    }

    public function test_contexto_materializado_pode_ser_reutilizado_sem_nova_consulta(): void
    {
        $resolver = new NacionalEmissionContextResolver($this->parametrizacaoStub(), new class implements NfseNacionalCncInterface
        {
            public int $calls = 0;

            public function consultarCadastroCnc(string $municipio, string $inscricaoFederal, ?string $inscricaoMunicipal = null, bool $forceRefresh = false): NacionalApiResult
            {
                $this->calls++;

                return new NacionalApiResult('nao_parametrizado', metadata: ['stale' => false]);
            }
        }, new NfseNacionalIssIncidenceResolver);
        $first = $resolver->resolve($this->payload());
        $reused = $resolver->reuse($this->payload(), $first['context']);

        self::assertNotNull($reused);
        self::assertTrue($reused['context']['reused']);
        self::assertArrayHasKey('valid_until', $reused['context']);
    }

    public function test_incidencia_iss_e_resolvida_pela_tabela_oficial_embarcada(): void
    {
        $resolver = new NfseNacionalIssIncidenceResolver;
        $base = $this->payload();
        $base['prestador']['codigoMunicipio'] = '4209102';
        $base['tomador']['endereco']['codigoMunicipio'] = '3550308';
        $base['servico']['cLocPrestacao'] = '3304557';

        $base['servico']['cTribNac'] = '170501';
        $customer = $resolver->resolve($base);
        $base['servico']['cTribNac'] = '070201';
        $prestation = $resolver->resolve($base);
        $base['servico']['cTribNac'] = '010101';
        $issuer = $resolver->resolve($base);

        self::assertSame('3550308', $customer['codigo_municipio']);
        self::assertSame('3304557', $prestation['codigo_municipio']);
        self::assertSame('4209102', $issuer['codigo_municipio']);
    }

    public function test_beneficio_explicito_recebe_reducao_oficial_somente_no_payload_interno(): void
    {
        $parameters = $this->parametrizacaoStub();
        $parameters = new class($parameters) implements NfseNacionalParametrizacaoInterface
        {
            public function __construct(private NfseNacionalParametrizacaoInterface $base) {}

            public function consultarAliquota(string $municipio, string $servico, string $competencia, bool $forceRefresh = false): NacionalApiResult
            {
                return $this->base->consultarAliquota($municipio, $servico, $competencia, $forceRefresh);
            }

            public function consultarHistoricoAliquotas(string $municipio, string $servico, bool $forceRefresh = false): NacionalApiResult
            {
                return $this->base->consultarHistoricoAliquotas($municipio, $servico, $forceRefresh);
            }

            public function consultarBeneficio(string $municipio, string $beneficio, string $competencia, bool $forceRefresh = false): NacionalApiResult
            {
                return new NacionalApiResult('encontrado', ['nBM' => $beneficio, 'cTribNac' => '010701', 'pRedBCBM' => 25], metadata: ['stale' => false]);
            }

            public function consultarConvenio(string $municipio, bool $forceRefresh = false): NacionalApiResult
            {
                return $this->base->consultarConvenio($municipio, $forceRefresh);
            }

            public function consultarRegimesEspeciais(string $municipio, string $servico, string $competencia, bool $forceRefresh = false): NacionalApiResult
            {
                return $this->base->consultarRegimesEspeciais($municipio, $servico, $competencia, $forceRefresh);
            }

            public function consultarRetencoes(string $municipio, string $competencia, bool $forceRefresh = false): NacionalApiResult
            {
                return $this->base->consultarRetencoes($municipio, $competencia, $forceRefresh);
            }
        };
        $payload = $this->payload();
        $payload['tributacao']['municipal']['BM'] = ['nBM' => 'BEN-01'];
        $result = (new NacionalEmissionContextResolver($parameters, $this->cncNotFound(), new NfseNacionalIssIncidenceResolver))->resolve($payload);

        self::assertArrayNotHasKey('pRedBCBM', $payload['tributacao']['municipal']['BM']);
        self::assertSame(25.0, $result['payload']['tributacao']['municipal']['BM']['pRedBCBM']);
        self::assertSame('parametrizacao_nacional', $result['context']['decisions'][0]['source']);
    }

    public function test_retencao_declarada_em_contradicao_com_parametrizacao_e_bloqueada(): void
    {
        $base = $this->parametrizacaoStub();
        $parameters = new class($base) implements NfseNacionalParametrizacaoInterface
        {
            public function __construct(private NfseNacionalParametrizacaoInterface $base) {}

            public function consultarAliquota(string $municipio, string $servico, string $competencia, bool $forceRefresh = false): NacionalApiResult
            {
                return $this->base->consultarAliquota($municipio, $servico, $competencia, $forceRefresh);
            }

            public function consultarHistoricoAliquotas(string $municipio, string $servico, bool $forceRefresh = false): NacionalApiResult
            {
                return $this->base->consultarHistoricoAliquotas($municipio, $servico, $forceRefresh);
            }

            public function consultarBeneficio(string $municipio, string $beneficio, string $competencia, bool $forceRefresh = false): NacionalApiResult
            {
                return $this->base->consultarBeneficio($municipio, $beneficio, $competencia, $forceRefresh);
            }

            public function consultarConvenio(string $municipio, bool $forceRefresh = false): NacionalApiResult
            {
                return $this->base->consultarConvenio($municipio, $forceRefresh);
            }

            public function consultarRegimesEspeciais(string $municipio, string $servico, string $competencia, bool $forceRefresh = false): NacionalApiResult
            {
                return $this->base->consultarRegimesEspeciais($municipio, $servico, $competencia, $forceRefresh);
            }

            public function consultarRetencoes(string $municipio, string $competencia, bool $forceRefresh = false): NacionalApiResult
            {
                return new NacionalApiResult('encontrado', ['retencaoObrigatoria' => true], metadata: ['stale' => false, 'request_id' => 'req-ret']);
            }
        };
        $payload = $this->payload();
        $payload['tributacao']['municipal']['tpRetISSQN'] = '1';

        $this->expectException(NfseNacionalPreflightException::class);
        $this->expectExceptionMessage('Parametrização exige retenção do ISS');
        (new NacionalEmissionContextResolver($parameters, $this->cncNotFound(), new NfseNacionalIssIncidenceResolver))->resolve($payload);
    }

    private function parametrizacaoStub(): NfseNacionalParametrizacaoInterface
    {
        return new class implements NfseNacionalParametrizacaoInterface
        {
            public function consultarAliquota(string $municipio, string $servico, string $competencia, bool $forceRefresh = false): NacionalApiResult
            {
                return new NacionalApiResult('nao_parametrizado', metadata: ['stale' => false]);
            }

            public function consultarHistoricoAliquotas(string $municipio, string $servico, bool $forceRefresh = false): NacionalApiResult
            {
                return new NacionalApiResult('nao_parametrizado', metadata: ['stale' => false]);
            }

            public function consultarBeneficio(string $municipio, string $beneficio, string $competencia, bool $forceRefresh = false): NacionalApiResult
            {
                return new NacionalApiResult('nao_parametrizado', metadata: ['stale' => false]);
            }

            public function consultarConvenio(string $municipio, bool $forceRefresh = false): NacionalApiResult
            {
                return new NacionalApiResult('encontrado', ['aderenteEmissorNacional' => true], metadata: ['stale' => false]);
            }

            public function consultarRegimesEspeciais(string $municipio, string $servico, string $competencia, bool $forceRefresh = false): NacionalApiResult
            {
                return new NacionalApiResult('nao_parametrizado', metadata: ['stale' => false]);
            }

            public function consultarRetencoes(string $municipio, string $competencia, bool $forceRefresh = false): NacionalApiResult
            {
                return new NacionalApiResult('nao_parametrizado', metadata: ['stale' => false]);
            }
        };
    }

    private function cncNotFound(): NfseNacionalCncInterface
    {
        return new class implements NfseNacionalCncInterface
        {
            public function consultarCadastroCnc(string $municipio, string $inscricaoFederal, ?string $inscricaoMunicipal = null, bool $forceRefresh = false): NacionalApiResult
            {
                return new NacionalApiResult('nao_parametrizado', metadata: ['stale' => false]);
            }
        };
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'dCompet' => '2026-07-13', 'cLocEmi' => '4209102',
            'prestador' => ['cnpj' => '11222333000181', 'codigoMunicipio' => '4209102', 'inscricaoMunicipal' => '1234'],
            'tomador' => ['endereco' => ['codigoMunicipio' => '4209102']],
            'servico' => ['cTribNac' => '010701', 'cLocPrestacao' => '4209102', 'enviarPAliq' => false],
            'tributacao' => ['municipal' => []],
        ];
    }

    private function cache(): FileCacheStore
    {
        return new FileCacheStore(sys_get_temp_dir().'/nfse-national-ops-'.bin2hex(random_bytes(6)));
    }
}
