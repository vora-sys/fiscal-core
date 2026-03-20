<?php

declare(strict_types=1);

final class TestCertificateFile
{
    /**
     * @return array{path:string,password:string}
     */
    public static function create(
        string $commonName,
        string $password = 'secret',
        ?string $cnpj = null,
        string $organizationName = 'Freeline Testes',
        string $countryName = 'BR'
    ): array {
        $configPath = tempnam(sys_get_temp_dir(), 'fiscal-cert-cfg-');
        $certificatePath = tempnam(sys_get_temp_dir(), 'fiscal-cert-');

        if ($configPath === false || $certificatePath === false) {
            throw new RuntimeException('Nao foi possivel criar arquivos temporarios para o certificado de teste.');
        }

        file_put_contents($configPath, self::buildOpenSslConfig($cnpj));

        try {
            $privateKey = @openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'config' => $configPath,
            ]);

            if ($privateKey === false) {
                throw new RuntimeException('Falha ao criar chave privada do certificado de teste.');
            }

            $distinguishedName = [
                'commonName' => $commonName,
                'organizationName' => $organizationName,
                'countryName' => $countryName,
            ];

            $opensslOptions = [
                'digest_alg' => 'sha256',
                'config' => $configPath,
                'req_extensions' => 'v3_req',
                'x509_extensions' => 'v3_req',
            ];

            $csr = @openssl_csr_new($distinguishedName, $privateKey, $opensslOptions);
            if ($csr === false) {
                throw new RuntimeException('Falha ao criar CSR do certificado de teste.');
            }

            $x509 = @openssl_csr_sign($csr, null, $privateKey, 1, $opensslOptions);
            if ($x509 === false) {
                throw new RuntimeException('Falha ao assinar certificado de teste.');
            }

            $pkcs12 = '';
            if (!@openssl_pkcs12_export($x509, $pkcs12, $privateKey, $password)) {
                throw new RuntimeException('Falha ao exportar certificado PKCS#12 de teste.');
            }

            file_put_contents($certificatePath, $pkcs12);
            @chmod($certificatePath, 0600);
        } finally {
            @unlink($configPath);
        }

        return [
            'path' => $certificatePath,
            'password' => $password,
        ];
    }

    public static function cleanup(?string $path): void
    {
        if (is_string($path) && $path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    private static function buildOpenSslConfig(?string $cnpj): string
    {
        $config = <<<'CFG'
oid_section = custom_oids

[custom_oids]
cnpj = 2.16.76.1.3.3

[req]
distinguished_name = req_distinguished_name
prompt = no
req_extensions = v3_req
x509_extensions = v3_req

[req_distinguished_name]
CN = Teste Fiscal Core
O = Freeline Testes
C = BR

[v3_req]
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = clientAuth
CFG;

        if ($cnpj !== null && trim($cnpj) !== '') {
            $config .= PHP_EOL . 'cnpj = ASN1:PRINTABLESTRING:' . preg_replace('/\D+/', '', $cnpj) . PHP_EOL;
        }

        return $config . PHP_EOL;
    }
}
