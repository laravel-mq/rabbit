<?php

namespace LaravelMq\Rabbit\Services;

use JsonSchema\Constraints\Constraint;
use stdClass;
use JsonSchema\Validator;
use LaravelMq\Rabbit\Exceptions\SchemaValidationException;
use LaravelMq\Rabbit\Exceptions\SchemaFileException;

class SchemaValidator
{
    public function __construct(
        private readonly Validator $validator = new Validator()
    ) {}

    /**
     * Validate data against a JSON schema file
     *
     * @param array|object $data The data to validate
     * @param string $schemaPath Path to the JSON schema file
     * @return bool True if valid
     * @throws SchemaFileException If schema file doesn't exist or can't be read
     * @throws SchemaValidationException If validation fails
     * @throws JsonException If the schema file is not valid JSON
     */
    public function validate(array|object $data, string $schemaPath): bool
    {
        if (!file_exists($schemaPath)) {
            throw new SchemaFileException("Schema file not found: {$schemaPath}");
        }

        $schemaContent = file_get_contents($schemaPath);
        if ($schemaContent === false) {
            throw new SchemaFileException("Could not read schema file: {$schemaPath}");
        }

        $schema = json_decode($schemaContent, flags: JSON_THROW_ON_ERROR);

        $this->validator->reset();
        
        $this->validator->validate($data, $schema, Constraint::CHECK_MODE_TYPE_CAST);

        if (!$this->validator->isValid()) {
            $errors = [];
            foreach ($this->validator->getErrors() as $error) {
                $errors[] = sprintf(
                    '[%s] %s',
                    $error['property'] ?: 'root',
                    $error['message']
                );
            }
            
            throw new SchemaValidationException("Schema validation failed: " . implode('; ', $errors));
        }

        return true;
    }
}
