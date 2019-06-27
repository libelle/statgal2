<?php
$title = $this->out($album, 'title');
require('_header.php');
?>

  <h2><?php echo $this->out($album, 'title'); ?></h2>

<?php if (isset($subalbums) && count($subalbums)) { ?>
  <section class="border">
    <h3>Subalbums</h3>
    <div id="subalbums" style="display:none;">
        <?php foreach ($subalbums as $tsa) { ?>
            <?php
            $dets = array();
            $k = $this->out($tsa, 'subalbum_count');
            if ($k > 0) $dets[] = "$k albums";
            $j = $this->out($tsa, 'image_count');
            if ($j > 0) $dets[] = "$j images";
            $desc = $this->out($tsa, 'title').' : '.implode(' | ', $dets);
            ?>
            <a href="<?php echo $this->url($tsa, 'url') ?>">
              <img alt="<?php echo $desc; ?>" src="<?php echo $this->url($tsa, 'thumb_url'); ?>"
                   data-image="<?php echo $this->url($tsa, 'display_url'); ?>"
                   data-description="<?php echo $desc; ?>">
            </a>
        <?php } ?>
    </div>
  </section>
<?php } ?>
<?php if (isset($images) && count($images)) { ?>
  <section class="border">
    <h3>Images</h3>
    <div id="images" style="display:none;">
        <?php foreach ($images as $ti) { ?>
            <a href="<?php echo $this->url($ti, 'page_url') ?>">
              <img src="<?php echo $this->url($ti, 'thumb_url') ?>"
                   data-image="<?php echo $this->url($ti, 'display_url'); ?>"
                   data-description="<?php echo $this->out($ti, 'title') ?>" alt="<?php echo $this->out($ti, 'title') ?>">
            </a>
        <?php } ?>
    </div>
      <?php
      if ($this->out($album, 'config.gallery_zipfile') && $this->url($album, 'zip_url') && empty($this->out($album,'no-zip')))
          echo '<br><nav><a href="' . $this->url($album, 'zip_url') . '">Download gallery as a zip file</a></nav>';
      ?>
  </section>
<?php } ?>
<?php
require('_footer.php');
?>
