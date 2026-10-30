<?php

namespace App\Services\InvitationConfig;

use Illuminate\Validation\ValidationException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Server-side validation of invitation configs against the JSON Schema of
 * their version (schemas/v{n}.json). The React editor runs the matching Zod
 * schema (resources/js/lib/config-schema.ts) for instant feedback; this is
 * the authoritative check used whenever a config is saved. Each config is
 * checked against its own version: old v1 documents stay valid as v1.
 */
class InvitationConfigValidator
{
    /** What the wizard writes (v2, phase 8D: new occasions, kind, thank_you_gallery). */
    public const CURRENT_VERSION = 2;

    /** Every version still accepted and rendered: a v1 config is checked against v1.json, never upgraded on read. */
    public const SUPPORTED_VERSIONS = [1, 2];

    /** Paths that are JSON objects and must not be encoded as [] when empty. */
    private const OBJECT_PATHS = [['opening', 'options'], ['effects'], ['theme', 'colors'], ['theme', 'fonts']];

    private ?Validator $validator = null;

    /**
     * @return array<string, list<string>> JSON pointer => messages; empty when valid
     */
    public function validate(array $config): array
    {
        $version = $config['version'] ?? null;

        if (! is_int($version) || ! in_array($version, self::SUPPORTED_VERSIONS, true)) {
            return ['/version' => ['Unsupported config version; expected one of '.implode(', ', self::SUPPORTED_VERSIONS).'.']];
        }

        $result = $this->validator()->validate($this->toJsonData($config), self::schemaId($version));

        if ($result->isValid()) {
            return [];
        }

        $errors = (new ErrorFormatter)->format($result->error(), true);

        return collect($errors)
            ->mapWithKeys(fn ($messages, $pointer) => [($pointer === '' ? '/' : $pointer) => array_values((array) $messages)])
            ->all();
    }

    public function isValid(array $config): bool
    {
        return $this->validate($config) === [];
    }

    /**
     * @throws ValidationException
     */
    public function assertValid(array $config, string $attribute = 'config'): void
    {
        $errors = $this->validate($config);

        if ($errors === []) {
            return;
        }

        $messages = [];
        foreach ($errors as $pointer => $list) {
            $key = $attribute.str_replace('/', '.', rtrim($pointer, '/'));
            $messages[$key] = $list;
        }

        throw ValidationException::withMessages($messages);
    }

    public static function schemaId(int $version): string
    {
        return "https://daawa.app/schemas/invitation-config/v{$version}.json";
    }

    public static function schemaPath(int $version): string
    {
        return __DIR__."/schemas/v{$version}.json";
    }

    private function validator(): Validator
    {
        if ($this->validator) {
            return $this->validator;
        }

        $validator = new Validator;
        $validator->setMaxErrors(25);
        foreach (self::SUPPORTED_VERSIONS as $version) {
            $validator->resolver()->registerFile(self::schemaId($version), self::schemaPath($version));
        }

        return $this->validator = $validator;
    }

    /**
     * Eloquent's array casts turn empty JSON objects into [], which would fail
     * "type": "object"; restore them before encoding.
     */
    private function toJsonData(array $config): mixed
    {
        foreach (self::OBJECT_PATHS as $path) {
            $ref = &$config;
            foreach ($path as $segment) {
                if (! is_array($ref) || ! array_key_exists($segment, $ref)) {
                    unset($ref);

                    continue 2;
                }
                $ref = &$ref[$segment];
            }
            if ($ref === []) {
                $ref = new \stdClass;
            }
            unset($ref);
        }

        return json_decode(json_encode($config, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
