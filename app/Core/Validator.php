<?php
/**
 * K-Docs - Validator
 * Validation centralisée des inputs
 */

namespace KDocs\Core;

class Validator
{
    private array $data;
    private array $rules;
    private array $errors = [];
    private array $validated = [];

    /**
     * Messages d'erreur par défaut (français)
     */
    private array $messages = [
        'required' => 'Le champ :field est requis.',
        'string' => 'Le champ :field doit être une chaîne de caractères.',
        'integer' => 'Le champ :field doit être un nombre entier.',
        'numeric' => 'Le champ :field doit être un nombre.',
        'email' => 'Le champ :field doit être une adresse email valide.',
        'url' => 'Le champ :field doit être une URL valide.',
        'min' => 'Le champ :field doit contenir au moins :param caractères.',
        'max' => 'Le champ :field ne doit pas dépasser :param caractères.',
        'min_value' => 'Le champ :field doit être au moins :param.',
        'max_value' => 'Le champ :field ne doit pas dépasser :param.',
        'between' => 'Le champ :field doit être entre :param1 et :param2.',
        'in' => 'Le champ :field doit être parmi: :param.',
        'not_in' => 'Le champ :field ne peut pas être: :param.',
        'regex' => 'Le format du champ :field est invalide.',
        'confirmed' => 'La confirmation du champ :field ne correspond pas.',
        'date' => 'Le champ :field doit être une date valide.',
        'before' => 'Le champ :field doit être une date avant :param.',
        'after' => 'Le champ :field doit être une date après :param.',
        'file' => 'Le champ :field doit être un fichier.',
        'mimes' => 'Le champ :field doit être de type: :param.',
        'max_size' => 'Le fichier :field ne doit pas dépasser :param Ko.',
        'unique' => 'La valeur du champ :field existe déjà.',
        'alpha' => 'Le champ :field ne doit contenir que des lettres.',
        'alpha_num' => 'Le champ :field ne doit contenir que des lettres et chiffres.',
        'alpha_dash' => 'Le champ :field ne doit contenir que des lettres, chiffres, tirets et underscores.',
    ];

    public function __construct(array $data, array $rules)
    {
        $this->data = $data;
        $this->rules = $rules;
    }

    /**
     * Factory statique pour créer et valider
     */
    public static function make(array $data, array $rules): self
    {
        $validator = new self($data, $rules);
        $validator->validate();
        return $validator;
    }

    /**
     * Exécute la validation
     */
    public function validate(): bool
    {
        $this->errors = [];
        $this->validated = [];

        foreach ($this->rules as $field => $ruleSet) {
            $rules = is_string($ruleSet) ? explode('|', $ruleSet) : $ruleSet;
            $value = $this->getValue($field);

            // Si nullable et vide, skip les autres règles
            if (in_array('nullable', $rules) && $this->isEmpty($value)) {
                continue;
            }

            foreach ($rules as $rule) {
                if ($rule === 'nullable') continue;

                $params = [];
                if (strpos($rule, ':') !== false) {
                    [$rule, $paramStr] = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                }

                $method = 'validate' . str_replace('_', '', ucwords($rule, '_'));

                if (method_exists($this, $method)) {
                    if (!$this->$method($field, $value, $params)) {
                        // Arrêter à la première erreur pour ce champ
                        break;
                    }
                }
            }

            // Si pas d'erreur, ajouter aux données validées
            if (!isset($this->errors[$field])) {
                $this->validated[$field] = $value;
            }
        }

        return empty($this->errors);
    }

    /**
     * Retourne si la validation a échoué
     */
    public function fails(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Retourne si la validation a réussi
     */
    public function passes(): bool
    {
        return empty($this->errors);
    }

    /**
     * Retourne les erreurs
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Retourne la première erreur pour un champ
     */
    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }

    /**
     * Retourne les données validées
     */
    public function validated(): array
    {
        return $this->validated;
    }

    /**
     * Récupère une valeur avec notation pointée
     */
    private function getValue(string $field)
    {
        $keys = explode('.', $field);
        $value = $this->data;

        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Vérifie si une valeur est vide
     */
    private function isEmpty($value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * Ajoute une erreur
     */
    private function addError(string $field, string $rule, array $params = []): void
    {
        $message = $this->messages[$rule] ?? "Le champ :field est invalide.";
        $message = str_replace(':field', $field, $message);

        if (count($params) === 1) {
            $message = str_replace(':param', $params[0], $message);
        } elseif (count($params) >= 2) {
            $message = str_replace(':param1', $params[0], $message);
            $message = str_replace(':param2', $params[1], $message);
            $message = str_replace(':param', implode(', ', $params), $message);
        }

        $this->errors[$field] = $message;
    }

    // ===== RÈGLES DE VALIDATION =====

    private function validateRequired(string $field, $value, array $params): bool
    {
        if ($this->isEmpty($value)) {
            $this->addError($field, 'required');
            return false;
        }
        return true;
    }

    private function validateString(string $field, $value, array $params): bool
    {
        if (!is_string($value)) {
            $this->addError($field, 'string');
            return false;
        }
        return true;
    }

    private function validateInteger(string $field, $value, array $params): bool
    {
        if (!filter_var($value, FILTER_VALIDATE_INT) && $value !== 0 && $value !== '0') {
            $this->addError($field, 'integer');
            return false;
        }
        return true;
    }

    private function validateNumeric(string $field, $value, array $params): bool
    {
        if (!is_numeric($value)) {
            $this->addError($field, 'numeric');
            return false;
        }
        return true;
    }

    private function validateEmail(string $field, $value, array $params): bool
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->addError($field, 'email');
            return false;
        }
        return true;
    }

    private function validateUrl(string $field, $value, array $params): bool
    {
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            $this->addError($field, 'url');
            return false;
        }
        return true;
    }

    private function validateMin(string $field, $value, array $params): bool
    {
        $min = (int)($params[0] ?? 0);

        if (is_string($value) && strlen($value) < $min) {
            $this->addError($field, 'min', [$min]);
            return false;
        }

        return true;
    }

    private function validateMax(string $field, $value, array $params): bool
    {
        $max = (int)($params[0] ?? 0);

        if (is_string($value) && strlen($value) > $max) {
            $this->addError($field, 'max', [$max]);
            return false;
        }

        return true;
    }

    private function validateMinValue(string $field, $value, array $params): bool
    {
        $min = (float)($params[0] ?? 0);

        if (is_numeric($value) && (float)$value < $min) {
            $this->addError($field, 'min_value', [$min]);
            return false;
        }

        return true;
    }

    private function validateMaxValue(string $field, $value, array $params): bool
    {
        $max = (float)($params[0] ?? 0);

        if (is_numeric($value) && (float)$value > $max) {
            $this->addError($field, 'max_value', [$max]);
            return false;
        }

        return true;
    }

    private function validateBetween(string $field, $value, array $params): bool
    {
        $min = (int)($params[0] ?? 0);
        $max = (int)($params[1] ?? 0);

        if (is_string($value)) {
            $length = strlen($value);
            if ($length < $min || $length > $max) {
                $this->addError($field, 'between', [$min, $max]);
                return false;
            }
        }

        return true;
    }

    private function validateIn(string $field, $value, array $params): bool
    {
        if (!in_array($value, $params, true)) {
            $this->addError($field, 'in', [implode(', ', $params)]);
            return false;
        }
        return true;
    }

    private function validateNotIn(string $field, $value, array $params): bool
    {
        if (in_array($value, $params, true)) {
            $this->addError($field, 'not_in', [implode(', ', $params)]);
            return false;
        }
        return true;
    }

    private function validateRegex(string $field, $value, array $params): bool
    {
        $pattern = $params[0] ?? '';

        if (!preg_match($pattern, $value)) {
            $this->addError($field, 'regex');
            return false;
        }

        return true;
    }

    private function validateConfirmed(string $field, $value, array $params): bool
    {
        $confirmField = $field . '_confirmation';
        $confirmValue = $this->getValue($confirmField);

        if ($value !== $confirmValue) {
            $this->addError($field, 'confirmed');
            return false;
        }

        return true;
    }

    private function validateDate(string $field, $value, array $params): bool
    {
        if (strtotime($value) === false) {
            $this->addError($field, 'date');
            return false;
        }
        return true;
    }

    private function validateAlpha(string $field, $value, array $params): bool
    {
        if (!ctype_alpha($value)) {
            $this->addError($field, 'alpha');
            return false;
        }
        return true;
    }

    private function validateAlphaNum(string $field, $value, array $params): bool
    {
        if (!ctype_alnum($value)) {
            $this->addError($field, 'alpha_num');
            return false;
        }
        return true;
    }

    private function validateAlphaDash(string $field, $value, array $params): bool
    {
        if (!preg_match('/^[\pL\pM\pN_-]+$/u', $value)) {
            $this->addError($field, 'alpha_dash');
            return false;
        }
        return true;
    }

    // ===== SANITIZATION =====

    /**
     * Nettoie les données
     */
    public static function sanitize(array $data, array $rules): array
    {
        $sanitized = [];

        foreach ($rules as $field => $sanitizers) {
            $value = $data[$field] ?? null;
            $sanitizerList = is_string($sanitizers) ? explode('|', $sanitizers) : $sanitizers;

            foreach ($sanitizerList as $sanitizer) {
                $value = self::applySanitizer($value, $sanitizer);
            }

            $sanitized[$field] = $value;
        }

        return $sanitized;
    }

    private static function applySanitizer($value, string $sanitizer)
    {
        if ($value === null) return null;

        return match($sanitizer) {
            'trim' => is_string($value) ? trim($value) : $value,
            'strip_tags' => is_string($value) ? strip_tags($value) : $value,
            'escape' => is_string($value) ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value,
            'lowercase' => is_string($value) ? strtolower($value) : $value,
            'uppercase' => is_string($value) ? strtoupper($value) : $value,
            'ucfirst' => is_string($value) ? ucfirst($value) : $value,
            'int' => (int)$value,
            'float' => (float)$value,
            'bool' => (bool)$value,
            'null_empty' => $value === '' ? null : $value,
            default => $value
        };
    }
}
