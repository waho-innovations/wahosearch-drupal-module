<?php

declare(strict_types=1);

namespace Drupal\Tests\wahosearch_connector\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wahosearch_connector\Service\TextRepair;

/**
 * @coversDefaultClass \Drupal\wahosearch_connector\Service\TextRepair
 * @group wahosearch_connector
 */
class TextRepairTest extends UnitTestCase {

  /**
   * Broken accents are repaired, correct text stays as it is.
   *
   * @dataProvider texts
   */
  public function testFix(string $text, string $expected): void {
    $this->assertSame($expected, TextRepair::fix($text));
  }

  /**
   * Texts seen on a real site, and correct texts that must not change.
   */
  public static function texts(): array {
    return [
      'date' => ["Date de publication : Ao\u{C3}\u{BB}t 2019", 'Date de publication : Août 2019'],
      'e acute' => ["Date de r\u{C3}\u{A9}vision : Septembre 2022", 'Date de révision : Septembre 2022'],
      'copyright' => ["\u{C2}\u{A9} Conseil canadien", '© Conseil canadien'],
      'quotes' => ["\u{C3}\u{A2}\u{C2}\u{AB} Observation \u{C2}\u{BB}", "\u{E2}« Observation »"],
      'apostrophe as windows-1252' => ["l\u{E2}\u{20AC}\u{2122}objet", 'l’objet'],
      'apostrophe as control characters' => ["d\u{E2}\u{80}\u{99}un suivi", 'd’un suivi'],
      'symbol after a broken accent' => ["temp\u{C3}\u{A9}\u{B0}", 'tempé°'],
      'correct french' => ['Août, été, à l’école, « citation »', 'Août, été, à l’école, « citation »'],
      'correct symbols' => ['© 2020 · 25 °C · 50 €', '© 2020 · 25 °C · 50 €'],
      'accent before ellipsis' => ["Résumé\u{2026}\u{2026}", "Résumé\u{2026}\u{2026}"],
      'accent before a no-break space and quote' => ["vitalité\u{A0}»", "vitalité\u{A0}»"],
      'capital accent before a no-break space' => ["PRÉ\u{A0}2020", "PRÉ\u{A0}2020"],
      'mixed' =>["été et r\u{C3}\u{A9}vision", 'été et révision'],
      'plain' => ['Mice housing', 'Mice housing'],
      'empty' => ['', ''],
    ];
  }

}
