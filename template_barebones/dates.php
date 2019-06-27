<?php
$title= 'Pictures By Date';
require('_header.php');
?>
    <h2>Dates</h2>
    <ul class="threecol">
        <?php foreach ($dates as $tk) { ?>
            <li>
                <a href="<?php echo $this->url($tk, 'page_url') ?>"><?php echo Utils::month($this->out($tk, 'month')).' - '.$this->out($tk, 'year') ?></a>
                (<?php echo $this->out($tk, 'count') ?> images)
            </li>
        <?php } ?>
    </ul>
<?php
require('_footer.php');
?>