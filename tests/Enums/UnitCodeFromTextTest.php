<?php
/*
 * Created on   : Thu Aug 20 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : UnitCodeFromTextTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Enums;

use ERechnungToolkit\Enums\UnitCode;
use Tests\Contracts\BaseTestCase;

/**
 * Freitext-Auflösung von Mengeneinheiten ({@see UnitCode::fromText()}).
 */
class UnitCodeFromTextTest extends BaseTestCase {
    public function test_direct_iso_code_wins(): void {
        $this->assertSame(UnitCode::HOUR, UnitCode::fromText('HUR'));
        $this->assertSame(UnitCode::HOUR, UnitCode::fromText('hur'));
    }

    public function test_word_list_resolves_german_jargon(): void {
        $this->assertSame(UnitCode::METRE, UnitCode::fromText('lfm'));
        $this->assertSame(UnitCode::SQUARE_METRE, UnitCode::fromText('qm'));
        $this->assertSame(UnitCode::CUBIC_METRE, UnitCode::fromText('m3'));
        $this->assertSame(UnitCode::HOUR, UnitCode::fromText('Stunden'));
        $this->assertSame(UnitCode::LUMP_SUM, UnitCode::fromText('Pauschale'));
    }

    public function test_abbreviations_resolve_including_trailing_dot(): void {
        $this->assertSame(UnitCode::PACKAGE, UnitCode::fromText('Pkt.'));
        $this->assertSame(UnitCode::BOX, UnitCode::fromText('Krt'));
        $this->assertSame(UnitCode::DOZEN, UnitCode::fromText('Dtz.'));
    }

    /** Die Stück-Familie folgt dem Zielcode des Aufrufers, nicht einer Annahme. */
    public function test_piece_family_follows_the_requested_target_code(): void {
        $this->assertSame(UnitCode::PIECE, UnitCode::fromText('Stk.'));
        $this->assertSame(UnitCode::UNIT_H87, UnitCode::fromText('Stk.', UnitCode::UNIT_H87));
        $this->assertSame(UnitCode::UNIT_H87, UnitCode::fromText('stück', UnitCode::UNIT_H87));
    }

    /** „C62"/„H87" als Direkt-Code schlagen den Zielcode-Parameter. */
    public function test_explicit_piece_code_is_not_overridden(): void {
        $this->assertSame(UnitCode::PIECE, UnitCode::fromText('C62', UnitCode::UNIT_H87));
    }

    /** Englische Stunden-/Tagesformen und romanische Stückwörter (workDiary-Vollscan 2026-08-23, C8). */
    public function test_english_time_words_and_romance_piece_words_resolve(): void {
        self::assertSame(UnitCode::HOUR, UnitCode::fromText('hr'));
        self::assertSame(UnitCode::HOUR, UnitCode::fromText('Hours'));
        self::assertSame(UnitCode::DAY, UnitCode::fromText('Tag'));
        self::assertSame(UnitCode::DAY, UnitCode::fromText('days'));
        self::assertSame(UnitCode::UNIT_H87, UnitCode::fromText('pz', UnitCode::UNIT_H87));
        self::assertSame(UnitCode::UNIT_H87, UnitCode::fromText('ud', UnitCode::UNIT_H87));
    }

    public function test_unknown_and_empty_yield_null(): void {
        $this->assertNull(UnitCode::fromText('Schubkarre'));
        $this->assertNull(UnitCode::fromText(''));
        $this->assertNull(UnitCode::fromText('   '));
        $this->assertNull(UnitCode::fromText(null));
    }
}
