</main>
<footer>
    Copyright &copy; <?php echo date('Y').' '.$this->out($album, 'config.copyright') ?>
</footer>
<script type="text/javascript">

    jQuery(document).ready(function(){
        jQuery("#subalbums").unitegallery({
            gallery_theme: "tiles",
            tiles_type:'cols',
                tiles_col_width: 200,
            tile_overlay_opacity:0.3,
            tile_enable_icons:false,
            tile_enable_textpanel:true,
            tile_as_link:true,
            tile_link_newpage:false,
            lightbox_textpanel_title_color:"e5e5e5"}
            );
        jQuery("#images").unitegallery({
            gallery_theme: "tiles",
            tiles_type:'cols',
            tiles_col_width: 200,
            tile_overlay_opacity:0.3,
            tile_enable_icons:false,
            tile_enable_textpanel:true,
            tile_as_link:true,
            tile_link_newpage:false,
            lightbox_textpanel_title_color:"e5e5e5"}
        );
    });

</script>
</body>
</html>