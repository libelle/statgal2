<?php
$title= 'Keywords';
require('_header.php');
?>
<h2>Keywords</h2>
<ul class="threecol">
    <?php foreach ($keywords as $tk) { ?>
        <li>
            <a href="<?php echo $this->url($tk, 'page_url') ?>"><?php echo $this->out($tk, 'keyword') ?></a>
            (<?php echo $this->out($tk, 'count') ?> images)
        </li>
    <?php } ?>
</ul>
<?php
require('_footer.php');
?>