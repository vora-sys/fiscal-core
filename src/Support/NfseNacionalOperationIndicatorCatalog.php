<?php

namespace sabbajohn\FiscalCore\Support;

/**
 * Domínio cIndOp aceito pelo leiaute Nacional.
 *
 * As opções exibidas para novos cadastros vêm do Anexo VII IndOp IBS/CBS
 * v1.02.00 (somente linhas com indNFSe = S). Códigos do leiaute anterior
 * permanecem aceitos para não invalidar documentos e cadastros existentes.
 */
final class NfseNacionalOperationIndicatorCatalog
{
    /** @var array<string,string> */
    private const NFSE_OPTIONS = [
        '010101' => 'Bem Móvel Material — Presencial, com retirada no estabelecimento do fornecedor',
        '010102' => 'Bem Móvel Material — Presencial, com retirada fora do estabelecimento do fornecedor',
        '010103' => 'Bem Móvel Material — Não presencial, com entrega ou disponibilização em endereço fornecido',
        '010106' => 'Bem Móvel Material — Locação de bem móvel material em aquisições realizadas de forma centralizada por contribuinte sujeito ao regime regular do IBS e da CBS que possui mais de um estabelecimento e que não estejam sujeitas a vedação à apropriação de créditos',
        '020101' => 'Operação com bem imóvel, bem imaterial, inclusive direito, relacionada a bem imóvel — Realização de operações com bem imóvel, bem imaterial, inclusive direito, relacionado a bem imóvel',
        '020201' => 'Serviço prestado fisicamente sobre bem imóvel — Execução de serviços diversos prestados fisicamente sobre bem imóvel',
        '020202' => 'Serviço prestado fisicamente sobre bem imóvel — Execução de serviços sobre Bens Imóveis de Características Especiais (BICE)',
        '020301' => 'Serviço de administração e intermediação de bem imóvel — Execução dos serviços de administração e intermediação de bens imóveis',
        '020401' => 'Serviços de locação, sublocação, arrendamento, direito de passagem ou permissão de uso, compartilhado ou não, de ferrovia, rodovia, postes, cabos, dutos e condutos de qualquer natureza — Execução de serviços sobre Bens Imóveis de Características Especiais (BICE)',
        '030101' => 'Serviço prestado fisicamente sobre a pessoa ou fruído presencialmente por pessoa física — Execução de serviços diversos exclusivamente prestados fisicamente sobre a pessoa ou integralmente fruídos presencialmente por pessoa física',
        '030102' => 'Serviço prestado fisicamente sobre a pessoa ou fruído presencialmente por pessoa física — Execução de serviços diversos exclusivamente prestados fisicamente sobre a pessoa ou integralmente fruídos presencialmente por pessoa física',
        '040101' => 'Serviço de planejamento, organização e administração de feiras, exposições, congressos, espetáculos, exibições e congêneres — Execução de serviços de planejamento, organização e administração de feiras, exposições, congressos, espetáculos, exibições e congêneres',
        '050101' => 'Serviço prestado fisicamente sobre bem móvel material — Execução de serviços diversos prestados fisicamente sobre bem móvel material',
        '050102' => 'Serviço prestado fisicamente sobre bem móvel material — Execução de serviços diversos prestados fisicamente sobre bem móvel material',
        '050201' => 'Serviços portuários — Execução de serviços portuários',
        '060101' => 'Serviço de transporte de passageiros — Execução de serviços de transporte de passageiros',
        '070101' => 'Serviço de transporte de carga — Execução de serviço de transporte de carga',
        '070102' => 'Serviço de transporte de carga — Execução de serviço de transporte de carga',
        '100101' => 'Cessão de espaço para prestação de serviços publicitários, em operações onerosas — Realização de operações de cessão de espaço para prestação de serviços publicitários',
        '100102' => 'Cessão de espaço para prestação de serviços publicitários, em operações onerosas — Realização de operações de cessão de espaço para prestação de serviços publicitários',
        '100201' => 'Cessão de espaço para prestação de serviços publicitários, em operações não onerosas — Realização de operações de cessão de espaço para prestação de serviços publicitários',
        '100301' => 'Demais serviços, em operações onerosas — Execução dos demais serviços em operações não especificadas em outros indicadores ou, nos serviços de que trata o inc. III do art. 11, esses sejam, ainda que parcialmente, prestados à distância',
        '100302' => 'Demais serviços, em operações onerosas — Execução dos demais serviços em operações não especificadas em outros indicadores ou, nos serviços de que trata o inc. III do art. 11, esses sejam, ainda que parcialmente, prestados à distância',
        '100401' => 'Demais serviços, em operações não onerosas — Execução dos demais serviços em operações não especificadas em outros indicadores ou, nos serviços de que tratam o inc. III, estes sejam, ainda que parcialmente, prestados à distância',
    ];

    /** @var array<string,true> */
    private const LEGACY_CODES = [
        '010101' => true,
        '010102' => true,
        '010103' => true,
        '010104' => true,
        '010105' => true,
        '010201' => true,
        '020101' => true,
        '020201' => true,
        '020301' => true,
        '030101' => true,
        '030102' => true,
        '030103' => true,
        '030104' => true,
        '040101' => true,
        '050101' => true,
        '050102' => true,
        '050103' => true,
        '050104' => true,
        '050201' => true,
        '060101' => true,
        '070101' => true,
        '070102' => true,
        '080101' => true,
        '090101' => true,
        '090102' => true,
        '100101' => true,
        '100102' => true,
        '100201' => true,
        '100301' => true,
        '100302' => true,
        '100401' => true,
        '100501' => true,
        '100502' => true,
        '100601' => true,
        '110101' => true,
        '110201' => true,
        '120101' => true,
        '130101' => true,
        '130201' => true,
    ];

    public static function contains(string $code): bool
    {
        return isset(self::NFSE_OPTIONS[$code]) || isset(self::LEGACY_CODES[$code]);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(self::NFSE_OPTIONS),
            array_keys(self::LEGACY_CODES),
        )));
    }

    /** @return list<array{value:string,label:string,description:string}> */
    public static function nfseOptions(): array
    {
        return array_map(
            static fn (string $code, string $description): array => [
                'value' => $code,
                'label' => sprintf('%s - %s', $code, $description),
                'description' => $description,
            ],
            array_keys(self::NFSE_OPTIONS),
            array_values(self::NFSE_OPTIONS),
        );
    }
}
