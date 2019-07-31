<?php
/*
Copyright (c) 2019 Samuel Goldstein
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions
are met:
1. Redistributions of source code must retain the above copyright
   notice, this list of conditions and the following disclaimer.
2. Redistributions in binary form must reproduce the above copyright
   notice, this list of conditions and the following disclaimer in the
   documentation and/or other materials provided with the distribution.
3. Neither the name of copyright holders nor the names of its
   contributors may be used to endorse or promote products derived
   from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED
TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR
PURPOSE ARE DISCLAIMED.  IN NO EVENT SHALL COPYRIGHT HOLDERS OR CONTRIBUTORS
BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
POSSIBILITY OF SUCH DAMAGE.
*/

/**
 * Assorted utilities, including two from Stack Overflow which are not subject to above copyright.
 *
 * @author   "SjG" <github@fogbound.net>
 * @package  Statgal2
 * @version  1.0
 * @link     https://github.com/libelle/statgal2
 * @example  https://github.com/libelle/statgal2
 * @license  Revised BSD
 */
class Utils {

    /**
     * Build a relative filesystem path reference.
     * @param $ref string where you're starting
     * @param $target string where you're going
     * @return string how you get there
     */
    public static function relPath($ref,$target)
    {
        $ref_p = explode(DIRECTORY_SEPARATOR,$ref);
        $target_p = explode(DIRECTORY_SEPARATOR,$target);
        $ret = array();
        $i = 0;
        while ( isset($ref_p[$i]) && isset($target_p[$i]) )
        {
            if (strcmp($ref_p[$i],$target_p[$i]))
                break;
            $i += 1;
        }

        $j = count( $ref_p ) - 1;
        while ( $i <= $j )
        {
            if (!empty($ref_p[$j]))
                $ret[] = '..';
            $j -= 1;
        }

        for ($j=$i;$j<count($target_p);$j++ )
        {
            $ret[] = $target_p[$j];
        }

        return implode(DIRECTORY_SEPARATOR,$ret);
    }

    /**
     * Try to make nice names from filenames. Humanish stuffs are hard.
     * @param $str string
     * @return string|string[]|null
     */
    public static function makeHumanNice($str)
    {
        $str = self::beautify_filename($str);
        $str = implode(' ',preg_split('/[a-z]+\K|(?=[A-Z][a-z]+)/',$str));
        $str = trim(ucwords(str_replace(array('_','-'),array(' ',' '),$str)));
        $str = str_replace(array(' And ',' Or ',' With ',' By ',' \''),array(' and ',' or ',' with ',' by ','\''),$str);
        return preg_replace(array('/ +/','/_+/','/-+/'), ' ', $str);
    }

    /**
     * Remove some words irrelevant to titles
     * @param $str
     * @return mixed
     */
    public static function removeNoisewords($str)
    {
        return str_ireplace(
            array(' in ',' and ',' from ',' to ',' the ', ' a ',' an ',' this ',' that ',' by ',' with ',' or '),
            '',
            $str);
    }

    /**
     * Get month name
     * @param $num int month number, 1-indexed
     */
    public static function month($num)
    {
        $dateObj   = DateTime::createFromFormat('!m', $num);
        return $dateObj->format('F');
    }


    // from https://stackoverflow.com/questions/2021624/string-sanitizer-for-filename
    public static function unicodeSanitizeFilename($filename, $beautify=true) {
        $filename = \ForceUTF8\Encoding::fixUTF8($filename);
        // sanitize filename
        $filename = preg_replace(
            '~
        [<>:"/\\|?*]|            # file system reserved https://en.wikipedia.org/wiki/Filename#Reserved_characters_and_words
        [\x00-\x1F]|             # control characters http://msdn.microsoft.com/en-us/library/windows/desktop/aa365247%28v=vs.85%29.aspx
        [\x7F\xA0\xAD]|          # non-printing characters DEL, NO-BREAK SPACE, SOFT HYPHEN
        [#\[\]@!$&\'()+,;=]|     # URI reserved https://tools.ietf.org/html/rfc3986#section-2.2
        [{}^\~`]                 # URL unsafe characters https://www.ietf.org/rfc/rfc1738.txt
        ~x',
            '-', $filename);
        $filename = \ForceUTF8\Encoding::fixUTF8($filename);
        // avoids ".", ".." or ".hiddenFiles"
        $filename = ltrim($filename, '.-');
        // optional beautification
        if ($beautify) $filename = self::beautify_filename($filename);
        // maximise filename length to 255 bytes http://serverfault.com/a/9548/44086
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $filename = mb_strcut(pathinfo($filename, PATHINFO_FILENAME), 0, 255 - ($ext ? strlen($ext) + 1 : 0), mb_detect_encoding($filename,'UTF-8, ISO-8859-1')) . ($ext ? '.' . $ext : '');
        return $filename;
    }

    // from https://stackoverflow.com/questions/2021624/string-sanitizer-for-filename
    public static function beautify_filename($filename) {
        // reduce consecutive characters
        $filename = preg_replace(array(
            // "file   name.zip" becomes "file-name.zip"
            '/ +/',
            // "file___name.zip" becomes "file-name.zip"
            '/_+/',
            // "file---name.zip" becomes "file-name.zip"
            '/-+/'
        ), '-', $filename);
        $filename = preg_replace(array(
            // "file--.--.-.--name.zip" becomes "file.name.zip"
            '/-*\.-*/',
            // "file...name..zip" becomes "file.name.zip"
            '/\.{2,}/'
        ), '.', $filename);
        // lowercase for windows/unix interoperability http://support.microsoft.com/kb/100625
        $filename = mb_strtolower($filename, mb_detect_encoding($filename,'UTF-8, ISO-8859-1'));
        // ".file-name.-" becomes "file-name"
        $filename = trim($filename, '.- ');
        return $filename;
    }

     /** modified from https://gist.github.com/ozh/8169202 */
    public static function timedelta($time1, $time2, $precision = 2)
    {
        // If not numeric then convert timestamps
        if (!is_int($time1))
        {
            $time1 = strtotime($time1);
        }
        if (!is_int($time2))
        {
            $time2 = strtotime($time2);
        }
        // If time1 > time2 then swap the 2 values
        if ($time1 > $time2)
        {
            list($time1, $time2) = array($time2, $time1);
        }
        else if ($time1 == $time2)
            return "less than 1 second";
        // Set up intervals and diffs arrays
        $intervals = array('year', 'month', 'day', 'hour', 'minute', 'second');
        $diffs = array();
        foreach ($intervals as $interval)
        {
            // Create temp time from time1 and interval
            $ttime = strtotime('+1 ' . $interval, $time1);
            // Set initial values
            $add = 1;
            $looped = 0;
            // Loop until temp time is smaller than time2
            while ($time2 >= $ttime)
            {
                // Create new temp time from time1 and interval
                $add++;
                $ttime = strtotime("+" . $add . " " . $interval, $time1);
                $looped++;
            }
            $time1 = strtotime("+" . $looped . " " . $interval, $time1);
            $diffs[$interval] = $looped;
        }
        $count = 0;
        $times = array();
        foreach ($diffs as $interval => $value)
        {
            // Break if we have needed precision
            if ($count >= $precision)
            {
                break;
            }
            // Add value and interval if value is bigger than 0
            if ($value > 0)
            {
                if ($value != 1)
                {
                    $interval .= "s";
                }
                // Add value and interval to times array
                $times[] = $value . " " . $interval;
                $count++;
            }
        }
        // Return string with times
        return implode(", ", $times);
    }



    public static function erl($var)
    {
        echo print_r($var,true)."\n";
    }
}
