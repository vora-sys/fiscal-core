<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Providers\NFSe\Municipal;

/**
 * Alias legado para a antiga família DSF.
 *
 * DSF passa a reutilizar a base ABRASF compartilhada por compatibilidade
 * enquanto os municípios legados são migrados para a família canônica.
 */
final class DsfProvider extends AbrasfSharedProvider {}
