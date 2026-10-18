<?php
/**
 * ExoClick Bulk Creative Uploader — File Validation
 * Validates uploaded files against format-specific specs (MIME, size, dimensions).
 */

require_once __DIR__ . '/config.php';

class FileValidator {

    /**
     * Validate a file for a given format category.
     * @param string $filePath  Path to the uploaded file
     * @param string $format    Format category: banner, native, push, popunder
     * @param string|null $pushSlot  For push format: 'icon' or 'image'
     * @return array {valid: bool, errors: string[], dimensions: [w,h]|null}
     */
    public function validate(string $filePath, string $format, ?string $pushSlot = null): array {
        $errors = [];
        $specs = FORMAT_SPECS[$format] ?? null;

        if (!$specs) {
            return ['valid' => false, 'errors' => ["Unknown format: $format"], 'dimensions' => null];
        }

        if (!file_exists($filePath)) {
            return ['valid' => false, 'errors' => ['File not found'], 'dimensions' => null];
        }

        // MIME type check
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        $allowedMime = $specs['allowed_mime'];
        if (!in_array($mime, $allowedMime, true)) {
            $errors[] = "Invalid file type: $mime. Allowed: " . implode(', ', $allowedMime);
        }

        // File size — no local limit; ExoClick auto-resizes as needed
        $fileSize = filesize($filePath);
        if ($format === 'push' && $pushSlot) {
            $slotSpec = $specs['images'][$pushSlot] ?? null;
            if (!$slotSpec) {
                $errors[] = "Invalid push slot: $pushSlot";
            }
        }

        // Dimension check
        $dimensions = null;
        $imageInfo = @getimagesize($filePath);
        if ($imageInfo === false) {
            $errors[] = 'Cannot read image dimensions';
        } else {
            $w = $imageInfo[0];
            $h = $imageInfo[1];
            $dimensions = [$w, $h];

            if ($format === 'push' && $pushSlot) {
                $acceptedSizes = $specs['images'][$pushSlot]['accepted_sizes'] ?? [];
            } else {
                $acceptedSizes = $specs['accepted_sizes'] ?? [];
            }

            // Check aspect ratio validation (native format)
            $aspectRatios = $specs['aspect_ratios'] ?? [];
            if (!empty($aspectRatios)) {
                $minDim = $specs['min_dimension'] ?? 0;
                $maxDim = $specs['max_dimension'] ?? PHP_INT_MAX;

                if ($w < $minDim || $h < $minDim) {
                    $errors[] = "Image too small: {$w}x{$h}. Minimum {$minDim}px per side.";
                } elseif ($w > $maxDim || $h > $maxDim) {
                    $errors[] = "Image too large: {$w}x{$h}. Maximum {$maxDim}px per side.";
                } else {
                    $actualRatio = $w / $h;
                    $matched = false;
                    foreach ($aspectRatios as $ar) {
                        $targetRatio = $ar['w'] / $ar['h'];
                        $flex = $ar['flexibility'];
                        if (abs($actualRatio - $targetRatio) / $targetRatio <= $flex) {
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        $ratioLabels = array_map(fn($ar) => "{$ar['w']}:{$ar['h']}", $aspectRatios);
                        $errors[] = "Invalid aspect ratio for {$w}x{$h}. Allowed: " . implode(', ', $ratioLabels) . " (10% tolerance)";
                    }
                }
            } elseif (!empty($acceptedSizes)) {
                $matched = false;
                foreach ($acceptedSizes as [$aw, $ah]) {
                    if ($w === $aw && $h === $ah) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    $allowed = array_map(fn($s) => "{$s[0]}x{$s[1]}", $acceptedSizes);
                    $errors[] = "Invalid dimensions: {$w}x{$h}. Allowed: " . implode(', ', $allowed);
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'dimensions' => $dimensions,
            'mime' => $mime ?? null,
            'size' => $fileSize,
        ];
    }

    /**
     * Validate text fields for a given format.
     * @param array $fields  Submitted text fields [field_name => value]
     * @param string $format Format category
     * @return array {valid: bool, errors: string[]}
     */
    public function validateText(array $fields, string $format): array {
        $errors = [];
        $specs = FORMAT_SPECS[$format] ?? null;
        if (!$specs) {
            return ['valid' => false, 'errors' => ["Unknown format: $format"]];
        }

        $textFields = $specs['text_fields'] ?? [];
        foreach ($textFields as $name => $rule) {
            $value = trim($fields[$name] ?? '');
            if ($rule['required'] && $value === '') {
                $errors[] = "{$rule['label']} is required";
                continue;
            }
            if ($value !== '' && mb_strlen($value) > $rule['max']) {
                $errors[] = "{$rule['label']}: max {$rule['max']} characters (got " . mb_strlen($value) . ")";
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * Detect format category from an ExoClick ad type ID.
     * @param int $adTypeId
     * @return string|null
     */
    public function detectFormat(int $adTypeId): ?string {
        return FORMAT_CATEGORY_MAP[$adTypeId] ?? null;
    }
}
