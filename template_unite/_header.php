<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo $title; ?></title>

  <script type="text/javascript" src="<?php echo $this->rootAlbum('url',$album);?>unitegallery/js/jquery-11.0.min.js"></script>
  <script type="text/javascript" src="<?php echo $this->rootAlbum('url',$album);?>unitegallery/js/unitegallery.js"></script>

  <link rel="stylesheet" href="<?php echo $this->rootAlbum('url',$album);?>unitegallery/css/unite-gallery.css" type="text/css" />
  <script type="text/javascript" src="<?php echo $this->rootAlbum('url',$album);?>unitegallery/themes/tiles/ug-theme-tiles.js"></script>
  <link rel="stylesheet" href="<?php echo $this->rootAlbum('url',$album);?>style.css" type="text/css" />
</head>
<body>
<header>
  <h1><?php echo $this->rootAlbum('config.site_title',$album)?></h1>
</header>
<main>
<?php
$bcl = array();
foreach ($breadcrumbs as $tbc)
{
    $bcl[] = '<a href="' . $this->url($tbc, 'url') . '">' . $this->out($tbc, 'title') . '</a>';
}
if (count($bcl) > 0) echo '<ul class="breadcrumb"><li>' . implode('</li><li>', $bcl) . '</li></ul>';
?>
