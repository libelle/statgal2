<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo $title; ?></title>
  <link rel="stylesheet" type="text/css" href="<?php echo $this->rootAlbum('url',$album);?>style.css" />
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
