<?php
if (! function_exists('campo')) {
function campo(string $titulo, ?string $valor = null, $class = '', bool $html = false): void
{
    ?>
<div class="campo <?= htmlspecialchars($class) ?>">
    <div class="campo-label"> <?= htmlspecialchars($titulo) ?> </div>
    <?php if ($valor !== null) { ?>
        <div class="campo-valor">
            <?php if ($html) { ?>
                <?= $valor !== '' ? $valor : '-' ?>
            <?php } else { ?>
                <?= htmlspecialchars($valor !== '' ? $valor : '-') ?>
            <?php } ?>
        </div>
    <?php } ?>
</div>
<?php
}
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>DANFSe</title>
<style>
    :root{
        --border:.2pt solid #000;
        --texto:6.4pt;
        --cinza: rgb(238, 238, 238);
        --altura-linha: 1.15;
        --line: 6mm;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: Arial, Helvetica, sans-serif;
    }

    @page {
        size: A4;
        margin: 0;
    }

    body {
        margin: 0;
        font-family: Inter, Arial, Helvetica, sans-serif;
        background: #f5f5f5;
        color: #171717;
    }

    body {
        background: #ececec;
        color: #000;
        padding: 0;
        font-size: 7pt;
    }

    .page{
        position:absolute;
        top:0;
        left:0;
        /* Dompdf treats percentage width plus padding as wider than A4 in
           this layout. Reserve the 3mm horizontal padding explicitly. */
        width:207mm;
        max-width:207mm;
        /* Keep a small tolerance below the exact A4 height. Dompdf rounds
           millimetres to points and an exact 297mm box can overflow onto a
           second page, taking the absolutely positioned footer with it. */
        height:293mm;
        margin:0 auto;
        background:#fff;
        padding: .15cm;
        overflow:hidden;
    }

    .border {
        position: relative;
        /* .page has 1.5mm padding on each side. An explicit inner height is
           more reliable than a percentage in Dompdf's paged layout. */
        height: 287mm;
        padding: 1.2mm;
        border: 1pt solid #000;
    }

    hr {
    border: none;
    border-top: .5pt solid #000000;
    }

    .watermark{
        position:absolute;
        top:50%;
        left:50%;
        transform:translate(-50%,-50%) rotate(-32deg);
        font-size: 30mm;
        font-weight:bold;
        letter-spacing:2mm;
        opacity:.12;
        pointer-events:none;
        user-select:none;
        z-index:0;
        color: #000;
    }

    .border > *:not(.watermark):not(footer){
        position:relative;
        z-index:1;
    }

    .topo{
        display:table;
        table-layout:fixed;
        width:100%;
        max-width:100%;
    }

    .topo-esquerda,
    .qrcode-box {
        display:table-cell;
        vertical-align:top;
    }

    .topo-esquerda {
        width:75%;
    }

    .qrcode-box {
        width:25%;
    }

    header {
        display:table;
        table-layout:fixed;
        width:100%;
        min-height:11mm;
        background-color: rgb(238, 238, 238);
        /* border-bottom:.2pt solid #000; */
    }

    .header-logo{
        display:table-cell;
        width:40mm;
        vertical-align:middle;
        text-align:center;
        padding:.4mm;
    }

    .header-logo img {
        max-width: 34mm;
    }

    .header-documento{
        display:table-cell;
        vertical-align:middle;
        text-align:center;
        padding: 0.6mm;
    }

    .header-informacoes {
        display:table-cell;
        width:50mm;
        vertical-align:top;
        padding: 1.4mm;
        font-family: "Microsoft Sans Serif", Arial, sans-serif;
    }

    .mun {
        font-size: 8pt;
    }

    .amb,
    .tpamb {
        font-size: 6pt;
    }

    .titulo{
        font-size:9pt;
        font-weight:bold;
    }

    .subtitulo{
        font-size: 9pt;
        font-weight:bold;
    }

    .homologacao{
        color:#FF0000;
        font-size:9pt;
        font-weight:bold;
    }

    .section{
        width:100%;
        /* border-top:.2pt solid #000; */
        page-break-inside:avoid;
        break-inside:avoid;
    }

    .section:first-child{
        border-top:none;
    }

    .section-title{
        padding:.2mm 1mm;
        background:rgb(238, 238, 238);
        font-size:7pt;
        font-weight:bold;
        line-height:1.15;
        text-transform:uppercase;
    }

    .destaque {
        background:rgb(238, 238, 238);
        padding: 0.3mm;
    }

    .qrcode-box{
        text-align:center;
        padding:.3mm;
    }

    .qrcode-imagem{
        margin:0 auto .3mm;
        width: 1.52cm;
        height: 1.52cm;
    }

    .qrcode-imagem img{
        width:100%;
        height:100%;
        object-fit:contain;
    }

    .qrcode-texto{
        font-size:6pt;
        text-align:justify;
        white-space:normal;
        word-wrap:break-word;
        overflow-wrap:break-word;
    }

    .chave-acesso{
        display:table-cell;
        vertical-align:middle;
        font-size: 7pt;
        font-family:Consolas,"Courier New",monospace;
        letter-spacing:0;
        white-space:nowrap;
    }

    .chave-acesso-row {
        display:table;
        table-layout:fixed;
        width:100%;
        min-height:6mm;
    }

    .chave-acesso-label {
        display:table-cell;
        width:38mm;
        vertical-align:middle;
        padding:.2mm 1mm;
        font-size:6.4pt;
        font-weight:bold;
        text-transform:uppercase;
    }

    .row{
        display:table;
        table-layout:fixed;
        width:100%;
        max-width:100%;
    }

    .row > .campo,
    .row > .destaque {
        display:table-cell;
        vertical-align:top;
    }

    .row:first-of-type{
        border-top:none;
    }

    .campo{
        min-height: 6mm;
    }

    .campo-extra{
        display:inline-block;
        font-size: 6.4pt;
        font-weight:bold;
        padding:.2mm 1mm;
        font-weight:bold;
        min-height: 6mm;
        text-transform:uppercase;
    }

    .campo-label{
        padding:.2mm 1mm;
        font-size: 6.2pt;
        font-weight:bold;
        line-height:1.15;
    }

    .identificacao .campo-label {
        font-size: 7pt;
    }

    .campo-valor{
        margin: 0 .9mm;
        font-size: 7pt;
        font-family: "Microsoft Sans Serif", Arial, sans-serif;
        line-height:1.15;
        white-space:normal;
        word-wrap:break-word;
        overflow-wrap:anywhere;
    }

    .campo-descricao{
        min-height:42mm;
    }

    .campo-descricao .campo-valor{
        white-space:pre-wrap;
        align-items:flex-start;
        line-height:1.15;
        overflow-wrap:anywhere;
    }

    /* alinhamento */
    .d3 {
        /* As larguras explícitas mantêm o layout também no Dompdf, que não
           implementa CSS Grid/Flexbox. */
    }

    .d3 > * { width:33.3333%; }

    .d4 > * { width:25%; }

    .d55 > * { width:50%; }

    .d22 > * { width:25%; }

    .d522 > *:first-child { width:50%; }
    .d522 > *:not(:first-child) { width:25%; }

    .d252 > * { width:25%; }
    .d252 > *:nth-child(2) { width:50%; }

    .d225 > * { width:25%; }
    .d225 > *:last-child { width:50%; }

    .servico {
        padding-bottom: 1mm;
    }

    .desc-servico {
        min-height: 60pt;
    }

    .campo-compl{
        margin: 0 .9mm;
        font-size:6.4pt;
        line-height:1.15;
        white-space:normal;
    }

    footer{
        position:absolute;
        /* Dompdf positions an absolutely positioned table incorrectly when
           only bottom is supplied. Pinning the top keeps the receipt footer
           fully inside the first page. */
        top:279mm;
        left:0;
        width:100%;
        z-index:1;
        display:table;
        table-layout:fixed;
        min-height:8mm;
        border:.2pt solid #000;
    }

    footer > * {
        display:table-cell;
        vertical-align:top;
    }

    footer > *:nth-child(1),
    footer > *:nth-child(2) { width:25%; }
    footer > *:nth-child(3) { width:50%; }

    .footer-data,
    .footer-assin {
        border-right:.2pt solid #000;
    }

    .footer-data,
    .footer-assin,
    .footer-chaves{
        padding: 1mm;
    }

    @media print{
        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        body{
            background:#fff;
            padding:0;
        }

        .page{
            width:207mm;
            max-width:207mm;
            margin:0;
            border:none;
        }
    }

    @media screen {
        body {
            padding:12px;
        }
    }
</style>
</head>
<body>
    <div class="page">
        <div class="border">
            <?php if ($dados['cancelada'] ?? false) { ?>
                <div class="watermark cancelada">CANCELADA</div>
            <?php } elseif ($dados['substituida'] ?? false) { ?>
                <div class="watermark substituida">SUBSTITUÍDA</div>
            <?php } ?>
            <header class="header">
                <div class="header-logo"><img src="<?= htmlspecialchars($dados['logo'], ENT_QUOTES) ?>" alt="Logo NFS-e"></div>
                <div class="header-documento">
                    <h1 class="titulo">DANFSe v2.0</h1>
                    <p class="subtitulo">Documento Auxiliar da NFS-e </p>
                    <?php if (($dados['identificacao']['ambiente'] ?? '') === '2' || ($dados['identificacao']['ambiente'] ?? '') === 'Homologação') { ?>
                        <div class="homologacao"> NFS-e SEM VALIDADE JURÍDICA </div>
                    <?php } ?>
                </div>
                <div class="header-informacoes">
                    <div class="mun">Município: <?= htmlspecialchars($dados['identificacao']['municipio'] ?? '-') ?></div>
                    <div class="amb">Ambiente Gerador: <?= htmlspecialchars($dados['identificacao']['ambiente_gerador'] ?? '-') ?></div>
                    <div class="tpamb">Tipo Ambiente: <?= htmlspecialchars($dados['identificacao']['ambiente'] ?? '-') ?></div>
                </div>
            </header>
            <hr>
            <div class="topo">
                <div class="topo-esquerda">
                    <!-- IDENTIFICAÇÃO -->
                    <section class="section identificacao">
                        <div class="chave-acesso-row">
                            <div class="chave-acesso-label">CHAVE DE ACESSO DA NFS-e</div>
                            <div class="chave-acesso"><?= htmlspecialchars($dados['identificacao']['chave'] ?? '-') ?></div>
                        </div>
                        <div class="row d3">
                            <?php campo('NÚMERO DA NFS-e', $dados['identificacao']['numero_nfse'] ?? ''); ?>
                            <?php campo('COMPETÊNCIA DA NFS-e', $dados['identificacao']['competencia'] ?? ''); ?>
                            <?php campo('DATA E HORA DA EMISSÃO DA NFS-e', $dados['identificacao']['emissao_nfse'] ?? ''); ?>
                        </div>
                        <div class="row d3">
                            <?php campo('NÚMERO DA DPS', $dados['identificacao']['numero_dps'] ?? ''); ?>
                            <?php campo('SÉRIE DA DPS', $dados['identificacao']['serie_dps'] ?? ''); ?>
                            <?php campo('DATA E HORA DA EMISSÃO DA DPS', $dados['identificacao']['emissao_dps'] ?? ''); ?>
                        </div>
                        <div class="row d3">
                            <div class="destaque">
                                <div class="campo-label">EMITENTE DA NFS-e</div>
                                <div class="campo-valor"><?= htmlspecialchars($dados['prestador']['nome'] ?? '-') ?></div>
                            </div>
                            <?php campo('SITUAÇÃO DA NFS-e', $dados['identificacao']['status'] ?? ''); ?>
                            <?php campo('FINALIDADE', $dados['identificacao']['finalidade'] ?? ''); ?>
                        </div>
                    </section>
                </div>

                <aside class="qrcode-box">
                    <div class="qrcode-imagem">
                        <?php if (! empty($dados['identificacao']['chave'])) { ?>
                            <img src="<?= $dados['qrcode'] ?>" alt="QR Code">
                        <?php } ?>
                    </div>
                    <div class="qrcode-texto">
                        A autenticidade desta NFS-e pode ser verificada pela leitura deste código QR ou pela consulta da chave de acesso no Portal Nacional da NFS-e.
                    </div>
                </aside>
            </div>

            <hr>
            <!-- PRESTADOR -->
            <section class="section prestador">
                <div class="row d4">
                    <div class="campo section-title">PRESTADOR / FORNECEDOR</div>
                    <?php campo('CNPJ / CPF / NIF', $dados['prestador']['documento'] ?? ''); ?>
                    <?php campo('Indicador Municipal (Inscrição)', $dados['prestador']['inscricao_municipal'] ?? ''); ?>
                    <?php campo('Telefone', $dados['prestador']['telefone'] ?? ''); ?>
                </div>
                <div class="row d522">
                    <?php campo('Nome / Nome Empresarial', $dados['prestador']['nome'] ?? ''); ?>
                    <?php campo('Município / Sigla UF', $dados['prestador']['municipio_uf'] ?? ''); ?>
                    <?php campo('Código IBGE / CEP', $dados['prestador']['ibge_cep'] ?? ''); ?>
                </div>
                <div class="row d55">
                    <?php campo('Endereço', $dados['prestador']['endereco'] ?? ''); ?>
                    <?php campo('Email', $dados['prestador']['email'] ?? ''); ?>
                </div>
                <div class="row d22">
                    <?php campo('Simples Nacional na Data de Competência', $dados['prestador']['simples_nacional'] ?? ''); ?>
                    <?php campo('Regime de Apuração Tributária pelo SN', $dados['prestador']['regime_apuracao'] ?? ''); ?>
                </div>
            </section>

            <hr>
            <!-- TOMADOR -->
            <section class="section tomador">
                <div class="row d4">
                    <div class="campo section-title">TOMADOR / ADQUIRENTE</div>
                    <?php campo('CNPJ / CPF / NIF', $dados['tomador']['documento'] ?? ''); ?>
                    <?php campo('Indicador Municipal (Inscrição)', $dados['tomador']['inscricao'] ?? ''); ?>
                    <?php campo('Telefone', $dados['tomador']['telefone'] ?? ''); ?>
                </div>
                <div class="row d522">
                    <?php campo('Nome / Nome Empresarial', $dados['tomador']['nome'] ?? ''); ?>
                    <?php campo('Município / Sigla UF', $dados['tomador']['municipio_uf'] ?? ''); ?>
                    <?php campo('Código IBGE / CEP', $dados['tomador']['ibge_cep'] ?? ''); ?>
                </div>
                <div class="row d55">
                    <?php campo('Endereço', $dados['tomador']['endereco'] ?? ''); ?>
                    <?php campo('Email', $dados['tomador']['email'] ?? ''); ?>
                </div>
            </section>
            <!-- DESTINATÁRIO -->
            <?php if (! empty($dados['destinatario'])) { ?>
            <hr>
            <section class="section destinatario">
                <div class="row d252">
                    <div class="campo section-title">DESTINATÁRIO DA OPERAÇÃO</div>
                    <?php campo('CNPJ / CPF / NIF', $dados['destinatario']['documento'] ?? ''); ?>
                    <?php campo('Telefone', $dados['destinatario']['telefone'] ?? ''); ?>
                </div>
                <div class="row d522">
                    <?php campo('Nome / Nome Empresarial', $dados['destinatario']['nome'] ?? '', 'campo-destaque'); ?>
                    <?php campo('Município / Sigla UF', $dados['destinatario']['municipio_uf'] ?? ''); ?>
                    <?php campo('Código IBGE / CEP', $dados['destinatario']['ibge_cep'] ?? ''); ?>
                </div>
                <div class="row d55">
                    <?php campo('Endereço', $dados['destinatario']['endereco'] ?? ''); ?>
                    <?php campo('Email', $dados['destinatario']['email'] ?? ''); ?>
                </div>
            </section>
            <?php } else { ?>
            <hr>
            <section class="section intermediario">
                <div class="row">
                    <div class="campo-valor">DESTINATÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e</div>
                </div>
            </section>
            <?php } ?>
            <!-- INTERMEDIÁRIO -->
            <?php if (! empty($dados['intermediario'])) { ?>
            <hr>
            <section class="section intermediario">
                <div class="row d4">
                    <div class="campo section-title">INTERMEDIÁRIO DA OPERAÇÃO</div>
                    <?php campo('CNPJ / CPF / NIF', $dados['intermediario']['documento'] ?? ''); ?>
                    <?php campo('Indicador Municipal (Inscrição)', $dados['intermediario']['inscricao_municipal'] ?? ''); ?>
                    <?php campo('Telefone', $dados['intermediario']['telefone'] ?? ''); ?>
                </div>
                <div class="row d522">
                    <?php campo('Nome / Nome Empresarial', $dados['intermediario']['nome'] ?? '', 'campo-destaque'); ?>
                    <?php campo('Município / Sigla UF', $dados['intermediario']['municipio_uf'] ?? ''); ?>
                    <?php campo('Código IBGE / CEP', $dados['intermediario']['ibge_cep'] ?? ''); ?>
                </div>
                <div class="row d55">
                    <?php campo('Endereço', $dados['intermediario']['endereco'] ?? ''); ?>
                    <?php campo('Email', $dados['intermediario']['email'] ?? ''); ?>
                </div>
            </section>
            <?php } else { ?>
            <hr>
            <section class="section intermediario">
                <div class="row">
                    <div class="campo-valor">INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e</div>
                </div>
            </section>
            <?php } ?>

            <hr>
            <!-- SERVIÇO PRESTADO -->
            <section class="section servico">
                <div class="row d4">
                    <div class="campo section-title">SERVIÇO PRESTADO</div>
                    <?php campo('Código de Tributação Nacional / Municipal', $dados['servico']['codigo_tributacao'] ?? ''); ?>
                    <?php campo('Código da NBS', $dados['servico']['codigo_nbs'] ?? ''); ?>
                    <?php campo('Local da Prestação / Sigla UF / País', $dados['servico']['local_prestacao'] ?? ''); ?>
                </div>
                <div class="row">
                    <?php campo('Descrição do Código de Tributação Nacional / Municipal', $dados['servico']['descricao_tributacao'] ?? ''); ?>
                </div>
                <div class="row desc-servico">
                    <?php campo('Descrição do Serviço', nl2br(htmlspecialchars($dados['servico']['descricao'] ?? '-')), '', true); ?>
                </div>
            </section>
            <!-- TRIBUTAÇÃO MUNICIPAL (ISSQN) -->
            <?php if (! empty($dados['issqn'])) { ?>
            <hr>
            <section class="section issqn">
                <div class="row d225">
                    <div class="campo section-title">TRIBUTAÇÃO MUNICIPAL (ISSQN)</div>
                    <?php campo('Tipo de Tributação do ISSQN', $dados['issqn']['tipo_tributacao'] ?? ''); ?>
                    <?php campo('Município / Sigla UF / País de Incidência do ISSQN', $dados['issqn']['municipio_incidencia'] ?? ''); ?>
                    <?php campo('Código Município de Incidência', $dados['issqn']['codigo_municipio'] ?? ''); ?>
                </div>
                <div class="row d4">
                    <?php campo('Regime Especial de Tributação do ISSQN', $dados['issqn']['regime_especial'] ?? ''); ?>
                    <?php campo('Tipo de Imunidade ISSQN', $dados['issqn']['imunidade'] ?? ''); ?>
                    <?php campo('Suspensão da Exigibilidade do ISSQN', $dados['issqn']['suspensao'] ?? ''); ?>
                    <?php campo('Número Processo Suspensão', $dados['issqn']['processo'] ?? ''); ?>
                </div>
                <div class="row d4">
                    <?php campo('Benefício Municipal', $dados['issqn']['beneficio'] ?? ''); ?>
                    <?php campo('Total Deduções/Reduções', $dados['issqn']['deducoes'] ?? ''); ?>
                    <?php campo('Desconto Incondicionado', $dados['issqn']['desconto_incondicionado'] ?? ''); ?>
                    <?php campo('BC ISSQN', $dados['issqn']['base_calculo'] ?? ''); ?>
                </div>
                <div class="row d4">
                    <?php campo('Alíquota Aplicada', $dados['issqn']['aliquota'] ?? ''); ?>
                    <?php campo('Retenção do ISSQN', $dados['issqn']['retencao'] ?? ''); ?>
                    <?php campo('ISSQN Apurado', $dados['issqn']['valor_issqn'] ?? ''); ?>
                    <?php campo('Código do BM', $dados['issqn']['beneficio'] ?? ''); ?>
                </div>
            </section>
            <?php } else { ?>
            <hr>
            <section class="section issqn">
                <div class="row">
                    <div class="campo-valor">TRIBUTAÇÃO MUNICIPAL (ISSQN) - OPERAÇÃO NÃO SUJEITA AO ISSQN</div>
                </div>
            </section>
            <?php } ?>
            <hr>
            <!-- TRIBUTAÇÃO FEDERAL -->
            <section class="section federal">
                <div class="row d4">
                    <div class="campo section-title">TRIBUTAÇÃO FEDERAL (EXCETO CBS)</div>
                    <?php campo('IRRF', $dados['federal']['irrf'] ?? ''); ?>
                    <?php campo('Contribuição Previdenciária - Retida', $dados['federal']['previdencia'] ?? ''); ?>
                    <?php campo('Contribuições Sociais - Retidas', $dados['federal']['contribuicoes'] ?? ''); ?>
                </div>
                <div class="row d225">
                    <?php campo('PIS - Débito Apuração Própria', $dados['federal']['pis'] ?? ''); ?>
                    <?php campo('COFINS - Débito Apuração Própria', $dados['federal']['cofins'] ?? ''); ?>
                    <?php campo('Descrição das Contribuições Sociais - Retidas', $dados['federal']['descricao'] ?? ''); ?>
                </div>
            </section>
            <!-- IBS/CBS -->
            <?php if (! empty($dados['ibscbs'])) { ?>
            <hr>
            <section class="section ibscbs">
                <div class="row d225">
                    <div class="campo section-title">TRIBUTAÇÃO IBS / CBS</div>
                    <?php campo('CST / cClassTrib', $dados['ibscbs']['cst'] ?? ''); ?>
                    <?php campo('Indicador da Operação / Código IBGE incidência / Município incidência / Sigla UF', $dados['ibscbs']['iimu'] ?? ''); ?>
                </div>
                <div class="row d4">
                    <?php campo('Base de Cálculo', $dados['ibscbs']['base'] ?? ''); ?>
                    <?php campo('Alíquota Efetiva - IBS UF', $dados['ibscbs']['aliquota_efetiva_ibs_uf'] ?? ''); ?>
                    <?php campo('Alíquota Efetiva - IBS Mun', $dados['ibscbs']['aliquota_efetiva_ibs_municipio'] ?? ''); ?>
                    <?php campo('Alíquota - IBS UF / IBS Mun', $dados['ibscbs']['ibsufmun'] ?? ''); ?>
                </div>
                <div class="row d4">
                    <?php campo('Alíquota IBS Mun', $dados['ibscbs']['aliquota_ibs_municipio'] ?? ''); ?>
                    <?php campo('Valor Apurado Municipal - IBS', $dados['ibscbs']['valor_ibs_municipio'] ?? ''); ?>
                    <?php campo('Alíquota IBS UF', $dados['ibscbs']['aliquota_ibs_uf'] ?? ''); ?>
                    <?php campo('Valor Apurado Estadual - IBS', $dados['ibscbs']['valor_ibs_uf'] ?? ''); ?>
                </div>
                <div class="row d4">
                    <?php campo('Valor Total Apurado - IBS', $dados['ibscbs']['valor_total_ibs'] ?? ''); ?>
                    <?php campo('Alíquota - CBS', $dados['ibscbs']['aliquota_cbs'] ?? ''); ?>
                    <?php campo('Alíquota Efetiva - CBS', $dados['ibscbs']['aliquota_efetiva_cbs'] ?? ''); ?>
                    <?php campo('Valor Total Apurado - CBS', $dados['ibscbs']['valor_cbs'] ?? ''); ?>
                </div>
            </section>
            <?php } ?>

            <hr>
            <!-- TOTAIS -->
            <section class="section totais">
                <div class="row d4">
                    <div class="campo section-title">VALOR TOTAL DA NFS-E</div>
                    <?php campo('VALOR DA OPERAÇÃO / SERVIÇO', $dados['totais']['valor_servico'] ?? ''); ?>
                    <?php campo('Desconto Incondicionado', $dados['totais']['desconto_incondicionado'] ?? ''); ?>
                    <?php campo('Desconto Condicionado', $dados['totais']['desconto_condicionado'] ?? ''); ?>
                    <?php campo('Valor Total da NFS-e', $dados['totais']['valor_total_nota'] ?? ''); ?>
                </div>
                <div class="row d4">
                    <?php campo('Total de Retenções (ISSQN / Federais)', $dados['totais']['retencoes'] ?? ''); ?>
                    <?php campo('VALOR LÍQUIDO DA NFS-e', $dados['totais']['valor_liquido'] ?? ''); ?>
                    <?php campo('Total do IBS/CBS', (($dados['totais']['total_ibs'] ?? '-').' / '.($dados['totais']['total_cbs'] ?? '-'))); ?>
                    <div class="destaque">
                        <div class="campo-label">VALOR LÍQUIDO DA NFS-e + IBS/CBS</div>
                        <div class="campo-valor"><?= htmlspecialchars($dados['totais']['valor_total_nota'] ?? ($dados['totais']['valor_liquido'] ?? '-')) ?></div>
                    </div>
                </div>
            </section>
            <hr>
            <!-- INFORMAÇÕES COMPLEMENTARES -->
            <section class="section informacoes">
                <div class="campo campo-extra">INFORMAÇÕES COMPLEMENTARES</div>
                <div class="campo-compl">
                    <?php
                    $infoAdicional = array_filter([
                        $dados['informacoes']['informacoes_complementares'] ?? null,
                        $dados['informacoes']['informacoes_municipio'] ?? null,
                        $dados['informacoes']['obra'] ?? null,
                        $dados['informacoes']['inscricao_imobiliaria'] ?? null,
                        $dados['informacoes']['evento'] ?? null,
                        $dados['informacoes']['nfse_substituida'] ?? null,
                        ! empty($dados['informacoes']['tributos_aproximados_federal']) ? 'Tributos aproximados Federais: '.$dados['informacoes']['tributos_aproximados_federal'] : null,
                        ! empty($dados['informacoes']['tributos_aproximados_estadual']) ? 'Tributos aproximados Estaduais: '.$dados['informacoes']['tributos_aproximados_estadual'] : null,
                        ! empty($dados['informacoes']['tributos_aproximados_municipal']) ? 'Tributos aproximados Municipais: '.$dados['informacoes']['tributos_aproximados_municipal'] : null,
                    ]);
echo nl2br(htmlspecialchars(implode("\n", $infoAdicional)));
?>
                </div>
            </section>

            <footer>
                <div class="footer-data">
                    <div class="campo">
                        <div class="campo-label">DATA CIENTIFICAÇÃO:</div>
                        <div class="campo-valor"></div>
                    </div>
                </div>
                <div class="footer-assin">
                    <div class="campo">
                        <div class="campo-label">IDENTIFICAÇÃO E ASSINATURA:</div>
                        <div class="campo-valor"></div>
                    </div>
                </div>
                <div class="footer-chaves">
                    <div class="campo">
                        <div class="campo-label">Nº NFS-e / CHAVE NFS-e:</div>
                        <div class="campo-valor">
                            <?= htmlspecialchars($dados['identificacao']['numero_nfse'] ?? '-') ?> / <?= htmlspecialchars($dados['identificacao']['chave'] ?? '-') ?>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </div>
</body>
</html>
