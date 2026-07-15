<?php

namespace sabbajohn\FiscalCore\Support;

final class NFSeSchemaValidator
{
    public function validate(string $xml, string $schemaPath): array
    {
        if (! is_file($schemaPath)) {
            return [
                'valid' => false,
                'schema' => $schemaPath,
                'errors' => ["Schema não encontrado: {$schemaPath}"],
            ];
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new \DOMDocument;
        $loaded = $dom->loadXML($xml, LIBXML_NONET);

        $errors = $this->normalizeErrors(libxml_get_errors());
        libxml_clear_errors();

        $valid = false;

        if ($loaded) {
            $valid = $dom->schemaValidate($schemaPath);
            $errors = array_merge($errors, $this->normalizeErrors(libxml_get_errors()));
            $errors = array_values(array_unique($errors));
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return [
            'valid' => $valid,
            'schema' => $schemaPath,
            'errors' => $errors,
        ];
    }

    /**
     * @param  \LibXMLError[]  $errors
     * @return string[]
     */
    private function normalizeErrors(array $errors): array
    {
        $messages = [];

        foreach ($errors as $error) {
            $message = trim($error->message);
            $line = $error->line > 0 ? "line {$error->line}" : null;
            $column = $error->column > 0 ? "column {$error->column}" : null;
            $location = implode(', ', array_filter([$line, $column]));

            $messages[] = $location !== ''
                ? "{$message} ({$location})"
                : $message;
        }

        sort($messages);

        return $messages;
    }
}
