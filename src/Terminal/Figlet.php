<?php

namespace CliMode\Terminal;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Big letters made of a fill character: a built-in five row font for A-Z, 0-9 and a little punctuation. Lowercase
 * letters are shown as capitals, anything the font does not have as a question mark.
 */
class Figlet
{
    const HEIGHT = 5;

    /** @var array<string, string[]> character => five rows of equal width ("#" is ink) */
    const FONT = [
        'A' => [' ### ', '#   #', '#####', '#   #', '#   #'],
        'B' => ['#### ', '#   #', '#### ', '#   #', '#### '],
        'C' => [' ####', '#    ', '#    ', '#    ', ' ####'],
        'D' => ['#### ', '#   #', '#   #', '#   #', '#### '],
        'E' => ['#####', '#    ', '#### ', '#    ', '#####'],
        'F' => ['#####', '#    ', '#### ', '#    ', '#    '],
        'G' => [' ####', '#    ', '# ###', '#   #', ' ####'],
        'H' => ['#   #', '#   #', '#####', '#   #', '#   #'],
        'I' => ['#####', '  #  ', '  #  ', '  #  ', '#####'],
        'J' => ['  ###', '   # ', '   # ', '#  # ', ' ##  '],
        'K' => ['#   #', '#  # ', '###  ', '#  # ', '#   #'],
        'L' => ['#    ', '#    ', '#    ', '#    ', '#####'],
        'M' => ['#   #', '## ##', '# # #', '#   #', '#   #'],
        'N' => ['#   #', '##  #', '# # #', '#  ##', '#   #'],
        'O' => [' ### ', '#   #', '#   #', '#   #', ' ### '],
        'P' => ['#### ', '#   #', '#### ', '#    ', '#    '],
        'Q' => [' ### ', '#   #', '# # #', '#  # ', ' ## #'],
        'R' => ['#### ', '#   #', '#### ', '#  # ', '#   #'],
        'S' => [' ####', '#    ', ' ### ', '    #', '#### '],
        'T' => ['#####', '  #  ', '  #  ', '  #  ', '  #  '],
        'U' => ['#   #', '#   #', '#   #', '#   #', ' ### '],
        'V' => ['#   #', '#   #', '#   #', ' # # ', '  #  '],
        'W' => ['#   #', '#   #', '# # #', '## ##', '#   #'],
        'X' => ['#   #', ' # # ', '  #  ', ' # # ', '#   #'],
        'Y' => ['#   #', ' # # ', '  #  ', '  #  ', '  #  '],
        'Z' => ['#####', '   # ', '  #  ', ' #   ', '#####'],
        '0' => [' ### ', '#  ##', '# # #', '##  #', ' ### '],
        '1' => ['  #  ', ' ##  ', '  #  ', '  #  ', ' ### '],
        '2' => [' ### ', '#   #', '  ## ', ' #   ', '#####'],
        '3' => ['#### ', '    #', ' ### ', '    #', '#### '],
        '4' => ['#  # ', '#  # ', '#####', '   # ', '   # '],
        '5' => ['#####', '#    ', '#### ', '    #', '#### '],
        '6' => [' ### ', '#    ', '#### ', '#   #', ' ### '],
        '7' => ['#####', '    #', '   # ', '  #  ', '  #  '],
        '8' => [' ### ', '#   #', ' ### ', '#   #', ' ### '],
        '9' => [' ### ', '#   #', ' ####', '    #', ' ### '],
        ' ' => ['   ', '   ', '   ', '   ', '   '],
        '!' => ['  #  ', '  #  ', '  #  ', '     ', '  #  '],
        '?' => [' ### ', '#   #', '  ## ', '     ', '  #  '],
        '.' => ['   ', '   ', '   ', '   ', ' # '],
        ',' => ['   ', '   ', '   ', ' # ', '#  '],
        ':' => ['   ', ' # ', '   ', ' # ', '   '],
        '-' => ['     ', '     ', ' ### ', '     ', '     '],
        '_' => ['     ', '     ', '     ', '     ', '#####'],
        '+' => ['     ', '  #  ', ' ### ', '  #  ', '     '],
        '=' => ['     ', ' ### ', '     ', ' ### ', '     '],
        '/' => ['    #', '   # ', '  #  ', ' #   ', '#    '],
        "'" => [' # ', ' # ', '   ', '   ', '   '],
    ];

    /**
     * @param string $fill one character that the ink is made of
     *
     * @return string[] the lines, a text wider than $width continues on the next row of letters
     */
    public static function render(string $text, int $width = 60, string $fill = '#'): array
    {
        $fill = $fill === '' ? '#' : $fill;
        $width = max(8, $width);
        $lines = [];
        foreach (preg_split('/\r\n|\n|\r/', mb_strtoupper($text)) as $paragraph) {
            $rows = array_fill(0, self::HEIGHT, '');
            $used = 0;
            foreach (preg_split('//u', $paragraph, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
                $glyph = self::FONT[$char] ?? self::FONT['?'];
                $glyphWidth = strlen($glyph[0]) + 1;
                if ($used > 0 && $used + $glyphWidth > $width) {
                    $lines = array_merge($lines, self::finish($rows, $fill), ['']);
                    $rows = array_fill(0, self::HEIGHT, '');
                    $used = 0;
                    if ($char === ' ') {
                        // the space we wrapped at does not start the next row
                        continue;
                    }
                }
                foreach ($glyph as $i => $row) {
                    $rows[$i] .= $row . ' ';
                }
                $used += $glyphWidth;
            }
            $lines = array_merge($lines, self::finish($rows, $fill), ['']);
        }
        array_pop($lines);

        return $lines;
    }

    /**
     * @param string[] $rows
     *
     * @return string[]
     */
    protected static function finish(array $rows, string $fill = '#'): array
    {
        return array_map(static function ($row) use ($fill) {
            return rtrim(str_replace('#', $fill, $row));
        }, $rows);
    }
}
