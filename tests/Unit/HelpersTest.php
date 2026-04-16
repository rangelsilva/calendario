<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    public function testFormatDateConvertsValidDateToBrazilianFormat()
    {
        $date = '2026-04-14';
        $formatted = formatDate($date);
        
        $this->assertEquals('14/04/2026', $formatted);
    }

    public function testFormatDateReturnsDashesForEmptyDate()
    {
        $this->assertEquals('—', formatDate(''));
    }

    public function testFormatTimeConvertsTimeCorrectly()
    {
        $this->assertEquals('14:30', formatTime('14:30:00'));
        $this->assertEquals('00:00', formatTime(null));
        $this->assertEquals('00:00', formatTime(''));
    }

    public function testGetAccessLabelReturnsCorrectRole()
    {
        $this->assertEquals('Master', getAccessLabel(0));
        $this->assertEquals('Administrador', getAccessLabel(1));
        $this->assertEquals('Visitante', getAccessLabel(7));
    }

    public function testGetAccessLabelReturnsFallbackForUnknownRole()
    {
        $this->assertEquals('Nível 99', getAccessLabel(99));
    }

    public function testSanitizePostCleansWhitespacesAndHtmlEscapingDoesNotHappenYet()
    {
        $input = [
            'name' => '   Bruno   ',
            'nested' => [
                'field' => ' value '
            ]
        ];
        
        $sanitized = sanitize_post($input);
        
        $this->assertEquals('Bruno', $sanitized['name']);
        $this->assertEquals('value', $sanitized['nested']['field']);
    }

    public function testHFunctionEscapesHtmlEntities()
    {
        $input = '<script>alert("xss")</script>';
        $expected = '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;';
        
        $this->assertEquals($expected, h($input));
    }
}
