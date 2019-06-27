<?php
$title = $this->out($album, 'title');
require('_header.php');
?>

  <h2><?php echo $this->out($album, 'title'); ?></h2>

<?php if (isset($subalbums) && count($subalbums)) { ?>
  <section class="border">
    <h3>Subalbums</h3>
    <ul class="grid">
        <?php foreach ($subalbums as $tsa) { ?>
          <li>
            <a href="<?php echo $this->url($tsa, 'url') ?>">
              <img src="<?php echo $this->url($tsa, 'thumb_url'); ?>"/>
              <br>
                <?php echo $this->out($tsa, 'title'); ?>
            </a>
            <br>
              <?php
              $dets = array();
              $k = $this->out($tsa, 'subalbum_count');
              if ($k > 0) $dets[] = "$k sub-albums";
              $j = $this->out($tsa, 'image_count');
              if ($j > 0) $dets[] = "$j images";
              echo implode(' | ', $dets);
              ?>
          </li>
        <?php } ?>
    </ul>
  </section>
<?php } ?>
<?php if (isset($images) && count($images)) { ?>
  <section class="border">
    <h3>Images</h3>
    <ul class="grid">
        <?php foreach ($images as $ti) { ?>
          <li>
            <a href="<?php echo $this->url($ti, 'page_url') ?>"><img src="<?php echo $this->url($ti, 'thumb_url') ?>"
                                                                     alt="<?php echo $this->url($ti, 'title') ?>"/><br>
                <?php echo $this->out($ti, 'title') ?>
            </a><?php echo $this->out($album,'show_album')!=''?'<br>in <a href="'.$this->url($ti,'album_url').'">'.$this->out($ti,'album_title').'</a>':'';?>
          </li>
        <?php } ?>
    </ul>
      <?php
      if ($this->out($album, 'config.gallery_zipfile') && $this->url($album, 'zip_url') && empty($this->out($album,'no-zip')))
          echo '<br><nav><a href="' . $this->url($album, 'zip_url') . '">Download gallery as a zip file</a></nav>';
      ?>
  </section>
<?php } ?>
<?php
require('_footer.php');
?>