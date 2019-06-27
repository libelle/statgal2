<?php
$title = $this->out($album, 'title') . ' - ' . $this->out($image, 'title');
require('_header.php');
?>
  <h2><?php echo $this->out($image, 'title'); ?></h2>
  <div class="image">
    <?php if ($this->isVideo($image)) {
      ?>
      <video controls>
        <source src="<?php echo $this->url($image,'display_url'); ?>.webm" type="video/webm; codecs=vp9,vorbis">
        <source src="<?php echo $this->url($image,'display_url'); ?>.mp4" type="video/mp4">
        Your browser does not support the video tag.
      </video>
    <?php } else { ?>
    <img src="<?php echo $this->url($image, 'display_url'); ?>" alt="<?php echo $this->out($image, 'title'); ?>">
    <?php } ?>
    <nav>
      <a class="left" href="<?php echo $this->out($image, 'prev.page_url') ?>">&lt; Previous
        (<?php echo $this->out($image, 'prev.title') ?>)</a>
      <a class="right" href="<?php echo $this->out($image, 'next.page_url') ?>">Next
        (<?php echo $this->out($image, 'next.title') ?>) &gt;</a>
    </nav>
  </div>
<?php if (count($keywords)) { ?>
  <h3>Keywords:</h3>
  <ul class="keywords">
      <?php foreach ($keywords as $tk) { ?>
        <li>
          <a href="<?php echo $this->url($tk, 'url') ?>">
              <?php echo $this->out($tk, 'keyword') ?>
          </a>
        </li>
      <?php } ?>
  </ul>
<?php } ?>
  <br>
<?php echo (isset($image['date_url']) ? '<a href="' . $image['date_url'] . '">' : '') .
    date('Y-m', $image['image_date']) .
    (isset($image['date_url']) ? '</a>' : '').
    date('-d H:i:s',$image['image_date']);
?>
  <br>
<?php $dl = array();
if ($this->out($album, 'config.download_largest'))
    $dl[] = '<a href="' . $this->url($image, 'full_url') .($this->isVideo($image)?'.mp4':''). '">Download largest size ('.$this->out($image,'fullsize').')</a>';
if ($this->out($album, 'config.create_labeled_image') && !$this->isVideo($image))
    $dl[] = '<a href="' . $this->url($image, 'labeled_url') . '">Download labeled image</a>';
if (count($dl))
{
    echo '<nav>' . implode(' | ', $dl) . '</nav>';
}
?>
<?php
require('_footer.php');
?>
