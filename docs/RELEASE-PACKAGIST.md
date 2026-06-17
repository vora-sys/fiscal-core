# Release Packagist

Data de referencia: 2026-06-17

Nome canonico do pacote: `sabbajohn/fiscal-core`.

Publicacoes com outro vendor, como `freeline/fiscal-core`, devem ser tratadas como legado/desalinhamento e nao devem aparecer como instalacao principal.

## Checklist de release v1.4.0

1. Validar metadados do Composer:

   ```bash
   composer validate --strict
   ```

2. Rodar a suite critica local:

   ```bash
   composer test:ci
   composer test:nfse
   ```

3. Rodar suites adicionais quando o ambiente permitir:

   ```bash
   composer test:unit
   vendor/bin/phpunit --testsuite NFe
   vendor/bin/phpunit --testsuite Tributacao
   ```

4. Rodar analise estatica inicial:

   ```bash
   composer analyse
   ```

5. Confirmar que testes externos reais seguem opt-in:

   ```bash
   ENABLE_EXTERNAL_TESTS=false composer test:ci
   ```

6. Atualizar `CHANGELOG.md` com a versao `v1.4.0`.

7. Criar tag:

   ```bash
   git tag v1.4.0
   git push origin v1.4.0
   ```

8. Publicar/submeter no Packagist usando o repositorio GitHub deste pacote e o nome `sabbajohn/fiscal-core`.

9. Conferir instalacao em um projeto limpo:

   ```bash
   composer require sabbajohn/fiscal-core:^1.2
   ```

## Canais fora do MVP

GitHub Packages fica fora do MVP deste release. Se for adotado depois, documente:

- registry usado;
- autenticacao;
- politica de tags;
- diferenca entre Packagist e GitHub Packages.

## Criterio de pronto

O release Composer e considerado pronto quando:

- `composer validate --strict` passa;
- `composer test:ci` passa em PHP 8.1 e 8.2 no GitHub Actions;
- `composer analyse` passa no nivel inicial configurado;
- README aponta para `composer require sabbajohn/fiscal-core`;
- Packagist resolve o pacote canonico `sabbajohn/fiscal-core`.
