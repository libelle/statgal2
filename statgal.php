#!/usr/bin/env php
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
 * Quick'n'Dirty Static Gallery Builder
 *
 * @author   "SjG" <github@fogbound.net>
 * @package  Statgal2
 * @version  1.0
 * @link     https://github.com/libelle/statgal2
 * @example  https://github.com/libelle/statgal2
 * @license  Revised BSD
 */
require 'ImageGallery.php';
require 'LabeledImage.php';
require 'Encoding.php';
require 'Utils.php';

$opt_def = array(
    's' => '',
    'v' => false,
    'f' => false,
    'F' => false,
    'n' => false,
    'd' => false,
    'e' => '/usr/bin/exiv2',
    'm' => '/usr/bin/ffmpeg',
    'p' => '/usr/bin/ffprobe',

);
if (count($argv) == 1)
{
    echo "Usage:\n";
    echo "statgal.php [-s source directory] [-v] [-d] [-f] [-F] [-n] [-e exiv2 path] [-m ffmpeg path] [-p ffprobe path] [-r filespec]\n";
    echo "  source directory (or any subdir) may contain a config file named config.txt\n";
    echo "  -v is verbose\n";
    echo "  -d is debug-level verbose\n";
    echo "  -f rescales new images, generates pages\n";
    echo "  -F means redo pages from scratch but no image processing\n";
    echo "  -n means clear the data cache, and rescan for all structure and keyword data. Implies -f\n";
    echo "  -e provides explicit path to exiv2 executable\n";
    echo "  -m provides explicit path to ffmpeg executable\n";
    echo "  -p provides explicit path to ffprobe executable\n";
    echo "  -r removes an album\n";
    echo "defaults:\n";
    foreach ($opt_def as $k => $v) echo "-$k = $v\n";
    echo "e.g.,\n";
    echo "statgal.php -v -s source_dir\n";
    exit;
}
$options = getopt('s:vdnfFe::m::p::r::');

$i = new ImageGallery;
$i->source = $options['s'];
$i->verbose = isset($options['v'])?true:$opt_def['v'];
$i->force = isset($options['f'])?true:$opt_def['f'];
$i->forcepages = isset($options['F'])?true:$opt_def['F'];
$i->nocache = isset($options['n'])?true:$opt_def['n'];
$i->exivpath = isset($options['e'])?$options['e']:false;
$i->ffmpegpath = isset($options['m'])?$options['m']:false;
$i->ffprobepath = isset($options['p'])?$options['p']:false;
$i->debug = isset($options['d'])?true:$opt_def['d'];
$i->verbose = ((isset($options['v'])?true:$opt_def['v'])||$i->debug);
$i->remove = isset($options['r'])?$options['r']:false;
$i->run();
