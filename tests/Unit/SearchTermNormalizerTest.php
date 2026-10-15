<?php

namespace Tests\Unit;

use App\Support\Search\SearchTermNormalizer;
use PHPUnit\Framework\TestCase;

class SearchTermNormalizerTest extends TestCase
{
    public function test_normalize_trims_and_collapses_whitespace(): void
    {
        $this->assertSame('', SearchTermNormalizer::normalize(null));
        $this->assertSame('', SearchTermNormalizer::normalize(''));
        $this->assertSame('', SearchTermNormalizer::normalize('   '));
        $this->assertSame('', SearchTermNormalizer::normalize("\t\n  \r\n "));

        $this->assertSame('Ali Khan', SearchTermNormalizer::normalize('  Ali   Khan  '));
        $this->assertSame('Muhammad Ali Khan', SearchTermNormalizer::normalize("  Muhammad \t  Ali \n  Khan  "));
        $this->assertSame('TEST-2026-001', SearchTermNormalizer::normalize('  TEST-2026-001  '));
    }

    public function test_tokens_returns_individual_safe_word_tokens(): void
    {
        $this->assertSame([], SearchTermNormalizer::tokens(null));
        $this->assertSame([], SearchTermNormalizer::tokens('   '));
        $this->assertSame(['Ali', 'Khan'], SearchTermNormalizer::tokens('  Ali   Khan  '));
        $this->assertSame(['Muhammad', 'Ali', 'Khan'], SearchTermNormalizer::tokens('  Muhammad   Ali Khan  '));
        $this->assertSame(['TEST-2026-001'], SearchTermNormalizer::tokens('TEST-2026-001'));
    }

    public function test_escape_like_escapes_wildcards_and_backslashes(): void
    {
        $this->assertSame('Ali\%', SearchTermNormalizer::escapeLike('Ali%'));
        $this->assertSame('Ali\_Khan', SearchTermNormalizer::escapeLike('Ali_Khan'));
        $this->assertSame('Ali\\\\Khan', SearchTermNormalizer::escapeLike('Ali\Khan'));
        $this->assertSame('Ali-Khan-2026', SearchTermNormalizer::escapeLike('Ali-Khan-2026'));
    }

    public function test_is_empty_detects_empty_and_whitespace_only(): void
    {
        $this->assertTrue(SearchTermNormalizer::isEmpty(null));
        $this->assertTrue(SearchTermNormalizer::isEmpty(''));
        $this->assertTrue(SearchTermNormalizer::isEmpty('     '));
        $this->assertTrue(SearchTermNormalizer::isEmpty("\t\n"));
        $this->assertFalse(SearchTermNormalizer::isEmpty('Ali'));
        $this->assertFalse(SearchTermNormalizer::isEmpty('  Ali  '));
    }
}
