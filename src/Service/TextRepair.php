<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Service;

/**
 * Repairs text whose accents were broken by a wrong encoding.
 *
 * Text saved as UTF-8, read back as Windows-1252 and saved again turns "é"
 * into "Ã©" and "’" into "â€™". Each such run of characters is turned back
 * into the bytes it came from; when those bytes are valid UTF-8, the run is
 * replaced. Correct text is left alone, since a single accented letter on its
 * own never forms valid UTF-8 this way.
 */
final class TextRepair {

  /**
   * Windows-1252 bytes 0x80 to 0x9F, by the character they display as.
   */
  private const CP1252 = [
    0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84, 0x2026 => 0x85,
    0x2020 => 0x86, 0x2021 => 0x87, 0x02C6 => 0x88, 0x2030 => 0x89, 0x0160 => 0x8A,
    0x2039 => 0x8B, 0x0152 => 0x8C, 0x017D => 0x8E, 0x2018 => 0x91, 0x2019 => 0x92,
    0x201C => 0x93, 0x201D => 0x94, 0x2022 => 0x95, 0x2013 => 0x96, 0x2014 => 0x97,
    0x02DC => 0x98, 0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B, 0x0153 => 0x9C,
    0x017E => 0x9E, 0x0178 => 0x9F,
  ];

  /**
   * A lead character (Â to ô) followed by characters that stand for bytes
   * 0x80 to 0xBF.
   */
  private const PATTERN = '/[\x{C2}-\x{F4}][\x{80}-\x{BF}\x{20AC}\x{201A}\x{0192}\x{201E}\x{2026}\x{2020}\x{2021}\x{02C6}\x{2030}\x{0160}\x{2039}\x{0152}\x{017D}\x{2018}\x{2019}\x{201C}\x{201D}\x{2022}\x{2013}\x{2014}\x{02DC}\x{2122}\x{0161}\x{203A}\x{0153}\x{017E}\x{0178}]{1,3}/u';

  /**
   * Returns the text with broken accents repaired.
   */
  public static function fix(string $text): string {
    if ($text === '' || !preg_match('/[\x{C2}-\x{F4}]/u', $text)) {
      return $text;
    }
    return preg_replace_callback(self::PATTERN, static function (array $match): string {
      $chars = mb_str_split($match[0]);
      $lead = mb_ord($chars[0]);
      // The lead byte says how many bytes the character had.
      $length = $lead >= 0xF0 ? 4 : ($lead >= 0xE0 ? 3 : 2);
      if (count($chars) < $length) {
        return $match[0];
      }
      $rest = implode('', array_slice($chars, $length));
      $bytes = '';
      foreach (array_slice($chars, 0, $length) as $char) {
        $code = mb_ord($char);
        if ($code <= 0xFF) {
          $bytes .= chr($code);
        }
        elseif (isset(self::CP1252[$code])) {
          $bytes .= chr(self::CP1252[$code]);
        }
        else {
          return $match[0];
        }
      }
      // Only a complete character that broken text really comes from counts:
      // accented Latin letters and symbols, and punctuation such as ’ “ – €.
      // Anything else, such as "é……" read as one Chinese character, was real
      // text.
      if (!mb_check_encoding($bytes, 'UTF-8') || mb_strlen($bytes) !== 1) {
        return $match[0];
      }
      $code = mb_ord($bytes);
      return ($code >= 0xA0 && $code <= 0x17F) || ($code >= 0x2000 && $code <= 0x214F) ? $bytes . $rest : $match[0];
    }, $text) ?? $text;
  }

}
