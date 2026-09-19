<?php

namespace sabbajohn\FiscalCore\Renderers\NFSe;

use sabbajohn\FiscalCore\Contracts\MunicipalDanfseRendererInterface;
use sabbajohn\FiscalCore\Helpers\NFSe\Formatter;
use sabbajohn\FiscalCore\Helpers\NFSe\QrCodeGen;
use Dompdf\Dompdf;
use Dompdf\Options;

class NacionalDanfseRenderer implements MunicipalDanfseRendererInterface
{
    /**
     * Logo oficial reduzida e incorporada para manter HTML e PDF idênticos.
     * O Dompdf roda sem acesso remoto, portanto uma URL externa não é confiável.
     */
    private const LOGO_DATA_URI = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAZAAAABRCAMAAADLogBbAAAAIGNIUk0AAHomAACAhAAA+gAAAIDoAAB1MAAA6mAAADqYAAAXcJy6UTwAAAA2UExURf///1qPYJ6kUeO/PjNEiluQYV6QX9G3ROjBPTZKh26WWzxVgWB/dURQg0dUjXh9lHyBmHl+lV4o7CEAAAABdFJOUwBA5thmAAAAAWJLR0QAiAUdSAAAAAd0SU1FB+oIBBUbIEysxBsAAAmZSURBVHja7Z3rgqsoDIDFHafjqQvy/i+7XoAkkHCpdtqzNX+m42Bs8xlCQux03SWXXHLJJU2isLz6zbyDKCr9PyD9V0YyOngpX/uCsppkIPLd336CxEBGAUikQxCVXruB3afIZsudiho2Y/wBIrc6IO5EFfTsOv2xcDy6cqs7fYR44w3IiOAjt7s8Y92xkt32A3I3z2PwUCIgj0xwnyD+5qZhJLjIJHvInVMSuUZ4OcQ2fizkfIDsphooFpi0ehnIFCtxpve6kE5/DJ1xARHEAYkcJbjIbZKA3FWsJLjIMFBnGVxggTNqeHw2kEoXQUCmIVYSuUMGSBWPjwaSiBBFRuQgQ1YJrNrQutcPv4DIIgD5vrGT1oh4qIIS4h8kD7mAZEQAglwkCiOeRwZIITHM2P4CIgL5vmWI3KOsQlLCmz3vCheQIY7osYv83Po7nrLu0w4AK4G0g6mQxJcszUwXEDYEoJLWgmT61wHxOCIgQzWQmlBxAWHkzw+W261fZJpQwoeVQAhXjUDEUa+2zIsE59aRi/Q/ifS8KWnOcQqQjxVchlI0OP+5iUCGTFBvA/Lqz/92kgHCuMgF5OmiBmxiRYodqYtcQJ4uakAEKJBB9XIMeRjIFULyQqasOMVLXEQM6g97yEUkkiyQxEUuIE+XCEjBRZ4A5CJCJQVC0pL++UAuIkRwTreZhi5+Ixc5f5V1EYkElU52S+K6VOIiZSDNmfqFhAoDhMxZ37ezPeQikhVSXAxHkKl6PoacDORi4gVSQQBC7ERcJBPUh6NALiSboC5Qb5FMLiIDGeqBXC29OSHW98eI4J2qc4DkuhykU/S6PbbI15d+tcnCW2KOmcNaWSCii5T3Q6KKMW9qlROBBhaZiQGLtNnGOGk40zDj5sNEOCDIYFt31Q0BQb3yghKp6aS+s7eAI4fEgEVsk23mRay1syI6moHYw7MuD4RskuA+xgogxFtQW2kDEfKphOZ7wUg2cOCAGNHUCII54CFPApI8VIWmrGFQBQ9RFUDqiYxfLUT8rHMESK38KhCh1bcmD6kDUktkjGYpDd2TrJEmbae/HEicGMJhsHBfzkOKkqy+apBw1teEUGIk5Wzr7RVidWeUsT5uT8aQ94OBwImqQzoM1RYB2Y/b5KJPARJcpKramz4fwgMpEFnt7fvzItuPoousVjAEyMbAbIbafm6HJxMOpkD2oL6ftx3Vy2u1v8bnYYu70cp5yPJSm7ZVRQGI4CJVHoJ36REmLj8puIjzhTE5TySyAZkN2MtjmXVsQ/obAaLpHxfr0ovMKjrdGd9YcumH1sCkL0sE4l2kZcqKV1t8wpj1EXFu0jkgnfcHbBSCJxgWXlsbpqUdCFjTWPYqFJnXaNAJD01afFDvkmSvD8veYOwCEIWexpWB5JBMcvSW/rLbYAMCVMD4MRD4dTYREBhqp+QqNlIWZqfNdwDP04B4F6kDImSG8nuQZBRXU1qYy9BkAVS61Mp6iwUYCHwcP9SHCjLz7EEkAgJD3EWdzA+UeUQgbBm+Hxo8JI0JmbeRcRH2hJGfzEIkdy8REOwwawyOPCQGEiICMaubDSMgGoCo7aIKFWKeBWRzkX5AayhBSUVxsRrJKFZJvvJAFgtlgPiwnwfiBuBRkw28BQ/ZgCRz3ClAumjh2p+1H9KA5E6nJQ0EhLAOLqANjq/Uhu4oNjVao6Kpfxtok1FTHNTnyZ+qugeDeQWQjgJZd6r63eIMkKbyezWRCducICgA6UKAVtj2/g53dq0DguYsknOSZS8BcqTmmwOSluF7/L0ZpwPpskA0WVkVgVifGrh8DnNRbnWKKMw8EKRjf6U3rCkQlzba6IRzgUQGWlxEBIJ6iQ4ASYmgMMEVGJNlVjqvm3VBO6NQ4tKTaYm8KFwnMcQuI0IhZLa7rfdUX6dr6PUS6wjwvagS0AAExe4srdVFeqi+nx3UeSJjIxDMBoxFLKfhmGFGh9canafDkklv5+8VFXq5bYDGZzxmAaF0wtnn++aAHGsDaiEywqQ0NgL5S6UAJHKRmjzkZCCCh4xjfi/3r5U8kITI8/IQr0oEIqUe/zPJBvXw3X2KE0HJ04BsK6v/O48SkMxj078C5AMIxAYoAlEvBEISwzcQXbl0OnAXlYDEVd8nA1GHgay1WFNZTKpamvrSLdleeVBvxfUqgDAsDqyypOvAX5HcW1e2Gu28Vhiuxr7GTs1ABMvXnF4EwhI58HyIyhGJrzO2ekhjNlYzmqSVjEXTfHytPfKaK653NpCSh2Qvxc1YbVH9eCtnasQCkJMvWQYifl+cMKIOiCrWBfYZq23O0r8P5FBplzNQPjHcRMhFsJJHgIjfXEYcpM1F6Pyx1vegZ8esNUEoB09QlV13c2dfwrXLokARJan1oXS4rSF881HY/LUWb13aOVQpldc576VmjcbWA1HPAlKQe7ZgpTVDaiINb6jPQbm4DEVgjXcONXSp6OiuZ4BsVXgX5pV1ii0Efg2NQbMySodysz99HajDm1P4cjVAOqVeAiTUEzlFUh/QDLc33k4y/j7EXW5uXzfdp8KHjI2XvdS87hft/Yqo0H5bBo9NlgGGAFEVQAb1AiDoQQSBB9+ZNce9pBab2G0aWmTfdOcP92IxQEivFwBxILCKENQQijTq4H6hiqDuzBj3IT4bCDJ7anid8Z14R2/7uNAx525yeD1DGonauExyLBy2hK0HEt0GXrn71SIglIfGG2jVQIYqIEMZSDWRfbSftGh3LzzCwwf8fQK3uywxVZNWEjBK0lViq4AsMdrpZoDMRIVmgJCmFBN3GeOcLpdBDzEQZtk7nAgkMX2o+2r8hefCRbbpYiYfHIBAJ8mjQMgtLgHRkodgIGkB4NeBNH4RP/c821cZyNxFsRPtn69b37gPqHnKygIJwxWhzU9ZyvWAEyC+byFf86D/IkTI1Leve3/8OXV+7hzbebg4LgAxodSUBPXJj2IaqdkTGCAWq2CBJLA5IMUv3I3+JYViPWSo8JAykWi4ACOTL5p0NYOBWNq2i8ZpbtmbAiEuYikQr0LNIhBwXaZjL+QhFVVB+aFagFr+8pkSE2Z02twgRXOz3f5g1tBbglt+UHuPgXH+jjYxymTZu/yYfGbnE0EI30QFF9R9Z7EfRVdZXd4UohVr/9KIRBqNIvmYf059rVOESLDkJPPe+ENyL0gktD/L10tMUsswaEMkNNxBrWW1Kp3IdhXR3EcfH9pWaVPnGr2WH++yL1oFA6hotlxyhhxqx73kfLmAvJtMF5H3kstF3koefqr8ZPkP+Dat53r0Jw0AAAAldEVYdGRhdGU6Y3JlYXRlADIwMjYtMDgtMDRUMjE6Mjc6MTMrMDA6MDDY98jjAAAAJXRFWHRkYXRlOm1vZGlmeQAyMDI2LTA4LTA0VDIxOjI3OjEzKzAwOjAwqapwXwAAACh0RVh0ZGF0ZTp0aW1lc3RhbXAAMjAyNi0wOC0wNFQyMToyNzozMiswMDowMBrtXUkAAAAASUVORK5CYII=';

    public function prepare(array $dados, array $context = []): array
    {
        $dados['logo'] = self::LOGO_DATA_URI;
        $dados['identificacao']['competencia'] = Formatter::data($dados['identificacao']['competencia'] ?? null);
        $dados['identificacao']['emissao_nfse'] = Formatter::dataHora($dados['identificacao']['emissao_nfse'] ?? null);
        $dados['identificacao']['emissao_dps'] = Formatter::dataHora($dados['identificacao']['emissao_dps'] ?? null);
        $dados['prestador']['documento'] = Formatter::documento($dados['prestador']['documento'] ?? null);
        $dados['prestador']['telefone'] = Formatter::telefone($dados['prestador']['telefone'] ?? null);
        $dados['prestador']['endereco'] = Formatter::endereco($dados['prestador']['endereco_logradouro'] ?? null, $dados['prestador']['endereco_numero'] ?? null, $dados['prestador']['endereco_complemento'] ?? null);
        $dados['prestador']['municipio_uf'] = Formatter::municipioUf($dados['prestador']['endereco_municipio'] ?? null, $dados['prestador']['endereco_estado'] ?? null);
        $dados['prestador']['ibge_cep'] = Formatter::ibgeCep($dados['prestador']['codigo_ibge'] ?? null, $dados['prestador']['endereco_cep'] ?? null);

        $dados['identificacao']['numero_nfse'] = Formatter::vazio($dados['identificacao']['numero_nfse'] ?? null);
        $dados['identificacao']['numero_dps'] = Formatter::vazio($dados['identificacao']['numero_dps'] ?? null);
        $statusCode = trim((string) ($dados['identificacao']['status'] ?? ''));
        $dados['identificacao']['status_code'] = Formatter::vazio($statusCode);
        $dados['identificacao']['status'] = match ($statusCode) {
            '100' => 'NFS-e gerada',
            // Embora o leiaute 1.01 liste apenas os códigos de situação
            // vigentes, o retorno do ADN para a emissão por substituição usa
            // cStat 101. O vínculo também está em subst/chSubstda.
            '101' => 'NFS-e de substituição gerada',
            '102' => 'NFS-e por decisão judicial',
            '103' => 'NFS-e avulsa',
            '107' => 'NFS-e MEI',
            default => Formatter::vazio($statusCode),
        };

        $chave = $dados['identificacao']['chave'] ?? '';
        if (str_starts_with($chave, 'NFS')) {
            $chave = substr($chave, 3);
        }
        $dados['identificacao']['chave'] = Formatter::vazio($chave);

        $dados['qrcode'] = $dados['identificacao']['chave'] ? QrCodeGen::image($dados['identificacao']['chave']) : null;
        $dados['url_consulta'] = QrCodeGen::url($dados['identificacao']['chave'] ?? '');

        // O XML assinado 1.01 não é alterado pelos eventos. A situação atual
        // vem do documento/evento distribuído e é fornecida pelo chamador.
        $danfseContext = is_array($context['danfse'] ?? null) ? $context['danfse'] : [];
        $dados['cancelada'] = (bool) ($danfseContext['cancelada'] ?? false);
        $dados['substituida'] = (bool) ($danfseContext['substituida'] ?? false)
            || $statusCode === '101';

        $dados['ibscbs']['ibge'] = '';
        $dados['ibscbs']['uf'] = '';
        $dados['ibscbs']['iimu'] = $dados['ibscbs']['indicador_operacao'].' / '.$dados['ibscbs']['ibge'].' / '.$dados['ibscbs']['municipio'].' / '.$dados['ibscbs']['uf'];

        $dados['ibscbs']['ibsufmun'] = $dados['ibscbs']['aliquota_ibs_uf'].'% / '.$dados['ibscbs']['aliquota_ibs_municipio'].'%';

        foreach (['codigo_tributacao', 'codigo_nbs', 'descricao_tributacao', 'descricao', 'local_prestacao', 'codigo_municipio'] as $campo) {
            $dados['servico'][$campo] = Formatter::vazio($dados['servico'][$campo] ?? null);
        }

        if (! empty($dados['tomador'])) {
            $dados['tomador']['documento'] = Formatter::documento($dados['tomador']['documento'] ?? null);
            $dados['tomador']['telefone'] = Formatter::telefone($dados['tomador']['telefone'] ?? null);
            $dados['tomador']['endereco'] = Formatter::endereco($dados['tomador']['endereco_logradouro'] ?? null, $dados['tomador']['endereco_numero'] ?? null, $dados['tomador']['endereco_complemento'] ?? null);
            $dados['tomador']['municipio_uf'] = Formatter::municipioUf($dados['tomador']['endereco_municipio'] ?? null, $dados['tomador']['endereco_estado'] ?? null);
            $dados['tomador']['ibge_cep'] = Formatter::ibgeCep($dados['tomador']['codigo_ibge'] ?? null, $dados['tomador']['endereco_cep'] ?? null);
        }

        if (! empty($dados['destinatario'])) {
            $dados['destinatario']['documento'] = Formatter::documento($dados['destinatario']['documento'] ?? null);
            $dados['destinatario']['telefone'] = Formatter::telefone($dados['destinatario']['telefone'] ?? null);
            $dados['destinatario']['endereco'] = Formatter::endereco($dados['destinatario']['endereco_logradouro'] ?? null, $dados['destinatario']['endereco_numero'] ?? null, $dados['destinatario']['endereco_complemento'] ?? null);
            $dados['destinatario']['municipio_uf'] = Formatter::municipioUf($dados['destinatario']['endereco_municipio'] ?? null, $dados['destinatario']['endereco_estado'] ?? null);
            $dados['destinatario']['ibge_cep'] = Formatter::ibgeCep($dados['destinatario']['codigo_ibge'] ?? null, $dados['destinatario']['endereco_cep'] ?? null);
        }

        if (! empty($dados['intermediario'])) {
            $dados['intermediario']['documento'] = Formatter::documento($dados['intermediario']['documento'] ?? null);
            $dados['intermediario']['telefone'] = Formatter::telefone($dados['intermediario']['telefone'] ?? null);
            $dados['intermediario']['endereco'] = Formatter::endereco($dados['intermediario']['endereco_logradouro'] ?? null, $dados['intermediario']['endereco_numero'] ?? null, $dados['intermediario']['endereco_complemento'] ?? null);
            $dados['intermediario']['municipio_uf'] = Formatter::municipioUf($dados['intermediario']['endereco_municipio'] ?? null, $dados['intermediario']['endereco_estado'] ?? null);
            $dados['intermediario']['ibge_cep'] = Formatter::ibgeCep($dados['intermediario']['codigo_ibge'] ?? null, $dados['intermediario']['endereco_cep'] ?? null);
        }

        foreach (['deducoes', 'desconto_incondicionado', 'base_calculo', 'valor_issqn'] as $campo) {
            $dados['issqn'][$campo] = Formatter::moeda($dados['issqn'][$campo] ?? null);
        }

        foreach (['tipo_tributacao', 'municipio_incidencia', 'codigo_municipio', 'regime_especial', 'imunidade', 'suspensao', 'processo', 'beneficio', 'retencao'] as $campo) {
            $dados['issqn'][$campo] = Formatter::vazio($dados['issqn'][$campo] ?? null);
        }

        $dados['issqn']['aliquota'] = Formatter::percentual($dados['issqn']['aliquota'] ?? null);

        foreach ($dados['federal'] as $campo => $valor) {
            if ($campo !== 'descricao') {
                $dados['federal'][$campo] = Formatter::moeda($valor);
            }
        }

        foreach (['base', 'valor_ibs_uf', 'valor_ibs_municipio', 'valor_total_ibs', 'valor_cbs'] as $campo) {
            $dados['ibscbs'][$campo] = Formatter::moeda($dados['ibscbs'][$campo] ?? null);
        }

        foreach (['aliquota_ibs_uf', 'aliquota_ibs_municipio', 'aliquota_cbs', 'aliquota_efetiva_ibs_uf', 'aliquota_efetiva_ibs_municipio', 'aliquota_efetiva_cbs'] as $campo) {
            $dados['ibscbs'][$campo] = Formatter::percentual($dados['ibscbs'][$campo] ?? null);
        }

        foreach (['cst', 'indicador_operacao', 'municipio'] as $campo) {
            $dados['ibscbs'][$campo] = Formatter::vazio($dados['ibscbs'][$campo] ?? null);
        }

        $dados['totais']['total_final'] = 'R$ ';
        foreach ($dados['totais'] as $campo => $valor) {
            if ($campo === 'total_final') {
                continue;
            }

            $dados['totais'][$campo] = Formatter::moeda($valor);
        }

        $dados['totais']['valor_total_nota'] = Formatter::moeda($dados['totais']['valor_total_nota'] ?? null);

        foreach (['informacoes_complementares', 'informacoes_municipio', 'obra', 'inscricao_imobiliaria', 'evento', 'nfse_substituida'] as $campo) {
            $dados['informacoes'][$campo] = Formatter::vazio($dados['informacoes'][$campo] ?? null);
        }

        foreach (['tributos_aproximados_federal', 'tributos_aproximados_estadual', 'tributos_aproximados_municipal'] as $campo) {
            $dados['informacoes'][$campo] = Formatter::moeda($dados['informacoes'][$campo] ?? null);
        }

        return $dados;
    }

    public function render(string $xml, array $context = []): string
    {
        $html = $this->renderHtml($xml, $context);

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    public function renderHtml(string $xml, array $context = []): string
    {
        $xmlreader = new XmlReader;

        $dados = $this->prepare(
            $xmlreader->read($xml),
            $context,
        );

        return $this->buildHtml($dados);
    }

    private function buildHtml(array $dados): string
    {
        ob_start();

        require __DIR__.'/components/layout.php';

        return ob_get_clean();
    }
}
