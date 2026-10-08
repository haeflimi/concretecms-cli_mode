<?php

namespace CliMode\Terminal;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Turns image data into lines of ASCII art (GD). Dark pixels become sparse characters, light ones dense characters,
 * which suits the light-on-dark terminal themes; $invert flips that for the "paper" theme.
 */
class AsciiImage
{
    const RAMP = ' .:-=+*#%@';

    /** Largest image (in pixels) we are willing to decode: GD needs about 4 bytes per pixel */
    const MAX_PIXELS = 40000000;

    /** A character cell is about twice as high as wide */
    const CELL_ASPECT = 0.5;

    /**
     * @throws \RuntimeException with a message fit for the terminal
     *
     * @return string[] the lines
     */
    public static function render(string $data, int $width, bool $invert = false): array
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('the GD extension is not installed on this server');
        }
        $info = @getimagesizefromstring($data);
        if ($info === false) {
            throw new \RuntimeException('not an image GD can read (SVG and friends are not supported)');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new \RuntimeException('the image is too large to turn into ASCII');
        }
        $source = @imagecreatefromstring($data);
        if (!$source) {
            throw new \RuntimeException('the image could not be decoded');
        }

        $width = max(8, min(200, $width));
        $height = max(1, (int) round($width * ($info[1] / $info[0]) * self::CELL_ASPECT));
        $small = imagecreatetruecolor($width, $height);
        // transparent areas count as black (the terminal background) instead of whatever the file stores there
        imagefill($small, 0, 0, imagecolorallocate($small, 0, 0, 0));
        imagealphablending($small, true);
        imagecopyresampled($small, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
        imagedestroy($source);

        $ramp = $invert ? strrev(self::RAMP) : self::RAMP;
        $last = strlen($ramp) - 1;
        $lines = [];
        for ($y = 0; $y < $height; ++$y) {
            $line = '';
            for ($x = 0; $x < $width; ++$x) {
                $rgb = imagecolorat($small, $x, $y);
                $luma = (0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF)) / 255;
                $line .= $ramp[(int) round($luma * $last)];
            }
            $lines[] = rtrim($line);
        }
        imagedestroy($small);

        return $lines;
    }
}
