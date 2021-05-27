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
 * Use exiv2 to extract EXIF keywords, titles, dates, etc from images. Create labeled image with baked-in keywords,
 * title, caption, etc.
 *
 * @author   "SjG" <github@fogbound.net>
 * @package  Statgal2
 * @version  1.0
 * @link     https://github.com/libelle/statgal2
 * @example  https://github.com/libelle/statgal2
 * @license  Revised BSD
 */
class LabeledImage
{
    public static $primary_fields = array('title','caption','keywords');

    public static $metadata_map = array(
        'title'=>array('Iptc.Application2.ObjectName','Iptc.Application2.Subject','Xmp.dc.title'),
        'caption'=>array('Iptc.Application2.Caption','Xmp.dc.description'),
        'headline'=>array('Iptc.Application2.Headline','Xmp.photoshop.Headline'),
        'author'=>array('Exif.Image.Artist','Iptc.Application2.Byline','Iptc.Application2.Credit','Xmp.dc.creator','Xmp.photoshop.Credit'),
        'copyright'=>array('Exif.Image.Copyright','Iptc.Application2.Copyright','Xmp.dc.rights'),
        'source'=>array('Iptc.Application2.Source','Xmp.photoshop.Source','Exif.Photo.FileSource'),
        'contact_email'=>array('Xmp.iptc.CreatorContactInfo/Iptc4xmpCore:CiEmailWork'),
        'contact_phone'=>array('Xmp.iptc.CreatorContactInfo/Iptc4xmpCore:CiTelWork'),
        'keywords'=>array('Iptc.Application2.Keywords','Xmp.dc.subject'),
        'url'=>array('Xmp.xmpRights.WebStatement','Xmp.iptc.CreatorContactInfo/Iptc4xmpCore:CiUrlWork'),
        'city'=>array('Iptc.Application2.City','Xmp.photoshop.City'),
        'state'=>array('Xmp.photoshop.State','Iptc.Application2.ProvinceState'),
        'country'=>array('Iptc.Application2.CountryName','Xmp.photoshop.Country'),
        'country_code'=>array('Iptc.Application2.CountryCode'),
        'date'=>array('Exif.Photo.DateTimeOriginal','Iptc.Application2.DateCreated','Xmp.photoshop.DateCreated','Xmp.xmp.CreateDate'),
        'software'=>array('Exif.Image.Software','Xmp.xmp.CreatorTool'),
        'make'=>array('Exif.Image.Make'),
        'model'=>array('Exif.Image.Model'),
    );

    public static function checkOrCreateDirectory($path)
    {
        if (!is_dir($path))
        {
            mkdir($path, 0777, true);
            chmod($path, 0777);
        }
    }

    public static function exportMetadata($filename, $exivpath)
    {
        $path_parts = pathinfo($filename);
        $metadata_file = $path_parts['dirname'].'/metadata.txt';
        $cmd = $exivpath.' -Pkt '.escapeshellarg($filename).' > '.escapeshellarg($metadata_file).' 2>&1  < /dev/null ';
        $res = exec($cmd);
        $meta = file($metadata_file);
        unlink($metadata_file);
        return $meta;
    }

    public static function applyMetadataScript($filename, $headers, $exivpath)
    {
        $path_parts = pathinfo($filename);
        $command_file = $path_parts['dirname'] . '/metadata_commands.txt';
        $handle = fopen($command_file, 'w');
        foreach ($headers as $key => $tv)
        {
            if ($key == 'keywords')
            {
                fwrite($handle, "del Iptc.Application2.Keywords\n");
                if (!empty($tv))
                {
                    foreach ($tv as $this_keyword)
                    {
                        $skeyw = trim($this_keyword);
                        fwrite($handle, "add Iptc.Application2.Keywords $skeyw\n");
                    }
                    fwrite($handle, "del Xmp.dc.subject\n");
                    fwrite($handle, "set Xmp.dc.subject ".implode(',',$tv)."\n");
                }
            }
            else
            {
                $map = (isset(self::$metadata_map[$key]) ? self::$metadata_map[$key] : false);
                if ($map)
                {
                    foreach ($map as $mapped_key)
                    {
                        fwrite($handle, "del $mapped_key\n");
                        if (!empty($tv))
                            fwrite($handle, "set $mapped_key $tv\n");
                    }
                }
            }

        }
        fclose($handle);
        $cmd = $exivpath . ' -m ' . escapeshellarg($command_file) . ' ' . escapeshellarg($filename);
        $res = exec($cmd);
        unlink($path_parts['dirname'] . '/metadata_commands.txt');
    }


    public static function getImageHeaders($filename, $exivpath, $unique=true, $sanitize=true, $exclude_keywords=array())
    {
        $image_metadata = array();
        $meta = self::exportMetadata($filename,$exivpath);
        $keys = array_keys(self::$metadata_map);
        array_walk($exclude_keywords,function($d){return mb_strtolower($d,mb_detect_encoding($d,'UTF-8, ISO-8859-1'));});
        foreach($keys as $key)
        {
            $image_metadata[$key]=array();
        }
        foreach ($meta as $tl)
        {
            // key val
            $bits = preg_split('/\s+/',$tl);
            $key = self::metaDataKeyFind($bits[0]);
            if ($key !== false)
            {
                array_shift($bits); // key
                $val = implode(' ',$bits);
                $val = trim(preg_replace('/lang="[^"]+"/','',$val));
                if ($key == 'keywords')
                {
                    $vals = explode(',',$val);
                    foreach($vals as $tv)
                    {
                        $tk = mb_strtolower(trim($tv),mb_detect_encoding($tv,'UTF-8, ISO-8859-1'));
                        if (! in_array($tk,$exclude_keywords))
                            $image_metadata[$key][] = trim($tv);
                    }
                }
                else
                    $image_metadata[$key][] = $val;
            }
        }
        if ($unique)
        {
            foreach ($image_metadata as $key=>$val)
            {
                $image_metadata[$key] = array_unique($val);
            }
        }
        if ($sanitize)
            return self::sanitizeHeaders($image_metadata);
        return $image_metadata;
    }


    public static function metaDataKeyFind($key)
    {
        $ret = false;
        foreach (self::$metadata_map as $tk=>$tl)
        {
            if (array_search($key,$tl) !== false)
            {
                $ret = $tk;
                break;
            }
        }
        return $ret;
    }

    public static function sanitizeHeaders($headers)
    {
        $dateOK = false;
        if (isset($headers['date']) && count($headers['date'])>0)
        {
            $sanitized = $headers['date'][0];
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $sanitized))
            {
                $dateOK = true;
            }
            else
            {
                $dt = new DateTime($sanitized);
                if ($dt)
                {
                    $headers['date'] = array($dt->format('Y-m-d H:i:s'));
                    $dateOK = true;
                }
            }
        }
        if (!$dateOK)
        {
            $headers['date'] = false;
        }
        return $headers;
    }

    public static function createLabeledImage($src_filename, $dest_filename, $options=array(), $exivpath)
    {
        $size = getimagesize($src_filename);
        $image = new Imagick($src_filename);

        $total_width = $size[0];

        if (isset($options['border']) && $options['border'] > 0)
        {
            $image->borderImage(new ImagickPixel("black"), $options['border'], $options['border']);
            $total_width += 2*$options['border'];
        }

        $dets = array();

        foreach (self::$primary_fields as $field)
        {
            if (isset($options[$field]) && !empty($options[$field]))
            {
                if ($field == 'keywords' && is_array($options[$field]))
                    $dets[] = implode(', ',$options[$field]);
                else
                    $dets[] = $options[$field];
            }
        }
        $lines = count($dets);
        $caption = implode("\n",$dets);

        // bad but fast
        $draw = new ImagickDraw();

        $font_size = $size[0]/40;
        if ($font_size < 16)
            $font_size = 16;
        $font_padding = $font_size / 2;

        // Font properties
        $draw->setFont($options['font']);
        $draw->setFontSize($font_size);
        $draw->setFillColor('black');
        $draw->setStrokeAntialias(true);
        $draw->setTextAntialias(true);

        $caption = self::makeTextBlock($caption, $options['font'],
            0.75 * $font_size, $size[0]);

        // Get font metrics
        $metrics = $image->queryFontMetrics($draw, $caption);

        // Create text
        $draw->annotation($font_padding, $metrics['ascender']+$font_padding, $caption);

        // Create image. was $metrics['textWidth']
        $caption_width = $total_width;
        if (isset($options['cborder']) && $options['cborder'] > 0)
        {
            $caption_width -= 2*$options['cborder'];
        }
        $image->newImage($caption_width, $metrics['textHeight']+$font_padding, 'white');
        if (isset($options['cborder']) && $options['cborder'] > 0)
        {
            $image->borderImage(new ImagickPixel("black"), $options['cborder'], $options['cborder']);
            if (isset($options['border']) && $options['border'] > 0)
            {
                $image->cropImage($caption_width+2*$options['cborder'],
                    $metrics['textHeight']+$font_padding+$options['cborder'],
                    0,$options['cborder']);
            }
        }

        $image->drawImage($draw);

        $image->resetIterator();
        $combined = $image->appendImages(true);

        $combined->writeImage($dest_filename);
        unset($combined);

        // write and apply metadata script
        self::applyMetadataScript($dest_filename,$options,$exivpath);
    }

    static function makeTextBlock($text, $fontfile, $fontsize, $width)
    {
        $words = explode(' ', $text);
        $lines = array($words[0]);
        $currentLine = 0;
        for($i = 1; $i < count($words); $i++)
        {
            $lineSize = imagettfbbox($fontsize, 0, $fontfile, $lines[$currentLine] . ' ' . $words[$i]);
            if($lineSize[2] - $lineSize[0] < $width)
            {
                $lines[$currentLine] .= ' ' . $words[$i];
            }
            else
            {
                $currentLine++;
                $lines[$currentLine] = $words[$i];
            }
        }
        return implode("\n", $lines);
    }
}