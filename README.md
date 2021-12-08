# statgal2
**Quick'n'Dirty Static Gallery Builder with a few interesting features.**

*Need I emphasize it's cruftalicious? It works, but only by dumb luck. Don't say you weren't warned.*

- Given a hierarchy of directories containing images and videos, build a static gallery.

- Static pages are templated, so output could actually be PHP or other interpreted code, or fit into other kinds of systems, or fed into your favorite Javascript dynamic gallery thing or whatever.

- Templates are just PHP, so you can do whatever crazy kinds of thing you want to do.

- Galleries contain images and/or galleries. Thumbnails click through to an image page with a larger size version of the image, and other details.

- Sub-galleries inherit settings from their parent, but may override them.

- Galleries may be individually password-protected with Basic Authentication.

- Images may be optionally renamed for their gallery and given a sequential index.

- EXIF keywords, title, caption, description, camera data may be extracted and associated with the image.

- Keywords and date may be displayed on the image page.

- Summary pages listing all site keywords and/or dates (clumped by year and month) may be generated.

- A special version of the image may be created where the keywords are rendered into the image itself, so that the names of people pictured or locations may be preserved (for example) for users who do not have access to viewing EXIF data (code was originally developed for [The Legacy Labeler](https://legacy-labeler.com), which describes it better than I'll do here).

- Zip files may be generated for each gallery, to allow end users to download all the images.

- Picture pages may provide a link to a lerger sized version of the images for download.

- The system tries to regenerate portions intelligently, without reprocessing galleries that haven't changed.

- File support: jpg, png, gif, mov, mp4, mpeg

# requirements
PHP with Imagick and SQLLite support. If you're going to do keywords, you'll need [exiv2](https://www.exiv2.org/). If you're going to do anything with video, you'll need [ffmpeg](https://ffmpeg.org/).

# usage
- Create a directory or directory hierarchy of images.
- Optionally, create a "config.txt" in the top level and/or any subdirectories. See the documented example in the "sample_src" directory for details.
- Run it: `./statgal2.php -s sample_src`

statgal.php [-s source directory] [-v] [-f] [-F] [-n] [-e exiv2 path] [-m ffmpeg path] [-p ffprobe path]
  source directory (or any subdir) may contain a config file named config.txt
  -v is verbose
  -f (re)scales all images, generates pages
  -F means redo pages from scratch but no image processing
  -n means clear the data cache, and rescan for all structure and keyword data. Implies -f
  -e provides explicit path to exiv2 executable
  -m provides explicit path to ffmpeg executable
  -p provides explicit path to ffprobe executable
defaults:
-s =
-v =
-f =
-F =
-n =
-e = /usr/bin/exiv2
-m = /usr/bin/ffmpeg
-p = /usr/bin/ffprobe

Place a file "redo.txt" in a directory to be the equivalent of "-f" for that directory only. The "redo.txt" file will be removed after processing.

# sample output
You can see the output when this is [run against the sample_src directory](https://libelle.github.io/statgal2/).