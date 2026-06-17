<?php
/**
 * K-DOCS - Input Validator
 * Validation stricte des entrées utilisateur
 */

namespace KDocs\Core;

class Validator
{
    private array $errors = [];
    private array $data = [];
    
    public function __construct(array $data)
    {
        $this->data = $data;
    }
    
    public static function make(array $data): self
    {
        return new self($data);
    }
    
    /**
     * Valide selon les règles
     */
    public function validate(array $rules): bool
    {
        $this->errors = [];
        
        foreach ($rules as $field => $fieldRules) {
            $value = $this->data[$field] ?? null;
            $ruleList = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;
            
            foreach ($ruleList as $rule) {
                $this->applyRule($field, $value, $rule);
            }
        }
        
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
     * Retourne les données validées (nettoyées)
     */
    public function validated(): array
    {
        $validated = [];
        
        foreach ($this->data as $key => $value) {
            if (is_string($value)) {
                $validated[$key] = trim($value);
            } elseif (is_array($value)) {
                $validated[$key] = $this->sanitizeArray($value);
            } else {
                $validated[$key] = $value;
            }
        }
        
        return $validated;
    }
    
    /**
     * Retourne une erreur formatée ou null
     */
    public function firstError(): ?string
    {
        if (empty($this->errors)) {
            return null;
        }
        
        $first = reset($this->errors);
        return is_array($first) ? $first[0] : $first;
    }
    
    private function applyRule(string $field, $value, string $rule): void
    {
        $params = [];
        
        if (strpos($rule, ':') !== false) {
            [$rule, $paramStr] = explode(':', $rule, 2);
            $params = explode(',', $paramStr);
        }
        
        $method = 'rule' . ucfirst($rule);
        
        if (method_exists($this, $method)) {
            $this->$method($field, $value, $params);
        }
    }
    
    // ========================
    // RÈGLES DE VALIDATION
    // ========================
    
    private function ruleRequired(string $field, $value, array $params): void
    {
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            $this->addError($field, "Le champ $field est requis");
        }
    }
    
    private function ruleString(string $field, $value, array $params): void
    {
        if ($value !== null && !is_string($value)) {
            $this->addError($field, "Le champ $field doit être une chaîne");
        }
    }
    
    private function ruleInteger(string $field, $value, array $params): void
    {
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_INT)) {
            $this->addError($field, "Le champ $field doit être un entier");
        }
    }
    
    private function ruleNumeric(string $field, $value, array $params): void
    {
        if ($value !== null && $value !== '' && !is_numeric($value)) {
            $this->addError($field, "Le champ $field doit être numérique");
        }
    }
    
    private function ruleEmail(string $field, $value, array $params): void
    {
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->addError($field, "Le champ $field doit être une adresse email valide");
        }
    }
    
    private function ruleUrl(string $field, $value, array $params): void
    {
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
            $this->addError($field, "Le champ $field doit être une URL valide");
        }
    }
    
    private function ruleMin(string $field, $value, array $params): void
    {
        $min = (int) ($params[0] ?? 0);
        
        if (is_string($value) && mb_strlen($value) < $min) {
            $this->addError($field, "Le champ $field doit contenir au moins $min caractères");
        } elseif (is_numeric($value) && $value < $min) {
            $this->addError($field, "Le champ $field doit être au moins $min");
        } elseif (is_array($value) && count($value) < $min) {
            $this->addError($field, "Le champ $field doit contenir au moins $min éléments");
        }
    }
    
    private function ruleMax(string $field, $value, array $params): void
    {
        $max = (int) ($params[0] ?? PHP_INT_MAX);
        
        if (is_string($value) && mb_strlen($value) > $max) {
            $this->addError($field, "Le champ $field ne doit pas dépasser $max caractères");
        } elseif (is_numeric($value) && $value > $max) {
            $this->addError($field, "Le champ $field ne doit pas dépasser $max");
        } elseif (is_array($value) && count($value) > $max) {
            $this->addError($field, "Le champ $field ne doit pas contenir plus de $max éléments");
        }
    }
    
    private function ruleIn(string $field, $value, array $params): void
    {
        if ($value !== null && $value !== '' && !in_array($value, $params, true)) {
            $allowed = implode(', ', $params);
            $this->addError($field, "Le champ $field doit être parmi: $allowed");
        }
    }
    
    private function ruleDate(string $field, $value, array $params): void
    {
        if ($value !== null && $value !== '') {
            $format = $params[0] ?? 'Y-m-d';
            $d = \DateTime::createFromFormat($format, $value);
            
            if (!$d || $d->format($format) !== $value) {
                $this->addError($field, "Le champ $field doit être une date valide (format: $format)");
            }
        }
    }
    
    private function ruleRegex(string $field, $value, array $params): void
    {
        $pattern = $params[0] ?? '';
        
        if ($value !== null && $value !== '' && !preg_match($pattern, $value)) {
            $this->addError($field, "Le format du champ $field est invalide");
        }
    }
    
    private function ruleArray(string $field, $value, array $params): void
    {
        if ($value !== null && !is_array($value)) {
            $this->addError($field, "Le champ $field doit être un tableau");
        }
    }
    
    private function ruleBoolean(string $field, $value, array $params): void
    {
        $valid = [true, false, 0, 1, '0', '1', 'true', 'false', 'on', 'off', 'yes', 'no'];
        
        if ($value !== null && !in_array($value, $valid, true)) {
            $this->addError($field, "Le champ $field doit être un booléen");
        }
    }
    
    private function ruleFile(string $field, $value, array $params): void
    {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            $this->addError($field, "Le champ $field doit être un fichier valide");
        }
    }
    
    private function ruleMimes(string $field, $value, array $params): void
    {
        if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES[$field]['tmp_name']);
            finfo_close($finfo);
            
            // Mapper extensions vers mimes
            $mimeMap = [
                'pdf' => 'application/pdf',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
            ];
            
            $allowed = [];
            foreach ($params as $ext) {
                $allowed[] = $mimeMap[$ext] ?? $ext;
            }
            
            if (!in_array($mime, $allowed)) {
                $this->addError($field, "Le type de fichier n'est pas autorisé");
            }
        }
    }
    
    private function ruleMaxFileSize(string $field, $value, array $params): void
    {
        $maxKb = (int) ($params[0] ?? 10240); // 10MB par défaut
        
        if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
            $sizeKb = $_FILES[$field]['size'] / 1024;
            
            if ($sizeKb > $maxKb) {
                $maxMb = round($maxKb / 1024, 1);
                $this->addError($field, "Le fichier ne doit pas dépasser {$maxMb}MB");
            }
        }
    }
    
    // Protection XSS/Injection
    private function ruleSafe(string $field, $value, array $params): void
    {
        if ($value !== null && is_string($value)) {
            // Détecter les patterns dangereux
            $dangerous = [
                '/<script/i',
                '/javascript:/i',
                '/on\w+\s*=/i',
                '/<iframe/i',
                '/data:\s*text\/html/i',
            ];
            
            foreach ($dangerous as $pattern) {
                if (preg_match($pattern, $value)) {
                    $this->addError($field, "Le champ $field contient du contenu non autorisé");
                    break;
                }
            }
        }
    }
    
    private function addError(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        $this->errors[$field][] = $message;
    }
    
    private function sanitizeArray(array $data): array
    {
        $result = [];
        
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $result[$key] = trim($value);
            } elseif (is_array($value)) {
                $result[$key] = $this->sanitizeArray($value);
            } else {
                $result[$key] = $value;
            }
        }
        
        return $result;
    }
}
