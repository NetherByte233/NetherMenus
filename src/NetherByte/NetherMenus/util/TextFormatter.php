<?php

namespace NetherByte\NetherMenus\util;

class TextFormatter {
    /**
     * Wraps text to a specified line length, breaking at word boundaries when possible
     * and preserving color codes across wrapped lines
     * 
     * @param string $text The text to wrap
     * @param int|null $maxLineLength Maximum characters per line (null to use default from config)
     * @param bool|null $forceBreak Whether to force break words that are longer than maxLineLength (null to use default from config)
     * @return array Array of lines
     */
    public static function wrapText(string $text, ?int $maxLineLength = null, ?bool $forceBreak = null): array {
        // Get default values from plugin config if not specified
        $plugin = \NetherByte\NetherMenus\NetherMenus::getInstance();
        $maxLineLength = $maxLineLength ?? $plugin->getMaxLineLength();
        $forceBreak = $forceBreak ?? $plugin->shouldForceWordBreak();
        
        if (empty(trim($text))) {
            return [];
        }

        $lines = [];
        $words = explode(' ', $text);
        $currentLine = '';
        $currentColor = '';
        
        foreach ($words as $word) {
            // Extract color codes from the current word
            preg_match_all('/§[0-9a-fk-or]/i', $word, $matches);
            if (!empty($matches[0])) {
                // Get the last color code in the current word
                $currentColor = end($matches[0]);
            }
            
            // If adding the next word would exceed the line length
            if (mb_strlen($currentLine . ' ' . $word) > $maxLineLength) {
                if (!empty($currentLine)) {
                    $lines[] = $currentLine;
                }
                
                // Handle very long words that exceed maxLineLength
                if (mb_strlen($word) > $maxLineLength && $forceBreak) {
                    // Split the long word into chunks
                    $chunks = [];
                    $remaining = $word;
                    while (mb_strlen($remaining) > 0) {
                        $chunk = mb_substr($remaining, 0, $maxLineLength - 1 - mb_strlen($currentColor));
                        $remaining = mb_substr($remaining, mb_strlen($chunk));
                        $chunks[] = $chunk;
                    }
                    
                    // Add all chunks except the last one
                    for ($i = 0; $i < count($chunks) - 1; $i++) {
                        $lines[] = $currentColor . $chunks[$i] . '-';
                    }
                    // Start new line with the last chunk
                    $currentLine = $currentColor . end($chunks);
                } else {
                    $currentLine = $currentColor . $word;
                }
            } else {
                $currentLine .= (empty($currentLine) ? '' : ' ') . $word;
            }
        }
        
        // Add the last line if not empty
        if (!empty($currentLine)) {
            $lines[] = $currentLine;
        }
        
        return $lines;
    }
    
    /**
     * Formats a description with color codes and wraps it to the specified line length
     * Supports both string and array descriptions
     * 
     * @param string|string[] $description The description text (string or array of lines)
     * @param string $defaultColor The default color code to use (e.g., '§7' for gray)
     * @param int|null $maxLineLength Maximum characters per line (null to use default from config)
     * @param bool|null $forceBreak Whether to force break words that are too long (null to use default from config)
     * @return array Array of formatted lines
     */
    public static function formatDescription($description, string $defaultColor = '§7', ?int $maxLineLength = null, ?bool $forceBreak = null): array {
        if (empty($description)) {
            return [];
        }
        
        $result = [];
        
        // Handle array of pre-formatted lines
        if (is_array($description)) {
            foreach ($description as $line) {
                if (is_string($line)) {
                    $trimmedLine = trim($line);
                    if ($trimmedLine === '') {
                        $result[] = ''; // Preserve empty lines
                    } else {
                        // Get the last color code in the line
                        preg_match_all('/§[0-9a-fk-or]/i', $line, $matches);
                        $lineColor = !empty($matches[0]) ? end($matches[0]) : $defaultColor;
                        
                        // Remove any existing color codes before processing
                        $cleanLine = preg_replace('/§[0-9a-fk-or]/i', '', $line);
                        
                        // Wrap the line and apply the appropriate color to each wrapped segment
                        $wrappedLines = self::wrapText($cleanLine, $maxLineLength, $forceBreak);
                        foreach ($wrappedLines as $wrappedLine) {
                            $result[] = $lineColor . $wrappedLine;
                        }
                    }
                }
            }
        } 
        // Handle string description
        elseif (is_string($description)) {
            $wrappedLines = self::wrapText($description, $maxLineLength, $forceBreak);
            $result = array_map(fn($line) => $defaultColor . $line, $wrappedLines);
        }
        
        return $result;
    }
}
