# Schrift

Schrift is a font library that reads true type fonts and creates subset
fonts containing only the needed characters. It is released under
the MIT license.

## Getting Started

 * Download Schrift and include the library into your project:

        include("schrift/schrift.php");

 * Run the code to create a font subset:

        $font = new Schrift("GentiumPlus-R.ttf");
        $data = $font->subset("Hello τυπογραφία!");

   The font subset in `$data` is a valid true type file and contains only
   the given characters.

   If you want to know which characters are supported by this font file:

        $chars = $font->supported_chars();
        print $chars;

   This will return an utf-8 encoded string. Additionally if you need
   integer character codes you can use `supported_charcodes()`.

   You can enable debugging output by overwriting the default options:

        $options = array("debug" => true);
        $font = new Schrift("GentiumPlus-R.ttf", $options);

## The Font Subset

At the moment the font subset contains the following information:

 * cmap table - character to glyph mappings
 * glyf table - simple and compound glyphs
 * head table - global font information
 * hhea table - horizontal font information
 * hmtx table - horizontal metric information
 * loca table - locations of the glyphs
 * maxp table - memory requirements
 * name table - human-readable names
 * post table - postscript names

The head, hmtx and name tables are unmodified copies of the original
font. The post table at this time contains no information.
