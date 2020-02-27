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
class ImageGallery
{
    public $source;
    public $verbose;
    public $force;
    public $forcepages;
    public $nocache;
    public $exivpath;
    public $ffmpegpath;
    public $ffprobepath;

    protected $_db;
    // commonly used prepared statements, e.g. in recursive fns
    protected $_dbr = array();
    protected $_sizes = array('thumb', 'display', 'full');
    protected $_root_conf = false;

    /**
     * Dooo it!
     * @throws Exception
     */
    public function run()
    {
        $start = time();
        $this->init();
        $this->getRoot();
        $this->getFiles();
        $this->buildDateIndex();
        $this->buildSizedImages();
        $this->buildAlbumPages();
        $this->buildKeywordPages();
        $this->buildDatePages();
        $this->buildTemplateCopies();
        if ($this->verbose) $this->stats();
        $this->closeDb();
        if ($this->verbose) echo "Generation completed in " . Utils::timedelta($start, time()) . ".\n";
    }

    /**
     * Initialize things like paths to binaries
     */
    public function init()
    {
        $this->openDb();
        if (empty($this->exivpath))
        {
            $exiv = exec('which exiv2');
            if (empty($exiv))
                error_log('Cannot determine exiv2 path; will not be able to do keywording');
            else
                $this->exivpath = $exiv;
        }
        if (empty($this->ffmpegpath))
        {
            $ffmpeg = exec('which ffmpeg');
            if (empty($ffmpeg))
                error_log('Cannot determine ffmpeg path; will not be able to convert videos');
            else
                $this->ffmpegpath = $ffmpeg;
        }
        if (empty($this->ffprobepath))
        {
            $ffprobe = exec('which ffprobe');
            if (empty($ffprobe))
                error_log('Cannot determine ffprobe path; will not be able to scale videos');
            else
                $this->ffprobepath = $ffprobe;
        }
    }

    /**
     * Connect to SQLLite, and prepare a bunch of statement for later use.
     */
    public function openDb()
    {
        $this->_db = new PDO('sqlite:statgal2.' . $this->source . '.sqlite');
        if ($this->nocache)
        {
            if ($this->verbose) echo "Dropping all old caches and metadata\n";
            $this->_db->exec('drop table if exists images');
            $this->_db->exec('drop table if exists albums');
            $this->_db->exec('drop table if exists keywords');
            $this->_db->exec('drop table if exists yearmonths');
            $this->_db->exec('drop table if exists keywords_images');
            $this->_db->exec('drop table if exists yearmonths_images');
        }
        $this->_db->exec('create table if not exists images (id INTEGER PRIMARY KEY, parent INTEGER, spec TEXT, gallery_spec TEXT, title TEXT, file_date INTEGER, image_date INTEGER, fullsize TEXT)');
        $this->_db->exec('create table if not exists albums (id INTEGER PRIMARY KEY, parent INTEGER, spec TEXT, title TEXT, config TEXT, latest_date INTEGER, config_date INTEGER, changed INTEGER)');
        $this->_db->exec('create table if not exists keywords (id INTEGER PRIMARY KEY, keyword TEXT, safekeyword TEXT)');
        $this->_db->exec('create table if not exists yearmonths (id INTEGER PRIMARY KEY, year INTEGER, month INTEGER)');
        $this->_db->exec('create table if not exists keywords_images (id INTEGER PRIMARY KEY, keyword_id INTEGER, image_id INTEGER)');
        $this->_db->exec('create table if not exists yearmonths_images (id INTEGER PRIMARY KEY, yearmonth_id INTEGER, image_id INTEGER)');

        $this->_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->_dbr['get_album'] = $this->_db->prepare('SELECT * FROM albums where spec=:spec');
        $this->_dbr['get_image'] = $this->_db->prepare('SELECT * FROM images where spec=:spec and parent=:parent');
        $this->_dbr['create_album'] = $this->_db->prepare('INSERT INTO albums (parent,spec,changed) VALUES (:parent,:spec,:changed)');
        $this->_dbr['create_image'] = $this->_db->prepare('INSERT INTO images (parent,spec,file_date) VALUES (:parent,:spec,:file_date)');
        $this->_dbr['set_image_title'] = $this->_db->prepare('update images set title=:title, gallery_spec=:gallery_spec where id=:id');
        $this->_dbr['get_config'] = $this->_db->prepare('SELECT config FROM albums where spec=:spec');
        $this->_dbr['set_config'] = $this->_db->prepare('UPDATE albums set config=:config, title=:title,config_date=:config_date,changed=:changed where id=:id');
        $this->_dbr['get_kw'] = $this->_db->prepare('select * from keywords where safekeyword=:safekeyword');
        $this->_dbr['set_kw'] = $this->_db->prepare('insert into keywords (keyword, safekeyword) values (:keyword,:safekeyword)');
        $this->_dbr['get_ym'] = $this->_db->prepare('select * from yearmonths where year=:year and month=:month');
        $this->_dbr['set_ym'] = $this->_db->prepare('insert into yearmonths (year,month) values (:year,:month)');
        $this->_dbr['get_kw_image'] = $this->_db->prepare('select * from keywords_images where keyword_id=:keyword_id and image_id=:image_id');
        $this->_dbr['set_kw_image'] = $this->_db->prepare('insert into keywords_images (keyword_id,image_id) values (:keyword_id,:image_id)');
        $this->_dbr['get_ym_image'] = $this->_db->prepare('select * from yearmonths_images where yearmonth_id=:yearmonth_id and image_id=:image_id');
        $this->_dbr['set_ym_image'] = $this->_db->prepare('insert into yearmonths_images (yearmonth_id,image_id) values (:yearmonth_id,:image_id)');
        $this->_dbr['update_image'] = $this->_db->prepare('update images set image_date=:image_date,title=:title,gallery_spec=:gallery_spec where id=:id');
        $this->_dbr['update_image_size'] = $this->_db->prepare('update images set fullsize=:fullsize where id=:id');
        $this->_dbr['update_image_date'] = $this->_db->prepare('update images set image_date=:image_date where id=:id');
    }

    /**
     * Shut it down
     */
    public function closeDb()
    {
        $this->_db = null;
    }

    /**
     * Dispaly some interesting[?] stats to the user
     */
    public function stats()
    {
        $alb = $this->_db->query('SELECT count(*) as cnt FROM albums')->fetch();
        $img = $this->_db->query('SELECT count(*) as cnt from images')->fetch();
        $kw = $this->_db->query('SELECT count(*) as cnt from keywords')->fetch();
        $albstats = $this->_db->query('select min(cnt) as mmin ,max(cnt) as mmax,avg(cnt) as aavg from (select count(*) as cnt from images group by parent)')->fetch();
        $kwstats = $this->_db->query('select min(cnt) as mmin,max(cnt) as mmax,avg(cnt) as aavg from (select count(*) as cnt from keywords_images group by image_id)')->fetch();
        echo "{$img['cnt']} images in {$alb['cnt']} albums, described by {$kw['cnt']} keywords\n";
        echo "Pictures per album (min, max, avg): {$albstats['mmin']}, {$albstats['mmax']}, {$albstats['aavg']}\n";
        echo "Keywords per image (min, max, avg): {$kwstats['mmin']}, {$kwstats['mmax']}, {$kwstats['aavg']}\n";
    }

    /**
     * Find gallery directories
     */
    public function getRoot()
    {
        $start = time();
        if ($this->verbose) echo "Starting from root '{$this->source}'\n";
        if (!is_dir($this->source))
            throw new Exception('Specified source path for gallery does not exist.');

        $this->_db->exec('update albums set changed=0');
        $this->recursiveBuildFlatDirlist($this->source);
        $this->_db->exec('update albums set changed=1 where parent is null');

        if ($this->verbose) echo "Scan done in " . Utils::timedelta($start, time()) . ".\n";
    }

    /**
     * Locate files in the directories
     */
    public function getFiles()
    {
        $start = time();
        if ($this->verbose) echo "Scanning files.\n";
        foreach ($this->_db->query('select * from albums', PDO::FETCH_ASSOC) as $ta)
        {
            $this->fixAlbum($ta);
            $this->getDirImages($ta);
        }
        if ($this->verbose) echo "Scan done in " . Utils::timedelta($start, time()) . ".\n";
    }

    /**
     * Extract keywords and/or dates from a file
     * @param $file string filespec
     * @param $get_keywords boolean should we get keywords?
     * @param $exclude array of keywords to ignore
     * @return bool|false|int|mixed
     */
    public function getKeywords($file, $get_keywords, $exclude, $add = array())
    {
        if ($this->verbose) echo "Scanning file for" . ($get_keywords ? " keywords and" : "") . " EXIF date/title.\n";
        if ($this->isVideo($file))
        {
            $spec = pathinfo($file['spec'], PATHINFO_FILENAME);
            $date = $file['file_date'];
            $filetitle = Utils::makeHumanNice($spec);
            $filetitle = \ForceUTF8\Encoding::toUTF8($filetitle);
            $this->_dbr['update_image']->execute(array(':image_date' => $date, ':title' => $filetitle, ':id' => $file['id'], ':gallery_spec' => $spec));
        }
        else
        {
            $keywords = LabeledImage::getImageHeaders($file['spec'], $this->exivpath, true, true, $exclude);
            if (empty($file['image_date']))
            {
                // update image data
                $spec = pathinfo($file['spec'], PATHINFO_FILENAME);
                $date = (!empty($keywords['date'][0]) ? strtotime($keywords['date'][0]) : $file['file_date']);
                $filetitle = (!empty($keywords['title'][0]) ? $keywords['title'][0] :
                    (!empty($keywords['caption'][0]) ? $keywords['caption'][0] :
                        (!empty($keywords['headline'][0]) ? $keywords['headline'][0] : Utils::makeHumanNice($spec))));
                $filetitle = \ForceUTF8\Encoding::toUTF8($filetitle);
                $this->_dbr['update_image']->execute(array(':image_date' => $date, ':title' => $filetitle, ':id' => $file['id'], ':gallery_spec' => $spec));
                $file['image_date'] = $date;
            }

            if ($get_keywords)
            {
                foreach ($add as $tk)
                {
                    $keywords['keywords'][] = $tk;
                }
                $lowered = array_map('strtolower', $keywords['keywords']);
                $keywords['keywords'] = array_intersect_key($keywords['keywords'], array_unique($lowered));
                foreach (array('keywords', 'make', 'model') as $tkwl)
                {
                    foreach ($keywords[$tkwl] as $tkw)
                    {
                        $tkw = ForceUTF8\Encoding::toUTF8($tkw);
                        $safekeyword = mb_strtolower($tkw);
                        $this->_dbr['get_kw']->execute(array(':safekeyword' => $safekeyword));
                        $row = $this->_dbr['get_kw']->fetch(PDO::FETCH_ASSOC);
                        if (!$row)
                        {
                            $this->_dbr['set_kw']->execute(array(':keyword' => $tkw, ':safekeyword' => $safekeyword));
                            $this->_dbr['get_kw']->execute(array(':safekeyword' => $safekeyword));
                            $row = $this->_dbr['get_kw']->fetch(PDO::FETCH_ASSOC);
                            $this->_dbr['set_kw_image']->execute(array(':keyword_id' => $row['id'], ':image_id' => $file['id']));
                        }
                        else
                        {
                            $this->_dbr['get_kw_image']->execute(array(':keyword_id' => $row['id'], ':image_id' => $file['id']));
                            $exists = $this->_dbr['get_kw_image']->fetch(PDO::FETCH_ASSOC);
                            if (!$exists)
                                $this->_dbr['set_kw_image']->execute(array(':keyword_id' => $row['id'], ':image_id' => $file['id']));
                        }
                    }
                }
            }
        }
    }

    /**
     * Scan for config files
     * @param $dir string starting point
     * @param null $parent integer parent_id
     */
    public function recursiveBuildFlatDirlist($dir, $parent = null)
    {
        if ($this->verbose) echo "Scanning '$dir'\n";
        // paths are unique
        $this->_dbr['get_album']->execute(array(':spec' => $dir));
        $row = $this->_dbr['get_album']->fetch(PDO::FETCH_ASSOC);
        if (!$row)
        {
            if ($this->verbose) echo "Creating new record\n";
            $this->_dbr['create_album']->execute(array(':spec' => $dir, ':parent' => $parent, ':changed' => 1));
            $this->_dbr['get_album']->execute(array(':spec' => $dir));
            $row = $this->_dbr['get_album']->fetch(PDO::FETCH_ASSOC);
        }
        list($new, $config) = $this->getConfigs($dir, $row['config_date'] ? $row['config_date'] : false);
        if ($new)
        {
            if ($this->verbose) echo "Updating config\n";
            $title = (!empty($config['title']) ? $config['title'] : Utils::makeHumanNice(pathinfo($dir, PATHINFO_FILENAME)));
            $title = \ForceUTF8\Encoding::toUTF8($title);
            $this->_dbr['set_config']->execute(array(':config' => json_encode($config), ':config_date' => $new, ':title' => $title, ':changed' => 1, ':id' => $row['id']));
        }

        $cdir = scandir($dir);
        foreach ($cdir as $key => $value)
        {
            if (!in_array($value, array(".", "..")) && is_dir($dir . DIRECTORY_SEPARATOR . $value))
            {
                $this->recursiveBuildFlatDirlist($dir . DIRECTORY_SEPARATOR . $value, $row['id']);
            }
        }
    }

    /**
     * Get the images from a directory, see if they've changed
     * returns newest image date
     * @param $album array album structure
     */
    public function getDirImages($album)
    {
        $idir = scandir($album['spec']);
        $allowed = explode(',', $album['config']['allowed_extensions']);
        array_walk($allowed, function ($d) {
            return trim($d);
        });

        $exclude = explode(',', $album['config']['exclude_keywords']);
        array_walk($exclude, function ($d) {
            return strtolower(trim($d));
        });

        $update_image = $this->_db->prepare('UPDATE images set file_date=:file_date where id=:id');
        $album_changed = false;
        foreach ($idir as $key => $value)
        {
            $file_changed = false;
            if (!in_array($value, array(".", "..")))
            {
                // a file!
                $ext = strtolower(pathinfo($value, PATHINFO_EXTENSION));
                if (in_array($ext, $allowed))
                {
                    if ($this->verbose) echo "Checking '$value'\n";
                    $this->_dbr['get_image']->execute(array(':spec' => $album['spec'] . DIRECTORY_SEPARATOR . $value, ':parent' => $album['id']));
                    $row = $this->_dbr['get_image']->fetch(PDO::FETCH_ASSOC);
                    $new = filemtime($album['spec'] . DIRECTORY_SEPARATOR . $value);
                    if (!$row)
                    {
                        if ($this->verbose) echo "Creating new record\n";
                        $this->_dbr['create_image']->execute(array(':spec' => $album['spec'] . DIRECTORY_SEPARATOR . $value, ':parent' => $album['id'], ':file_date' => $new));
                        $this->_dbr['get_image']->execute(array(':spec' => $album['spec'] . DIRECTORY_SEPARATOR . $value, ':parent' => $album['id']));
                        $row = $this->_dbr['get_image']->fetch(PDO::FETCH_ASSOC);
                        $album_changed = true;
                        $file_changed = true;
                    }
                    else
                    {
                        if (!$row['file_date'] || $new > $row['file_date'])
                        {
                            $update_image->execute(array(':file_date' => $new, ':id' => $row['id']));
                            $album_changed = true;
                            $file_changed = true;
                        }
                    }
                }
            }
            if ($file_changed)
            {
                // do keywords
                if (!empty($this->exivpath))
                {
                    $this->getKeywords($row, $album['config']['enable_keywords'],
                        $exclude,
                        ($album['config']['album_name_keywords'] ? $this->getKeywordsFromTitle($album['title'], $exclude) : array())
                    );
                }
            }
        }

        if ($album_changed)
            $this->_db->prepare('UPDATE albums set changed=1 where id=:id')->execute(array(':id' => $album['id']));
    }

    /**
     * Title pieces into keywords
     * @param $string
     * @return array
     */
    public function getKeywordsFromTitle($string, $exclude = array())
    {
        $ret = array();
        $keywords = explode(' ', Utils::removeNoisewords($string));
        foreach ($keywords as $tk)
        {
            if (!in_array(strtolower($tk), $exclude))
                $ret[] = $tk;
        }
        return $ret;
    }

    /**
     * De-JSONify the album config
     * @param $album array album structure
     */
    public function fixAlbum(&$album)
    {
        $album['config'] = json_decode($album['config'], true);
    }

    /**
     * Is this image a video type?
     * @param $image array image structure
     * @return bool
     */
    public function isVideo($image)
    {
        return in_array(strtolower(pathinfo($image['spec'], PATHINFO_EXTENSION)), array('mov', 'mp4', 'mpeg'));
    }

    /**
     * Generate base path for album output
     * @param $album array album structure
     * @return string
     */
    public function albumBasePath($album)
    {
        $rel_spec = preg_replace('/^' . $this->source . '/', '', $album['spec']);
        if (substr($rel_spec, 0, 1) === DIRECTORY_SEPARATOR)
            $rel_spec = substr($rel_spec, 1);
        return $album['config']['destination_directory'] . DIRECTORY_SEPARATOR . $rel_spec;
    }

    /**
     * Get the topmost album
     * @return array album structure
     */
    public function getRootAlbum()
    {
        if (!$this->_root_conf)
        {
            foreach ($this->_db->query('select * from albums limit 1', PDO::FETCH_ASSOC) as $base) ;
            $this->fixAlbum($base);
            $this->_root_conf = $base;
        }
        return $this->_root_conf;
    }

    /**
     * Get the album for a virtual type, e.g., Keywords or Dates
     * @param $type string type
     * @return array albums structure
     */
    public function getVirtualAlbum($type)
    {
        $base = $this->getRootAlbum();
        $base['parent'] = 1;
        $base['title'] = $type;
        $base['spec'] = $this->source . DIRECTORY_SEPARATOR . strtolower($type);
        return $base;
    }

    /**
     * Get the keyword virtual album
     * @return array
     */
    public function getKeywordVirtualAlbum()
    {
        return $this->getVirtualAlbum('Keywords');
    }

    /**
     * get the date virtual album
     * @return array
     */
    public function getDateVirtualAlbum()
    {
        return $this->getVirtualAlbum('Dates');
    }

    /**
     * Build an URL from a location to another location
     * @param $reference array album where we're starting
     * @param $target array album where we're going
     * @return string how we get there
     */
    public function albumRelativeBaseUrl($reference, $target)
    {
        $rel_spec = str_replace($this->source, '', $target['spec']);

        if ($reference['config']['base_url'] == '*')
        {
            $ret = Utils::relPath($reference['spec'], $target['spec']);
            if (!empty($ret))
                $ret .= '/';
        }
        else
        {
            $rel_spec = str_replace(DIRECTORY_SEPARATOR, '/', $rel_spec);
            $prefix = '';
            if (!empty($reference['config']['base_url']))
                $prefix = $reference['config']['base_url'];
            if (substr($prefix, -1, 1) != '/')
                $prefix .= '/';

            if (substr($rel_spec, 0, 1) == '/')
                $rel_spec = substr($rel_spec, 1);
            $ret = $prefix . $rel_spec;
            if (substr($ret, -1, 1) !== '/')
                $ret = "$ret/";
        }
        return $ret;
    }

    /**
     * Create all the sized images for this source image: thumb, display, and full
     * If we're video, we generate a thumbnail as a static image
     * @param $image array images structure
     * @param $album array album structure of album containing this image
     */
    public function buildImageSet($image, $album)
    {
        $base_path = $this->albumBasePath($album);

        foreach ($this->_sizes as $idx => $size)
        {
            $dest = $base_path . DIRECTORY_SEPARATOR . $size;
            if (!is_dir($dest))
            {
                if ($this->verbose) echo "Creating directory $dest\n";
                mkdir($dest, 0755, true);
            }

            if (!$this->isVideo($image))
            {
                $size = $this->buildScaledImage($image['spec'],
                    $dest . DIRECTORY_SEPARATOR . $image['gallery_spec'],
                    $album['config'][$size . '_size'],
                    $album['config'][$size . '_quality'],
                    ($idx == 0 && !empty($album['config']['thumb_aspect']) ? $album['config']['thumb_aspect'] : '')
                );
            }
            else if ($idx == 0)
            {
                $this->buildVideoThumbnail($image['spec'],
                    $dest . DIRECTORY_SEPARATOR . $image['gallery_spec'],
                    $album['config'][$size . '_size'],
                    $album['config'][$size . '_quality'],
                    !empty($album['config']['thumb_aspect']) ? $album['config']['thumb_aspect'] : ''
                );
            }
            else
            {
                $this->buildScaledVideo($image['spec'],
                    $dest . DIRECTORY_SEPARATOR . $image['gallery_spec'],
                    $album['config'][$size . '_size']);
            }
        }
        // biggest is last
        $this->_dbr['update_image_size']->execute(array(':fullsize' => $size, ':id' => $image['id']));

    }

    /**
     * Use FFmpeg to make a thumbnail from video
     * @param $src string file spec of original
     * @param $dest string destination spec
     * @param $size integer width of thumbnail
     */
    public function buildVideoThumbnail($src, $dest, $size, $quality, $aspect = '')
    {
        if ($this->ffmpegpath)
        {
            if ($this->verbose) echo "Creating video thumbnail $dest with max dimension $size\n";
            $cmd = $this->ffmpegpath . ' -y -i ' . escapeshellarg($src) . ' -vf "thumbnail,scale=' . $size .
                ':-1" -frames:v 1 ' . escapeshellarg($src . '_VT.jpg');
            exec($cmd);
            $this->buildScaledImage(
                $src . '_VT.jpg',
                pathinfo($dest, PATHINFO_DIRNAME) . DIRECTORY_SEPARATOR .
                pathinfo($dest, PATHINFO_FILENAME) . '.jpg',
                $size,
                $quality,
                $aspect
            );
            unlink($src . '_VT.jpg');
        }
        else
            echo "No ffmpeg path, so no thumbnail for you, my friend.\n";
    }

    /**
     * Use FFmpeg to make a different sizes and formats from video. creates MP4 and WEBM
     * @param $src string file spec of original
     * @param $dest string destination spec
     * @param $size integer width to generate
     */
    public function buildScaledVideo($src, $dest, $size)
    {
        if ($this->ffprobepath && $this->ffmpegpath)
        {
            if ($this->verbose) echo "Creating scaled video $dest with max dimension $size\n";
            $cmd = $this->ffprobepath . ' -v error -show_entries stream=width,height -of csv=p=0:s=x ' . escapeshellarg($src);
            $res = shell_exec($cmd);
            if (!empty($res))
            {
                foreach (explode("\n", $res) as $tr)
                {
                    if (strpos($tr, 'x') !== false)
                    {
                        list($width, $height) = explode("x", $res);
                        $width = intval(trim($width));
                        $height = intval(trim($height));
                        $scale = false;
                        if ($width > $size || $height > $size)
                        {
                            if ($width > $height)
                                $scale = "$size:-2";
                            else
                                $scale = "-2:$size";
                        }
                        $cmd = $this->ffmpegpath . ' -y -i ' . escapeshellarg($src) .
                            ' -vcodec h264 -acodec aac -strict -2 ' .
                            ($scale ? ' -vf "scale=' . $scale . '"' : '') . ' ' . pathinfo($dest, PATHINFO_DIRNAME) .
                            DIRECTORY_SEPARATOR . pathinfo($dest, PATHINFO_FILENAME) . '.mp4';
                        exec($cmd);
                        $cmd = $this->ffmpegpath . ' -y -i ' . escapeshellarg($src) .
                            ($scale ? ' -vf "scale=' . $scale . '"' : '') .
                            ' -c:v libvpx-vp9 -crf 30 -b:v 0 ' . pathinfo($dest, PATHINFO_DIRNAME) .
                            DIRECTORY_SEPARATOR . pathinfo($dest, PATHINFO_FILENAME) . '.webm';
                        exec($cmd);
                    }
                }
            }
        }
        else
            copy($src, $dest);
    }

    /**
     * Create a labeled version of an image, with keywords/title/caption
     * @param $album array album structure of the containing album
     * @param $image array the image structure to use as source
     * @param $keywords array list of keywords to bake into image
     */
    public function buildLabeledImage($album, $image, $keywords)
    {
        if (!$this->isVideo($image))
        {
            if ($this->verbose) echo "Creating keyword-labeled image\n";
            $base_path = $this->albumBasePath($album);
            $dest = $base_path . DIRECTORY_SEPARATOR . 'labeled';
            if (!is_dir($dest))
            {
                if ($this->verbose) echo "Creating directory $dest\n";
                mkdir($dest, 0755, true);
            }

            $disp_keywords = array();
            $exc = array_map(function ($d) {
                return mb_strtolower($d, mb_detect_encoding($d, 'UTF-8, ISO-8859-1'));
            }, explode(',', $album['config']['exclude_from_tagged']));

            foreach ($keywords as $tv)
            {
                $tk = mb_strtolower(trim($tv['keyword']), mb_detect_encoding($tv['keyword'], 'UTF-8, ISO-8859-1'));
                if (!in_array($tk, $exc))
                    $disp_keywords[] = trim($tv['keyword']);
            }

            if (!file_exists($dest . DIRECTORY_SEPARATOR . pathinfo($image['gallery_spec'], PATHINFO_FILENAME) . '.jpg'))
            {
                LabeledImage::createLabeledImage(
                    $image['spec'],
                    $dest . DIRECTORY_SEPARATOR . pathinfo($image['gallery_spec'], PATHINFO_FILENAME) . '.jpg',
                    array(
                        'title' => $image['title'],
                        'keywords' => $disp_keywords,
                        'border' => 3,
                        'cborder' => 3,
                        'font' => $album['config']['font_path']
                    ),
                    $this->exivpath);
            }
        }
    }

    /**
     * Scale an image using Imagick
     * @param $src
     * @param $dest
     * @param $max_pixels
     * @param $quality
     * @throws ImagickException
     */
    public function buildScaledImage($src, $dest, $max_pixels, $quality, $aspect = '')
    {
        $ret = '';
        if ($this->verbose) echo "Creating image $dest with max dimension $max_pixels\n";
        $image = new Imagick($src);
        $h = $image->getImageHeight();
        $w = $image->getImageWidth();
        $scaled = false;
        if (!empty($aspect))
        {
            list($asp_l, $asp_s) = preg_split('/[x\:]+/', $aspect);
            $target_asp = $asp_l / $asp_s;
            $current_asp = max($w / $h, $h / $w);
            if (abs($target_asp - $current_asp) > 0.1) // aspect ratios differ
            {
                if ($this->verbose) echo "Fixing aspect ratio\n";
                if ($h <= $w)
                    $image->cropThumbnailImage($max_pixels, floor($max_pixels / $target_asp));
                else
                    $image->cropThumbnailImage(floor($max_pixels / $target_asp), $max_pixels);
                $scaled = true;
            }
        }
        if (!$scaled && ($h > $max_pixels || $w > $max_pixels))
        {
            if ($this->verbose) echo "Normal scaling\n";
            if ($h <= $w)
                $image->resizeImage($max_pixels, 0, Imagick::FILTER_LANCZOS, 0.9);
            else
                $image->resizeImage(0, $max_pixels, Imagick::FILTER_LANCZOS, 0.9);

            $scaled = true;
        }
        if (!$scaled)
            copy($src, $dest);
        else
        {
            $image->setImageCompression(Imagick::COMPRESSION_JPEG);
            $image->setImageCompressionQuality($quality);
            $image->writeImage($dest);
            $h = $image->getImageHeight();
            $w = $image->getImageWidth();
        }
        $image->destroy();

        $ret = "{$w}x{$h}";
        return $ret;
    }


    /**
     * Get the configuration record for a directory if it has one, or travel up the path to the first
     * defined config (or use the system default)
     * @param $dir
     * @return array|bool|mixed
     */
    public function getDirConfig($dir)
    {
        $config = false;
        $dir_str = $dir;
        while (!$config && strlen($dir_str) > 0)
        {
            $this->_dbr['get_config']->execute(array(':spec' => $dir_str));
            $res = $this->_dbr['get_config']->fetch(PDO::FETCH_ASSOC);
            if ($res && $res['config'])
            {
                $config = json_decode($res['config'], true);
            }
            else
            {
                $dspieces = explode(DIRECTORY_SEPARATOR, $dir_str);
                array_pop($dspieces);
                $dir_str = implode(DIRECTORY_SEPARATOR, $dspieces);
            }
        }
        if (!$config)
        {
            if ($this->verbose) echo "Creating config point for '$dir'\n";
            $config = $this->defaultConfig();
        }
        return $config;
    }

    /**
     * System default settings
     * @return array
     */
    function defaultConfig()
    {
        return array(
            'destination_directory' => 'out',
            'htpasswd_directory' => '.',
            'base_url' => '',
            'site_title' => 'Static Gallery v.2 default',
            'title' => '',
            'copyright' => 'SjG',
            'description' => 'default config',
            'thumb_size' => 128,
            'thumb_quality' => 75,
            'display_size' => 800,
            'display_quality' => 75,
            'full_size' => 10000,
            'full_quality' => 100,
            'enable_keywords' => true,
            'create_labeled_image' => false,
            'enable_date_index' => true,
            'create_date_index_pages' => true,
            'font_path' => '/Library/fonts/Arial.ttf',
            'create_keyword_pages' => true,
            'download_largest' => true,
            'gallery_zipfile' => true,
            'exclude_keywords' => '',
            'exclude_from_tagged' => 'NIKON,NIKON CORPORATION,NIKON D70,E995,NIKON D90,Canon EOS DIGITAL REBEL,Canon,n/a',
            'template_index' => 'template_barebones/gallery.php',
            'template_gallery' => 'template_barebones/gallery.php',
            'template_image' => 'template_barebones/image.php',
            'template_keyword' => 'template_barebones/keyword.php',
            'template_keywords' => 'template_barebones/keywords.php',
            'template_date' => 'template_barebones/date.php',
            'template_dates' => 'template_barebones/dates.php',
            'template_copy' => 'template_barebones/style.css',
            'generated_page_extension' => 'html',
            'allowed_extensions' => 'gif,jpg,png,jpeg,mov,mp4,mpeg',
            'image_sort' => 'date',
            'image_sort_dir' => 'asc',
            'subalbum_sort' => 'date',
            'subalbum_sort_dir' => 'desc',
            'auth_realm' => '',
            'auth_users' => '',
            'gallery_thumbs' => 'random',
            'rename_images_by_gallery' => true,
            'thumb_aspect' => '',
            'album_name_keywords' => true,
            'video_date_fix' => true
        );
    }

    /**
     * List fo config items that do *not* get inherited
     * @return array
     */
    function getNoninheritedConfig()
    {
        return array('auth_realm', 'auth_users', 'title');
    }

    /**
     * read config files and create an appropriate array
     * @param $dir
     * @param $date
     */
    function getConfigs($dir, $date = false)
    {
        $new = null;
        $config = $this->getDirConfig($dir);
        foreach ($this->getNoninheritedConfig() as $tnic)
        {
            $config[$tnic] = '';
        }
        if (file_exists($dir . DIRECTORY_SEPARATOR . 'config.txt'))
        {
            $new = filemtime($dir . DIRECTORY_SEPARATOR . 'config.txt');
            if (!$date || $new > $date)
            {
                if ($this->verbose) echo "Reading $dir/config.txt\n";
                $conf = file($dir . DIRECTORY_SEPARATOR . 'config.txt');
                foreach ($conf as $tf)
                {
                    $tf = trim($tf);
                    if (!empty($tf) && substr($tf, 0, 1) != '#')
                    {
                        list($key, $val) = explode('=', $tf);
                        $key = trim($key);
                        $val = trim($val);
                        if (isset($config[$key]))
                        {
                            if (preg_match('/^y(es)?$|^t(rue)?$/i', $val))
                                $config[$key] = true;
                            else if (preg_match('/^n(o)?$|^f(alse)?$/i', $val))
                                $config[$key] = false;
                            else
                                $config[$key] = $val;
                        }
                    }
                }
            }
            else
                $new = null;
        }
        else if (!$date)
            $new = time();
        return array($new, $config);
    }

    /**
     * Generate all the static HTML pages
     */
    public function buildAlbumPages()
    {
        $start = time();
        if ($this->verbose) echo "Building album pages.\n";
        foreach ($this->_db->query('select * from albums' . ($this->force || $this->forcepages ? '' : ' where changed=1'), PDO::FETCH_ASSOC) as $ta)
        {
            $this->fixAlbum($ta);
            $this->buildAlbumPage($ta);
            if (!empty($ta['config']['auth_realm']) && !empty($ta['config']['auth_users']))
            {
                $this->buildBasicAuth($ta);
            }
        }
        if ($this->verbose) echo "Done in " . Utils::timedelta($start, time()) . ".\n";
    }

    /**
     * Iterate through all albums, build date indices (after fixing video dates where needed)
     */
    public function buildDateIndex()
    {
        $start = time();
        if ($this->verbose) echo "Handling Date Indices.\n";
        foreach ($this->_db->query('select * from albums' . ($this->force ? '' : ' where changed=1'), PDO::FETCH_ASSOC) as $ta)
        {
            $this->fixAlbum($ta);
            if ($ta['config']['video_date_fix'])
            {
                $this->fixVideoDatesForAlbum($ta);
            }
            if ($ta['config']['enable_date_index'])
            {
                $this->buildDateIndexForAlbum($ta);
            }
            $this->_db->prepare('UPDATE albums set latest_date=(select max(image_date) from images where parent=:id) where id=:id')->execute(array(':id' => $ta['id']));
        }
        if ($this->verbose) echo "Done in " . Utils::timedelta($start, time()) . ".\n";
    }

    public function buildDateIndexForAlbum($album)
    {
        if ($this->verbose) echo "Building date index for {$album['title']}\n";
        foreach ($this->_db->query('select * from images where parent=' . $album['id'] . ' order by spec', PDO::FETCH_ASSOC) as $ti)
        {
            $ym = array(':year' => date('Y', $ti['image_date']), ':month' => date('m', $ti['image_date']));
            $this->_dbr['get_ym']->execute($ym);
            $row = $this->_dbr['get_ym']->fetch(PDO::FETCH_ASSOC);
            if (!$row)
            {
                $this->_dbr['set_ym']->execute($ym);
                $this->_dbr['get_ym']->execute($ym);
                $row = $this->_dbr['get_ym']->fetch(PDO::FETCH_ASSOC);
                $this->_dbr['set_ym_image']->execute(array(':yearmonth_id' => $row['id'], ':image_id' => $ti['id']));
            }
            else
            {
                $this->_dbr['get_ym_image']->execute(array(':yearmonth_id' => $row['id'], ':image_id' => $ti['id']));
                $exists = $this->_dbr['get_ym_image']->fetch(PDO::FETCH_ASSOC);
                if (!$exists)
                    $this->_dbr['set_ym_image']->execute(array(':yearmonth_id' => $row['id'], ':image_id' => $ti['id']));
            }
        }
    }

    public function fixVideoDatesForAlbum($album)
    {
        if ($this->verbose) echo "Fixing video dates for {$album['title']}\n";
        $imgs = array();
        foreach ($this->_db->query('select * from images where parent=' . $album['id'] . ' order by spec', PDO::FETCH_ASSOC) as $ti)
        {
            $imgs[] = $ti;
        }
        $first = time();
        $last = -1;
        foreach ($imgs as $idx => $ti)
        {
            if (!$this->isVideo($ti))
            {
                if ($ti['image_date'] < $first)
                    $first = $ti['image_date'];
                else if ($ti['image_date'] > $last)
                    $last = $ti['image_date'];
            }
        }
        $total = count($imgs);
        foreach ($imgs as $idx => $ti)
        {
            if ($this->isVideo($ti))
            {
                $prev_ind = $idx;
                $prev = false;
                while (!$prev && $prev_ind > -1)
                {
                    if (!$this->isVideo($imgs[$prev_ind]))
                        $prev = $imgs[$prev_ind]['image_date'];
                    else
                        $prev_ind--;
                }
                if (!$prev)
                    $prev = $first;
                $next_ind = $idx;
                $next = false;
                while (!$next && $next_ind < $total)
                {
                    if (!$this->isVideo($imgs[$next_ind]))
                        $next = $imgs[$next_ind]['image_date'];
                    else
                        $next_ind++;
                }
                if (!$next)
                    $next = $last;
                $this->_dbr['update_image_date']->execute(array(':image_date' => floor(($next + $prev) / 2), ':id' => $ti['id']));
            }
        }
    }


    /**
     * Iterate through all albums and build the sized images where needed
     */
    public function buildSizedImages()
    {
        $start = time();
        if ($this->verbose) echo "Building sized image set.\n";
        foreach ($this->_db->query('select * from albums' . ($this->force ? '' : ' where changed=1'), PDO::FETCH_ASSOC) as $ta)
        {
            $this->fixAlbum($ta);
            $this->buildSizedImagesForAlbum($ta);
        }
        if ($this->verbose) echo "Done in " . Utils::timedelta($start, time()) . ".\n";
    }

    /**
     * Iterate through a single albums and build the sized images where needed
     * @param $album
     */
    public function buildSizedImagesForAlbum($album)
    {
        if ($this->verbose) echo "Building sized images for {$album['title']}\n";
        $images = array();
        foreach ($this->_db->query('select * from images where parent=' . $album['id'], PDO::FETCH_ASSOC) as $ti)
        {
            $images[] = $ti;
        }
        if (count($images) > 0)
        {
            $this->sortImageList($album, $images);
            // provide some capacity for later additions in sequence.
            $needed_digits = log10(count($images));
            $needed_digits = ceil($needed_digits) + (fmod($needed_digits, 1) >= 0.875 || fmod($needed_digits, 1) == 0 ? 1 : 0);
            $idxlenfmt = "%0{$needed_digits}d";
            foreach ($images as $idx => $image)
            {
                if ($album['config']['rename_images_by_gallery'])
                {
                    // update the title too, if it's not set from keyword/caption
                    $replace_title = !strcasecmp($image['title'], Utils::makeHumanNice(pathinfo($image['spec'], PATHINFO_FILENAME)));
                    $title = $album['title'] . '-' . sprintf($idxlenfmt, $idx);
                    // video gets a jpg extension so thumbnails work, remapped elsewhere as necessary
                    $ext = ($this->isVideo($image) ? 'jpg' : pathinfo($image['spec'], PATHINFO_EXTENSION));
                    $dspec = Utils::beautify_filename($title . '.' . $ext);
                    $this->_dbr['set_image_title']->execute(array(':title' => $replace_title ? $title : $image['title'], ':id' => $image['id'], ':gallery_spec' => $dspec));
                    $image['title'] = $replace_title ? $title : $image['title'];
                    $image['gallery_spec'] = $dspec;
                }
                $this->buildImageSet($image, $album);
            }
        }
    }

    /**
     * Create the .htaccess file and the htaccess password file for a protected gallery
     * @param $album
     */
    public function buildBasicAuth($album)
    {
        if ($this->verbose) echo "Installing Basic Authentication for album.\n";
        $users = explode(';', $album['config']['auth_users']);

        $htpasswd_file = '';
        foreach ($users as $idx => $u)
        {
            list($name, $password) = explode(':', $u);
            $password = password_hash($password, PASSWORD_BCRYPT);
            $htpasswd_file .= "$name:$password\n";
        }
        $password_spec = pathinfo($album['spec'], PATHINFO_BASENAME) . '.htpasswd';
        $password_loc = $album['config']['htpasswd_directory'] . DIRECTORY_SEPARATOR . $password_spec;
        $password_loc = preg_replace('/\/+/', '/', $password_loc);
        if (substr($password_loc, 0, 1) == '/')
            $password_rel = $password_loc; // absolute
        else
            $password_rel = preg_replace('/\/+/', '/', Utils::relPath(rtrim($this->albumBasePath($album), DIRECTORY_SEPARATOR), $album['config']['htpasswd_directory']) . $password_spec);


        file_put_contents($password_loc, $htpasswd_file);
        file_put_contents($this->albumBasePath($album) . DIRECTORY_SEPARATOR . '.htaccess',
            "Authtype Basic
AuthName \"{$album['config']['auth_realm']}\"
AuthUserFile {$password_rel}
Require valid-user");
    }

    /**
     * @param $album
     * @return bool
     */
    public function getParentAlbum($album)
    {
        if ($album['parent'])
        {
            $alb = $this->_db->query('select * from albums where id=' . $album['parent'])->fetch(PDO::FETCH_ASSOC);
            $this->fixAlbum($alb);
            return $alb;
        }
        return false;
    }

    /**
     * @param $album
     * @return array
     */
    public function getAlbumBreadcrumbs($album)
    {
        $albs = array();
        $last_alb = $album;
        while ($last_alb = $this->getParentAlbum($last_alb))
        {
            $last_alb['url'] = $this->albumRelativeBaseUrl($album, $last_alb) . 'index.' . $album['config']['generated_page_extension'];
            $albs[] = $last_alb;
            //$album = $last_alb;
        }
        return $albs;
    }

    /**
     * @param $album
     * @return array
     */
    public function thumbImageForAlbum($album)
    {
        $ret = false;
        $nada = false;
        while (!$ret && !$nada)
        {
            # options are random, alpha (first alpha), oldest, youngest
            if (!isset($album['config']['gallery_thumbs']) || !strcasecmp($album['config']['gallery_thumbs'], 'random'))
            {
                $where_ord = 'and _ROWID_ >= (abs(random()) % (SELECT max(_ROWID_) FROM images where parent=' . $album['id'] . '))';
            }
            else if (!strcasecmp($album['config']['gallery_thumbs'], 'alpha'))
            {
                $where_ord = 'order by title';
            }
            else if (!strcasecmp($album['config']['gallery_thumbs'], 'oldest'))
            {
                $where_ord = 'order by image_date desc';
            }
            else
            {
                $where_ord = 'order by image_date asc';
            }
            foreach ($this->_db->query("select * from images where parent={$album['id']} $where_ord limit 1", PDO::FETCH_ASSOC) as $ti)
            {
                $ret = $ti;
            }
            if (!$ret)
            {
                $subalb = $this->thumbSubAlbumForAlbum($album);
                if (!$subalb)
                    $nada = true;
                else
                    list($album, $ret) = $this->thumbImageForAlbum($subalb);
            }
        }
        return array($album, $ret);
    }

    /**
     * @param $album
     * @return bool
     */
    public function thumbSubAlbumForAlbum($album)
    {
        $ret = false;
        if (!strcasecmp($album['config']['gallery_thumbs'], 'random'))
        {
            $where_ord = 'and _ROWID_ >= (abs(random()) % (SELECT max(_ROWID_) FROM albums where parent=' . $album['id'] . '))';
        }
        else if (!strcasecmp($album['config']['gallery_thumbs'], 'alpha'))
        {
            $where_ord = 'order by title';
        }
        else if (!strcasecmp($album['config']['gallery_thumbs'], 'oldest'))
        {
            $where_ord = 'order by latest_date desc';
        }
        else
        {
            $where_ord = 'order by latest_date asc';
        }
        foreach ($this->_db->query("select * from albums where parent={$album['id']} $where_ord limit 1", PDO::FETCH_ASSOC) as $ti)
        {
            $ret = $ti;
        }
        return $ret;
    }

    /**
     * @param $album
     * @param $images
     */
    public function sortImageList($album, &$images)
    {
        if ($album['config']['image_sort'] == 'date')
        {
            if ($album['config']['image_sort_dir'] == 'asc')
                usort($images, function ($a, $b) {
                    return ($a['image_date'] < $b['image_date'] ? -1 : 1);
                });
            else
                usort($images, function ($a, $b) {
                    return ($a['image_date'] > $b['image_date'] ? -1 : 1);
                });
        }
        else
        {
            if ($album['config']['image_sort_dir'] == 'asc')
                usort($images, function ($a, $b) {
                    return strcasecmp($a['title'], $b['title']);
                });
            else
                usort($images, function ($a, $b) {
                    return strcasecmp($b['title'], $a['title']);
                });
        }
    }

    /**
     * @param $album
     */
    public function buildAlbumPage($album)
    {
        if ($this->verbose) echo "Creating album page for {$album['title']}\n";
        $album_url_prefix = $album['config']['base_url'] == '*' ? '' : $this->albumRelativeBaseUrl($album, $album);
        $images = array();
        foreach ($this->_db->query('select * from images where parent=' . $album['id'], PDO::FETCH_ASSOC) as $ti)
        {
            $spec = pathinfo($ti['gallery_spec']);
            foreach ($this->_sizes as $idx => $size)
            {
                // correct for video
                $ti[$size . '_url'] = $album_url_prefix . $size . '/' . ($idx > 0 && $this->isVideo($ti) ? $spec['filename'] : $spec['basename']);
                $ti['page_url'] = $album_url_prefix . Utils::unicodeSanitizeFilename($ti['title']) . '.' . $album['config']['generated_page_extension'];
            }
            $images[] = $ti;
        }
        $this->sortImageList($album, $images);

        if ($album['config']['gallery_zipfile'])
        {
            $zipurl = $this->buildZipFile($album);
            $album['zip_url'] = $album_url_prefix . $zipurl;
        }

        $subalbums = array();
        foreach ($this->_db->query('select * from albums where parent=' . $album['id'], PDO::FETCH_ASSOC) as $ta)
        {
            $this->fixAlbum($ta);
            $ta['url'] = $this->albumRelativeBaseUrl($album, $ta) . 'index.' . $ta['config']['generated_page_extension'];
            $subalbums[] = $ta;
        }
        // can't nest queries in sqllite
        foreach ($subalbums as $idx => $ta)
        {
            $subalbums[$idx]['subalbum_count'] = $this->_db->query('select count(*) from albums where parent=' . $ta['id'])->fetchColumn();
            $subalbums[$idx]['image_count'] = $this->_db->query('select count(*) from images where parent=' . $ta['id'])->fetchColumn();
            list ($thumbalb, $thumb) = $this->thumbImageForAlbum($ta);
            if (!empty($thumb))
                $subalbums[$idx]['thumb_url'] = $this->albumRelativeBaseUrl($album, $thumbalb) . $this->_sizes[0] . '/' . pathinfo($thumb['gallery_spec'], PATHINFO_BASENAME);
            else
                $subalbums[$idx]['thumb_url'] = '';
        }

        if ($album['config']['subalbum_sort'] == 'date')
        {
            if ($album['config']['subalbum_sort_dir'] == 'asc')
                usort($subalbums, function ($a, $b) {
                    return ($a['latest_date'] < $b['latest_date'] ? -1 : 1);
                });
            else
                usort($subalbums, function ($a, $b) {
                    return ($a['latest_date'] > $b['latest_date'] ? -1 : 1);
                });
        }
        else
        {
            if ($album['config']['subalbum_sort_dir'] == 'asc')
                usort($subalbums, function ($a, $b) {
                    return strcasecmp($a['title'], $b['title']);
                });
            else
                usort($subalbums, function ($a, $b) {
                    return strcasecmp($b['title'], $a['title']);
                });
        }

        $breadcrumbs = array_reverse($this->getAlbumBreadcrumbs($album));
        $breadcrumbs[] = array('url' => $album_url_prefix . 'index.' . $album['config']['generated_page_extension'], 'title' => $album['title']);

        $keyword_virtual = $this->getKeywordVirtualAlbum();
        $date_virtual = $this->getDateVirtualAlbum();
        $toplinks = array();

        if ($album['config']['create_keyword_pages'])
        {
            $toplinks[] = array('url' => $this->albumRelativeBaseUrl($album, $keyword_virtual) . 'index.' . $album['config']['generated_page_extension'], 'title' => 'Keywords');
        }
        if ($album['config']['create_date_index_pages'])
        {
            $toplinks[] = array('url' => $this->albumRelativeBaseUrl($album, $date_virtual) . 'index.' . $album['config']['generated_page_extension'], 'title' => 'Dates');
        }

        ob_start();
        require($album['config']['template_gallery']);
        $contents = ob_get_contents();
        ob_end_clean();
        file_put_contents($this->albumBasePath($album) . DIRECTORY_SEPARATOR . 'index.' . $album['config']['generated_page_extension'], $contents);

        if (!empty($album['config']['template_image']))
        {
            // create image pages
            foreach ($images as $idx => $image)
            {
                if ($this->verbose) echo "Creating image page for {$image['title']}\n";
                $keywords = array();
                foreach ($this->_db->query('select k.keyword from keywords k,keywords_images ik where k.id=ik.keyword_id and ik.image_id=' . $image['id'], PDO::FETCH_ASSOC) as $tk)
                {
                    $tk['url'] = $this->albumRelativeBaseUrl($album, $keyword_virtual) . Utils::unicodeSanitizeFilename($tk['keyword']) . '.' . $album['config']['generated_page_extension'];
                    $keywords[] = $tk;
                }
                $labeledspec = 'labeled/' . pathinfo($image['gallery_spec'], PATHINFO_FILENAME) . '.jpg';
                if ($album['config']['create_labeled_image'] && (!file_exists($this->albumBasePath($album) . $labeledspec) || $this->force))
                {
                    $this->buildLabeledImage($album, $image, $keywords);
                    $image['labeled_url'] = $album_url_prefix . $labeledspec;
                }
                $image['next'] = $images[($idx + 1 == count($images) ? 0 : $idx + 1)];
                $image['prev'] = $images[($idx > 1 ? $idx - 1 : count($images) - 1)];
                if ($album['config']['create_date_index_pages'])
                {
                    $image['date_url'] = $this->albumRelativeBaseUrl($album, $date_virtual) . date('Y-m', $image['image_date']) . '.' . $album['config']['generated_page_extension'];;
                }
                ob_start();
                require($album['config']['template_image']);
                $contents = ob_get_contents();
                ob_end_clean();
                file_put_contents($this->albumBasePath($album) . DIRECTORY_SEPARATOR . Utils::unicodeSanitizeFilename($image['title']) . '.' . $album['config']['generated_page_extension'], $contents);
            }
        }
        if (count($images) > 0 && $album['config']['gallery_zipfile'])
        {
            $zipurl = $this->buildZipFile($album);
            $album['zip_url'] = $album_url_prefix . $zipurl;
        }
    }

    /**
     * @param $obj
     * @param $attr
     * @return mixed|string
     */
    public function url($obj, $attr)
    {
        return $this->out($obj, $attr, '#', false, false);
    }

    /**
     * @param $attr
     * @param $album
     * @return mixed|string
     */
    public function rootAlbum($attr, $album)
    {
        $root = $this->getRootAlbum();
        $root['url'] = $this->albumRelativeBaseUrl($album, $root);
        return $this->out($root, $attr);
    }

    /**
     * @param $album
     * @return bool|string
     */
    public function buildZipFile($album)
    {
        $path = $this->_sizes[count($this->_sizes) - 1];
        if ($album['config']['create_labeled_image'])
            $path = 'labeled';

        $base_path = $this->albumBasePath($album) . DIRECTORY_SEPARATOR . $path;
        $spec = Utils::unicodeSanitizeFilename($album['title']) . '.zip';

        if (!file_exists($this->albumBasePath($album) . DIRECTORY_SEPARATOR . $spec) || $album['changed'] || $this->force)
        {
            if (is_dir($base_path))
            {
                if ($this->verbose) echo "Creating zip file for album '{$album['title']}'\n";


                $allowed = explode(',', $album['config']['allowed_extensions']);
                array_walk($allowed, function ($d) {
                    return trim($d);
                });

                $zip = new ZipArchive;
                if ($zip->open($this->albumBasePath($album) . DIRECTORY_SEPARATOR . $spec, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE)
                {
                    $cdir = scandir($base_path);
                    foreach ($cdir as $key => $value)
                    {
                        if (!in_array($value, array(".", "..")))
                        {
                            $ext = strtolower(pathinfo($value, PATHINFO_EXTENSION));
                            if (in_array($ext, $allowed))
                            {
                                $zip->addFile($base_path . DIRECTORY_SEPARATOR . $value, 'pictures/' . $value);
                            }
                        }
                    }
                    $res = $zip->close();
                    if ($res !== TRUE)
                        $spec = false;
                }
            }
            else
                $spec = false;
        }
        return $spec;
    }

    /**
     * Safe output
     * @param $obj
     * @param $attr
     * @param string $default
     * @param bool $html_escape
     * @param bool $url_encode
     * @return mixed|string
     */
    public function out($obj, $attr, $default = '', $html_escape = true, $url_encode = false)
    {
        foreach (explode('.', $attr) as $name)
        {
            if (is_object($obj) && isset($obj->$name))
                $obj = $obj->$name;
            else if (is_array($obj) && isset($obj[$name]))
                $obj = $obj[$name];
            else
            {
                $obj = $default;
                break;
            }
        }
        if ($html_escape)
            $obj = htmlentities($obj);
        if ($url_encode)
            $obj = urlencode($obj);
        return $obj;
    }


    /**
     *
     */
    public function buildDatePages()
    {
        $start = time();
        // anyone want date indexed images?
        $albums = array();
        $dw = false;
        foreach ($this->_db->query('select * from albums', PDO::FETCH_ASSOC) as $ta)
        {
            $this->fixAlbum($ta);
            if ($ta['config']['create_date_index_pages'])
                $dw = true;
            $albums[$ta['id']] = $ta;
        }
        if ($dw)
        {
            $date_album = $this->getDateVirtualAlbum();
            if ($this->verbose) echo "Generating date index files.\n";

            $dest = $this->albumBasePath($date_album);
            if (!is_dir($dest))
            {
                if ($this->verbose) echo "Creating directory $dest\n";
                mkdir($dest, 0755, true);
            }

            $dates = array();

            $album_url_prefix = $date_album['config']['base_url'] == '*' ? '' : $this->albumRelativeBaseUrl($date_album, $date_album);
            $breadcrumbs = array_reverse($this->getAlbumBreadcrumbs($date_album));
            $breadcrumbs[] = array('url' => $album_url_prefix . 'index.' . $date_album['config']['generated_page_extension'], 'title' => $date_album['title']);

            foreach ($this->_db->query('select * from yearmonths', PDO::FETCH_ASSOC) as $ty)
            {
                $ty['page_url'] = $this->albumRelativeBaseUrl($date_album, $date_album) .
                    Utils::unicodeSanitizeFilename($ty['year'] . '-' . sprintf('%02d', $ty['month'])) . '.' . $date_album['config']['generated_page_extension'];
                $dates[] = $ty;
            }
            usort($dates, function ($a, $b) {
                return strcasecmp($a['year'] . '-' . $a['month'], $b['year'] . '-' . $b['month']);
            });
            foreach ($dates as $idx => $date)
            {
                $album = $date_album;
                $images = array();
                foreach ($this->_db->query('select i.title,i.spec,i.parent,i.gallery_spec from images i,yearmonths_images iy where i.id=iy.image_id and iy.yearmonth_id=' . $date['id'], PDO::FETCH_ASSOC) as $ti)
                {
                    $img_album = $albums[$ti['parent']];
                    $ti['page_url'] = $this->albumRelativeBaseUrl($date_album, $img_album) .
                        Utils::unicodeSanitizeFilename($ti['title']) . '.' . $img_album['config']['generated_page_extension'];
                    // correct for video
                    $ti['thumb_url'] = $this->albumRelativeBaseUrl($date_album, $img_album) . $this->_sizes[0] .
                        '/' . ($this->isVideo($ti) ? pathinfo($ti['gallery_spec'], PATHINFO_FILENAME) . '.jpg' : pathinfo($ti['gallery_spec'], PATHINFO_BASENAME));
                    $ti['album_url'] = $this->albumRelativeBaseUrl($date_album, $img_album) . 'index.' . $img_album['config']['generated_page_extension'];
                    $ti['album_title'] = $img_album['title'];
                    $images[] = $ti;
                }
                $dates[$idx]['count'] = count($images);
                $album['title'] = Utils::month($date['month']) . ' ' . $date['year'];
                $album['show_album'] = 1;
                $album['no-zip'] = 1;
                ob_start();
                require($album['config']['template_date']);
                $contents = ob_get_contents();
                ob_end_clean();
                file_put_contents($dest . DIRECTORY_SEPARATOR . Utils::unicodeSanitizeFilename($date['year'] . '-' . sprintf('%02d', $date['month'])) . '.' . $date_album['config']['generated_page_extension'], $contents);
            }

            $album = $date_album;
            ob_start();
            require($album['config']['template_dates']);
            $contents = ob_get_contents();
            ob_end_clean();
            file_put_contents($dest . DIRECTORY_SEPARATOR . 'index.' . $date_album['config']['generated_page_extension'], $contents);

        }
        if ($this->verbose) echo "Done in " . Utils::timedelta($start, time()) . ".\n";
    }

    /**
     *
     */
    public function buildKeywordPages()
    {
        $start = time();
        // anyone want keywords?
        $kw = false;
        $albums = array();
        foreach ($this->_db->query('select * from albums', PDO::FETCH_ASSOC) as $ta)
        {
            $this->fixAlbum($ta);
            if ($ta['config']['create_keyword_pages'])
                $kw = true;
            $albums[$ta['id']] = $ta;
        }
        if ($kw)
        {
            $keyword_album = $this->getKeywordVirtualAlbum();
            if ($this->verbose) echo "Generating keyword files.\n";

            $dest = $this->albumBasePath($keyword_album);
            if (!is_dir($dest))
            {
                if ($this->verbose) echo "Creating directory $dest\n";
                mkdir($dest, 0755, true);
            }

            $keywords = array();

            $album_url_prefix = $keyword_album['config']['base_url'] == '*' ? '' : $this->albumRelativeBaseUrl($keyword_album, $keyword_album);
            $breadcrumbs = array_reverse($this->getAlbumBreadcrumbs($keyword_album));
            $breadcrumbs[] = array('url' => $album_url_prefix . 'index.' . $keyword_album['config']['generated_page_extension'], 'title' => $keyword_album['title']);

            foreach ($this->_db->query('select * from keywords', PDO::FETCH_ASSOC) as $tk)
            {
                $tk['page_url'] = $this->albumRelativeBaseUrl($keyword_album, $keyword_album) .
                    Utils::unicodeSanitizeFilename($tk['safekeyword']) . '.' . $keyword_album['config']['generated_page_extension'];
                $keywords[] = $tk;
            }
            usort($keywords, function ($a, $b) {
                return strcasecmp($a['keyword'], $b['keyword']);
            });
            foreach ($keywords as $idx => $keyword)
            {
                $album = $keyword_album;
                $images = array();
                foreach ($this->_db->query('select i.title,i.spec,i.parent,i.gallery_spec from images i,keywords_images ik where i.id=ik.image_id and ik.keyword_id=' . $keyword['id'], PDO::FETCH_ASSOC) as $ti)
                {
                    $img_album = $albums[$ti['parent']];
                    $ti['page_url'] = $this->albumRelativeBaseUrl($keyword_album, $img_album) .
                        Utils::unicodeSanitizeFilename($ti['title']) . '.' . $img_album['config']['generated_page_extension'];
                    $ti['thumb_url'] = $this->albumRelativeBaseUrl($keyword_album, $img_album) . $this->_sizes[0] .
                        '/' . ($this->isVideo($ti) ? pathinfo($ti['gallery_spec'], PATHINFO_FILENAME) . '.jpg' : pathinfo($ti['gallery_spec'], PATHINFO_BASENAME));
                    $ti['album_url'] = $this->albumRelativeBaseUrl($keyword_album, $img_album) . 'index.' . $img_album['config']['generated_page_extension'];
                    $ti['album_title'] = $img_album['title'];
                    $images[] = $ti;
                }
                $keywords[$idx]['count'] = count($images);
                $album['title'] = $keyword['keyword'];
                $album['show_album'] = 1;
                $album['no-zip'] = 1;
                ob_start();
                require($album['config']['template_keyword']);
                $contents = ob_get_contents();
                ob_end_clean();
                file_put_contents($dest . DIRECTORY_SEPARATOR . Utils::unicodeSanitizeFilename($keyword['safekeyword']) . '.' . $keyword_album['config']['generated_page_extension'], $contents);
            }
            $album = $keyword_album;
            ob_start();
            require($album['config']['template_keywords']);
            $contents = ob_get_contents();
            ob_end_clean();
            file_put_contents($dest . DIRECTORY_SEPARATOR . 'index.' . $keyword_album['config']['generated_page_extension'], $contents);
        }
        if ($this->verbose) echo "Done in " . Utils::timedelta($start, time()) . ".\n";
    }

    /**
     *
     */
    public function buildTemplateCopies()
    {
        $root = $this->getRootAlbum();
        if ($root['config']['template_copy'])
        {
            $files = explode(',', $root['config']['template_copy']);
            foreach ($files as $tf)
            {
                $this->recursiveCopyDir(trim($tf), $this->albumBasePath($root) . pathinfo($tf, PATHINFO_BASENAME));
            }
        }
    }

    /**
     * @param $source
     * @param $dest
     * @param array $excludes
     */
    public function recursiveCopyDir($source, $dest, $excludes = array())
    {
        if (!in_array('.', $excludes))
            $excludes[] = '.';
        if (!in_array('..', $excludes))
            $excludes[] = '..';

        if ($this->verbose) echo "Copying $source\n";
        if (is_dir($source))
        {
            $dir_handle = opendir($source);
            while ($file = readdir($dir_handle))
            {
                if (!in_array($file, $excludes))
                {
                    if (is_dir($source . DIRECTORY_SEPARATOR . $file))
                    {
                        if (!is_dir($dest . DIRECTORY_SEPARATOR . $file))
                        {
                            mkdir($dest . DIRECTORY_SEPARATOR . $file, 0777, true);
                        }
                        $this->recursiveCopyDir($source . DIRECTORY_SEPARATOR . $file, $dest . DIRECTORY_SEPARATOR . $file, $excludes);
                    }
                    else
                    {
                        if (!is_dir($dest))
                            mkdir($dest, 0777, true);
                        copy($source . DIRECTORY_SEPARATOR . $file, $dest . DIRECTORY_SEPARATOR . $file);
                    }
                }
            }
            closedir($dir_handle);
        }
        else
        {
            copy($source, $dest);
        }
    }

}
