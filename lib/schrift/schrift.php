<?php

# Copyright (c) 2011 Mark Raddatz <mraddatz@gmail.com>
#
# Schrift is a font library that reads true type fonts and creates subset
# fonts containing only the needed characters. It is released under
# the MIT license.

class Schrift {

  static $default_options = array(
    "debug" => false,
    "platform_id" => 3,
  );

  function __construct($file_name, $options=array()) {
    $this->file_name = $file_name;

    $this->options = array_merge(self::$default_options, $options);
    $this->debug = $this->options["debug"];

    $this->glyph_ids = null;
    $this->maxp = array();
    $this->head = array();
    $this->loca = array();

    $this->file = null;
    $this->open_file();

    $this->font_index = $this->read_font_index();
    $this->table_index = $this->read_table_index();
  }

  function subset($text) {
    $unicodes = gettype($text) == "string"
                ? $this->to_unicode($text)
                : $text;

    sort($unicodes);
    $unicodes = array_unique($unicodes);

    $charmap = $this->glyph_subset($unicodes);

    $this->read_head_table();
    $this->read_maxp_table();
    $this->read_loca_table();

    $glyf = $this->write_glyph_table($charmap);
    $cmap = $this->write_cmap_table($charmap, $glyf["mapping"]);

    $format = end($glyf["loca"]) > 0xffff ? 1 : 0;
    $num_glyphs = count($glyf["loca"]) - 1;

    $tables = array();
    $tables[] = $cmap;
    $tables[] = $glyf;
    $tables[] = $head = $this->write_head_table($format);
    $tables[] = $hhea = $this->write_hhea_table();
    $tables[] = $hmtx = $this->write_hmtx_table();
    $tables[] = $loca = $this->write_loca_table($glyf["loca"]);
    $tables[] = $maxp = $this->write_maxp_table($num_glyphs);
    $tables[] = $name = $this->write_name_table();
    $tables[] = $post = $this->write_post_table();

    $font = $this->write_font_directory($tables);
    $tabl = $this->write_table_directory($tables);

    $output = $font["output"] . $tabl["output"];
    foreach($tables as $table) {
      if ($table["tag"] == "head") {
        $checksum = $this->font_checksum($font, $tabl, $tables);
        $table = $this->write_head_table($format, $checksum);
      }
      $output .= $table["output"];
    }
    return $output;
  }

  function supported_charcodes() {
    if ($this->glyph_ids === null) {
      $this->read_cmap_index();
    }
    return array_keys($this->glyph_ids);
  }

  function supported_chars() {
    $unicodes = $this->supported_charcodes();
    $codepoints = array();
    foreach($unicodes as $codepoint) {
      $codepoints[] = pack("N", $codepoint);
    }
    return iconv("ucs-4be", "utf-8", implode($codepoints));
  }

  function to_unicode($text, $encoding="utf-8") {
    return array_values(unpack("N*", iconv($encoding, "ucs-4be", $text)));
  }

  function open_file() {
    if ($this->debug) {
      print("parse file: " . $this->file_name . "\n");
    }
    $this->file = fopen($this->file_name, "r");
  }

  function read_font_index() {
    fseek($this->file, 0, SEEK_SET);
    $font = unpack(
      "Nscalar_type/" .
      "nnum_tables/" .
      "nsearch_range/" .
      "nentry_selector/" .
      "nrange_shift",
      fread($this->file, 12)
    );

    if ($this->debug) {
      print_r(array("font" => $font));
    }

    return $font;
  }

  function read_table_index() {
    fseek($this->file, 12, SEEK_SET);
    $table = array();
    for($i = 0; $i < $this->font_index["num_tables"]; $i++) {
      $table = array_merge($table, $this->read_table($i));
    }

    if ($this->debug) {
      print_r(array("table" => $table));
    }

    return $table;
  }

  function read_table($index) {
    fseek($this->file, 12 + $index * 16, SEEK_SET);
    $tag = fread($this->file, 4);
    $table = unpack(
      "Nchecksum/" .
      "Noffset/" .
      "Nlength",
      fread($this->file, 12)
    );
    $table["name"] = self::$table_name[$tag];
    return array($tag => $table);
  }

  function read_head_table() {
    fseek($this->file, $this->table_index["head"]["offset"], SEEK_SET);
    $table = unpack(
      "nversion1/" .
      "nversion2/" .
      "nfont_revision1/" .
      "nfont_revision2/" .
      "Ncheck_sum_adjustment/" .
      "Nmagic_number/" .
      "nflags/" .
      "nunits_per_em/" .
      "Ncreated1/" .
      "Ncreated2/" .
      "Nmodified1/" .
      "Nmodified2/" .
      "nx_min/" .
      "ny_min/" .
      "nx_max/" .
      "ny_max/" .
      "nmac_style/" .
      "nlowest_rec_ppem/" .
      "nfont_direction_hint/" .
      "nindex_to_loc_format/" .
      "nglyph_data_format" ,
      fread($this->file, 54)
    );

    if ($this->debug) {
      print_r(array("table" => $table));
    }

    $this->head = $table;
  }

  function read_maxp_table() {
    fseek($this->file, $this->table_index["maxp"]["offset"], SEEK_SET);
    $table = unpack(
      "nversion1/" .
      "nversion2/" .
      "nnum_glyphs/" .
      "nmax_points/" .
      "nmax_contours/" .
      "nmax_component_points/" .
      "nmax_component_contours/" .
      "nmax_zones/" .
      "nmax_twilight_points/".
      "nmax_storage/" .
      "nmax_function_defs/" .
      "nmax_instruction_defs/" .
      "nmax_stack_elements/" .
      "nmax_size_of_instructons/" .
      "nmax_component_elements/" .
      "nmax_component_depth",
      fread($this->file, 32)
    );

    if ($this->debug) {
      print_r(array("table" => $table));
    }

    $this->maxp = $table;
  }

  function read_loca_table() {
    fseek($this->file, $this->table_index["loca"]["offset"], SEEK_SET);

    $long_format = $this->head["index_to_loc_format"] == 1;

    $loca = array_values(unpack(
      $long_format ? "N*" : "n*",
      fread($this->file, $this->table_index["loca"]["length"])
    ));

    if (!$long_format) {
      foreach($loca as $i => $offset) {
        $loca[$i] = $offset * 2;
      }
    }

    $this->loca = $loca;
  }

  function glyph_subset($unicodes) {
    $map = array();

    if ($this->glyph_ids === null) {
      $this->read_cmap_index();
    }

    foreach($unicodes as $charcode) {
      $glyph_id = $this->get_glyph_ids($charcode);
      if ($glyph_id != 0) {
        $map[$charcode] = $glyph_id;
      }
    }

    return $map;
  }

  function set_glyph_ids($charcode, $glyph_id) {
    if ($glyph_id == 0) {
      return;
    }

    if (isset($this->glyph_ids[$charcode]) && $this->glyph_ids[$charcode] != $glyph_id) {
      if ($this->debug) {
        $old_glyph_id =  $this->glyph_ids[$charcode];
        print("glyph for char " . $charcode . ": " . $old_glyph_id . " " . $glyph_id . "\n");
      }
      return;
    }

    $this->glyph_ids[$charcode] = $glyph_id;
  }

  function get_glyph_ids($charcode) {
    if (gettype($charcode) == "string") {
      list($charcode) = $this->to_unicode($charcode);
    }

    if (isset($this->glyph_ids[$charcode])) {
      return $this->glyph_ids[$charcode];
    }

    # no glyph for this char
    return 0;
  }

  function read_cmap_index() {
    fseek($this->file, $this->table_index["cmap"]["offset"], SEEK_SET);
    $cmap = unpack(
      "nversion/" .
      "nnumber_subtables",
      fread($this->file, 4)
    );

    if ($this->debug) {
      print_r(array("cmap" => $cmap));
    }

    for ($i = 0; $i < $cmap["number_subtables"]; $i++) {
      $header = $this->read_cmap_subtable_header($i);
      if ($header["platform_id"] == $this->options["platform_id"]) {
        $this->read_cmap_subtable($header["offset"]);
      }
    }

    ksort($this->glyph_ids);

    if ($this->debug) {
      print_r(array("glyph_ids" => $this->glyph_ids));
    }
  }

  function read_cmap_subtable_header($index) {
    fseek($this->file, $this->table_index["cmap"]["offset"] + 4 + $index * 8, SEEK_SET);
    $header = unpack(
      "nplatform_id/" .
      "nencoding_id/" .
      "Noffset",
      fread($this->file, 8)
    );

    $header["platform_name"] = self::$platform_name[$header["platform_id"]];
    $header["encoding_name"] = self::$encoding_name[$header["platform_id"]][$header["encoding_id"]];

    if ($this->debug) {
      print_r($header);
    }

    return $header;
  }

  function read_cmap_subtable($offset) {
    fseek($this->file, $this->table_index["cmap"]["offset"] + $offset, SEEK_SET);

    $cmap = unpack(
      "nformat",
      fread($this->file, 2)
    );

    if ($this->debug) {
      print_r($cmap);
    }

    if ($cmap["format"] == 0) {
      $this->read_cmap_format0($offset);
    }
    else if ($cmap["format"] == 4) {
      $this->read_cmap_format4($offset);
    }
    else if ($cmap["format"] == 6) {
      $this->read_cmap_format6($offset);
    }
    else if ($cmap["format"] == 12) {
      $this->read_cmap_format12($offset);
    }
    else {
      throw new Exception("Cmap format " . $cmap["format"] . " not supported yet.");
    }
  }

  function read_cmap_format0($offset) {
    fseek($this->file, $this->table_index["cmap"]["offset"] + $offset + 2, SEEK_SET);
    $cmap = unpack(
      "nlength/" .
      "nlanguage/" .
      "C256glyph_ids_array",
      fread($this->file, 260)
    );

    for($charcode = 0; $charcode < 256; $charcode++) {
      $glyph_id = $cmap["glyph_ids_array" . ($i + 1)];
        $this->set_glyph_ids($charcode, $glyph_id);
    }

    if ($this->debug) {
      print_r(array("format0" => $this->glyph_ids));
    }
  }

  function read_cmap_format4($offset) {
    fseek($this->file, $this->table_index["cmap"]["offset"] + $offset + 2, SEEK_SET);
    $header = unpack(
      "nlength/" .
      "nlanguage/" .
      "nseg_count_x2/" .
      "nsearch_range/" .
      "nentry_selector/" .
      "nrange_shift",
      fread($this->file, 12)
    );
    $seg_count = $header["seg_count_x2"] / 2;
    $cmap = unpack(
      "n" . $seg_count . "end_code/" .
      "nreserved_pad/" .
      "n" . $seg_count . "start_code/" .
      "n" . $seg_count . "id_delta/" .
      "n" . $seg_count . "id_range_offset",
      fread($this->file, $seg_count * 2 + 2 + $seg_count * 2 * 3)
    );
    unset($cmap["reserved_pad"]);

    # 0: end, 1: start, 2: delta, 3: rangeoffset
    $cmap = array_chunk($cmap,  $seg_count);

    if ($this->debug) {
      print_r(array("cmap" => $cmap));
    }

    $glyph_ids_length = $header["length"] - (14 + $seg_count * 2 + 2 + $seg_count * 2 * 3);
    $glyph_ids = $glyph_ids_length > 0
                 ? unpack("n*", fread($this->file, $glyph_ids_length))
                 : array();

     if ($this->debug) {
      print_r(array(
        "glyph_ids_length" => $glyph_ids_length,
        "glyph_ids" => $glyph_ids,
      ));
    }

    foreach($cmap[0] as $segment => $end) {
      $start = $cmap[1][$segment];
      $delta = $cmap[2][$segment];
      $range_offset = $cmap[3][$segment];
      if ($range_offset == 0) {
        for($i = 0; $i <= ($end - $start); $i++) {
          $charcode = $start + $i;
          $glyph_id = ($delta + $charcode) % 0x10000;
          $this->set_glyph_ids($charcode, $glyph_id);
        }
      }
      else {
        $glyph_offset = ($range_offset - ($seg_count * 2 - $segment * 2)) / 2 + 1;
        for($i = 0; $i <= ($end - $start); $i++) {
          $charcode = $start + $i;
          $glyph_id = $glyph_ids[$glyph_offset + $i];
          $this->set_glyph_ids($charcode, $glyph_id);
        }
      }
    }
  }

  function read_cmap_format6($offset) {
    fseek($this->file, $this->table_index["cmap"]["offset"] + $offset + 2, SEEK_SET);
    $header = unpack(
      "nlength/" .
      "nlanguage/" .
      "nfirst_code/" .
      "nentry_count",
      fread($this->file, 8)
    );

    $cmap = unpack(
      "n" . $header["entry_count"],
      fread($this->file, 2 * $header["entry_count"])
    );

    if ($this->debug) {
      print_r(array("format6 cmap" => $cmap));
    }

    $start = $header["first_code"];
    $end = $start + $header["entry_count"];
    for ($i = 0; $i < ($end - $start); $i++) {
      $charcode = $start + $i;
      $glyph_id = $cmap[$i + 1];
      $this->set_glyph_ids($charcode, $glyph_id);
      if ($this->debug) {
        print("start ".$start." end " . $end . " i " . $i . " -> " . $glyph_id . "\n");
      }
    }
  }

  function read_cmap_format12($offset) {
    fseek($this->file, $this->table_index["cmap"]["offset"] + $offset + 2, SEEK_SET);
    $header = unpack(
      "nreserved/" .
      "Nlength/" .
      "Nlanguage/" .
      "Nn_groups",
      fread($this->file, 14)
    );
    for ($i = 0; $i < $header["n_groups"]; $i++) {
      $this->read_cmap12_group($this->table_index["cmap"]["offset"] + $offset , $i);
    }
  }

  function read_cmap12_group($offset, $index) {
    fseek($this->file, $offset + 16 + $index * 12, SEEK_SET);
      $cmap = unpack(
        "Nstart_charcode/" .
        "Nend_charcode/" .
        "Nstart_glyphcode",
        fread($this->file, 12)
      );
      $start = $cmap["start_charcode"];
      $end = $cmap["end_charcode"];
      $glyph = $cmap["start_glyphcode"];
      for($i = 0; $i <= ($end - $start); $i++) {
        $charcode = $start + $i;
        $glyph_id = $glyph + $i;
        $this->set_glyph_ids($charcode, $glyph_id);
      }
  }

  function write_cmap_table($charmap, $mapping) {
    $output  = pack("nn", 0, 1); # version 0, 1 table
    $output .= pack("nn", 3, 1);# Microsoft, Unicode BMP (UCS-2)
    $output .= pack("N", 12); # offset

    $output .= $this->write_cmap_table_format4($charmap, $mapping);

    $length = strlen($output);

    $output = $this->table_padding($output);
    $checksum = $this->table_checksum($output);
    return array(
      "tag" => "cmap",
      "output" => $output,
      "length" => $length,
      "checksum" => $checksum,
    );
  }

  function write_cmap_table_format4($charmap, $mapping) {
    $end_code = "";
    $start_code = "";
    $id_delta = "";
    $id_range_offset = "";

    $last_charcode = null;
    $last_delta = null;

    $final_code = false;

    foreach($charmap as $charcode => $glyph_id) {
      $delta = $mapping[$glyph_id] - $charcode;

      if ($last_charcode === null || $delta != $last_delta) {
        if ($last_charcode !== null) {
          $end_code .= pack("n", $last_charcode);
        }
        $start_code .= pack("n", $charcode);
        $id_delta .= pack("n", (0x10000 + $delta) % 0x10000);
        $id_range_offset .= pack("n", 0);
      }

      $last_delta = $delta;
      $last_charcode = $charcode;

      if ($charcode == 0xffff) {
        $final_code = true;
      }
    }

    if ($last_charcode !== null) {
      $end_code .= pack("n", $last_charcode);
    }

    if (!$final_code) {
      $end_code .= pack("n", 0xffff);
      $start_code .= pack("n", 0xffff);
      $id_delta .= pack("n", 1);
      $id_range_offset .= pack("n", 0);
    }

    $cmap = $end_code . pack("n", 0) . $start_code . $id_delta . $id_range_offset;

    $length = 14 + strlen($cmap);
    $seg_count = strlen($end_code) / 2;
    $seg_count_x2 = 2 * $seg_count;
    $log2 = intval(log($seg_count, 2));
    $search_range = pow(2, $log2) * 2;
    $entry_selector = $log2;
    $range_shift = 2 * $seg_count - $search_range;

    $output  = pack("nnnnnnn", 4, $length, 0, $seg_count_x2, $search_range,
                               $entry_selector, $range_shift);
    $output .= $cmap;

    return $output;
  }

  function write_glyph_table($charmap) {
    $output = "";
    $loca = array();
    $mapping = array();
    $offsets = array();

    # undef glyph should be index 0
    foreach(range(0, 3) as $i) {
      if ($this->debug) {
        print("write glyp " . $i . "\n");
      }
      $this->write_glyph($i, $output, $loca, $mapping, $offsets);
    }

    # map chars and it glyphs
    foreach($charmap as $charcode => $glyph_id) {
      if ($this->debug) {
        print("write char " . $charcode . "\n");
      }
      $this->write_glyph($glyph_id, $output, $loca, $mapping, $offsets);
    }

    # last loca entry
    $loca[] = $length = strlen($output);
    $output = $this->table_padding($output);
    $checksum = $this->table_checksum($output);
    return array(
      "tag" => "glyf",
      "output" => $output,
      "length" => $length,
      "loca" => $loca,
      "mapping" => $mapping,
      "offsets" => $offsets,
      "checksum" => $checksum,
    );
  }

  function write_glyph($glyph_id, &$output, &$loca, &$mapping, &$offsets) {
    if (isset($mapping[$glyph_id])) {
      if ($this->debug) {
        print("glyph " . $glyph_id . " already mapped -> " . $mapping[$glyph_id] . "\n");
      }
      return;
    }

    if (!isset($this->loca[$glyph_id])) {
      throw new Exception("Glyph " . $glyph_id . " not found in loca.");
    }

    $offset = $this->loca[$glyph_id];
    $length = $this->loca[$glyph_id + 1] - $offset;

    $glyph = "";

    if ($length > 0) {
      fseek($this->file, $this->table_index["glyf"]["offset"] + $offset, SEEK_SET);
      $header = unpack(
        "snumber_of_contours",
        fread($this->file, 2)
      );
      $num_contours = $header["number_of_contours"];

      if ($num_contours >= 0) {
        $glyph = $this->write_simple_glyph($offset, $length, $num_contours);
      }
      else if ($num_contours == -1) {
        $glyph = $this->write_compound_glyph($offset, $length,  $num_contours,
                                             $output, $loca, $mapping, $offsets);
      }
    }

    if (isset($mapping[$glyph_id])) {
      if ($this->debug) {
        print("glyph " . $glyph_id . " already mapped -> " . $mapping[$glyph_id] . "\n");
      }
      return;
    }

    $mapping[$glyph_id] = count($loca);
    $offsets[$glyph_id] = strlen($output);
    $loca[] = strlen($output);
    $output .= $glyph;

    if ($this->debug) {
      print("write glyph " . $glyph_id . " -> " . $mapping[$glyph_id] .
            " offset " . $offsets[$glyph_id] . " length " . $length . "\n");
    }
  }

  function write_simple_glyph($offset, $length, $number_of_contours) {
    $glyph  = pack("s", $number_of_contours);
    $glyph .= fread($this->file, $length - 2);
    return $glyph;
  }

  function write_compound_glyph($offset, $length, $number_of_contours,
                                &$output, &$loca, &$mapping, &$offsets) {
    $glyph  = pack("s", $number_of_contours);

    $glyph .= fread($this->file, 8);
    $read = 2 + 8;

    do {
      $header = unpack(
        "nflags/" .
        "nglyph_id",
        fread($this->file, 4)
      );
      $read += 4;

      $flags = $header["flags"];
      $glyph_id = $header["glyph_id"];

      if ($this->debug) {
        print("flags " . decbin($flags) . "\n");
        if ($flags & 0x0001) {print("    ARG_1_AND_2_ARE_WORDS\n");}
        if ($flags & 0x0002) {print("    ARGS_ARE_XY_VALUES\n");}
        if ($flags & 0x0004) {print("    ROUND_XY_TO_GRID\n");}
        if ($flags & 0x0008) {print("    WE_HAVE_A_SCALE\n");}
        if ($flags & 0x0010) {print("    obsolet\n");}
        if ($flags & 0x0020) {print("    MORE_COMPONENTS\n");}
        if ($flags & 0x0040) {print("    WE_HAVE_AN_X_AND_Y_SCALE\n");}
        if ($flags & 0x0080) {print("    WE_HAVE_A_TWO_BY_TWO\n");}
        if ($flags & 0x0100) {print("    WE_HAVE_INSTRUCTIONS\n");}
        if ($flags & 0x0200) {print("    USE_MY_METRICS\n");}
        if ($flags & 0x0400) {print("    OVERLAP_COMPOUND\n");}

        print("child glyph " . $glyph_id . "\n");
      }

      $pos = ftell($this->file);
      $this->write_glyph($glyph_id, $output, $loca, $mapping, $offsets);
      fseek($this->file, $pos, SEEK_SET);

      $glyph .= pack("nn", $flags, $mapping[$glyph_id]);

      # ARG_1_AND_2_ARE_WORDS
      $glyph .= fread($this->file, $flags & 0x0001 ? 4 : 2);
      $read += $flags & 0x0001 ? 4 : 2;

      if ($flags & 0x0080) {
        # WE_HAVE_A_TWO_BY_TWO
        $glyph .= fread($this->file, 8);
        $read += 8;
      }
      else if ($flags & 0x0040) {
        # WE_HAVE_AN_X_AND_Y_SCALE
        $glyph .= fread($this->file, 4);
        $read += 4;
      }
      else if ($flags & 0x0008) {
        # WE_HAVE_A_SCALE
        $glyph .= fread($this->file, 2);
        $read += 2;
      }

    # MORE_COMPONENTS
    } while ($flags & 0x0020);

    # WE_HAVE_INSTRUCTIONS
    if ($flags & 0x0100) {
      $header = unpack(
        "ninstruction_length",
        fread($this->file, 2)
      );
      $read += 2;

      $instruction_length = $header["instruction_length"];

      $glyph .= pack("n", $instruction_length);

      if ($instruction_length > 0) {
        $glyph .= fread($this->file, $instruction_length);
        $read += $instruction_length;
      }
    }

    # add padding
    for($diff = $length - $read; $diff > 0; $diff--) {
      $glyph .= "\0";
    }

    return $glyph;
  }

  function write_head_table($format, $checksum=0) {
    fseek($this->file, $this->table_index["head"]["offset"], SEEK_SET);
    $output = fread($this->file,  4 + 4); # copy verson and fond revision

    # skip check_sum_adjustment
    fread($this->file,  4);
    $output .= pack("N", $checksum);
    $output .= fread($this->file,  $this->table_index["head"]["length"] - 12 - 4);
    $output .= pack("nn", $format, 0);

    $length = strlen($output);
    if ($length != $this->table_index["head"]["length"]) {
      throw new Exception("head length differ.");
    }

    $output = $this->table_padding($output);
    $checksum = $this->table_checksum($output);
    return array(
      "tag" => "head",
      "output" => $output,
      "length" => $length,
      "checksum" => $checksum,
    );
  }

  function write_hhea_table() {
    fseek($this->file, $this->table_index["hhea"]["offset"], SEEK_SET);
    $output = fread($this->file, $this->table_index["hhea"]["length"]);
    $length = strlen($output);
    $output = $this->table_padding($output);
    $checksum = $this->table_checksum($output);
    return array(
      "tag" => "hhea",
      "output" => $output,
      "length" => $length,
      "checksum" => $checksum,
    );
  }

  function write_hmtx_table() {
    fseek($this->file, $this->table_index["hmtx"]["offset"], SEEK_SET);
    $output = fread($this->file, $this->table_index["hmtx"]["length"]);
    $length = strlen($output);
    $output = $this->table_padding($output);
    $checksum = $this->table_checksum($output);
    return array(
      "tag" => "hmtx",
      "output" => $output,
      "length" => $length,
      "checksum" => $checksum,
    );
  }

  function write_loca_table($loca) {
    $output = "";

    $long_format = end($loca) > 0xffff ? true : false;
    $format = $long_format ? "N" : "n";
    $multi = $long_format ? 1 : 0.5;

    foreach($loca as $index => $offset) {
      $output .= pack($format, $offset * $multi);
      if ($this->debug) {
        print("write loca " . $index . " -> " . ($offset * $multi) . "\n");
      }
    }

    $length = strlen($output);
    $output = $this->table_padding($output);
    $checksum = $this->table_checksum($output);

    return array(
      "tag" => "loca",
      "output" => $output,
      "length" => $length,
      "checksum" => $checksum,
    );
  }

  function write_maxp_table($num_glyphs) {
    fseek($this->file, $this->table_index["maxp"]["offset"], SEEK_SET);
    $output = fread($this->file, 4);

    fread($this->file, 2); # skip
    $output .= pack("n", $num_glyphs);

    $output .= fread($this->file, $this->table_index["maxp"]["length"] - 6);

    $length = strlen($output);

    if ($length != $this->table_index["maxp"]["length"]) {
      throw new Exception("maxp length not equal");
    }

    $output = $this->table_padding($output);

    $checksum = $this->table_checksum($output);
    return array(
      "tag" => "maxp",
      "output" => $output,
      "length" => $length,
      "checksum" => $checksum,
    );
  }

  function write_name_table() {
    fseek($this->file, $this->table_index["name"]["offset"], SEEK_SET);
    $output = fread($this->file, $this->table_index["name"]["length"]);
    $length = strlen($output);
    $output = $this->table_padding($output);
    $checksum = $this->table_checksum($output);
    return array(
      "tag" => "name",
      "output" => $output,
      "length" => $length,
      "checksum" => $checksum,
    );
  }

  function write_post_table() {
    fseek($this->file, $this->table_index["post"]["offset"] + 4, SEEK_SET);

    $output = pack("nn", 3, 0); # format 3.0
    $output .= fread($this->file, $this->table_index["post"]["length"] - 4);

    $length = strlen($output);

    $output = $this->table_padding($output);
    $checksum = $this->table_checksum($output);
    return array(
      "tag" => "post",
      "output" => $output,
      "length" => $length,
      "checksum" => $checksum,
    );
  }

  function write_font_directory($tables) {
    $num_tables = count($tables);
    $log2 = intval(log($num_tables, 2));
    $search_range = pow(2, $log2) * 16;
    $entry_selector = $log2;
    $range_shift = $num_tables*16 - $search_range;
    $output = pack("nnnnnn", 1, 0, $num_tables, $search_range, $entry_selector, $range_shift);
    $output = $this->table_padding($output);
    $checksum = $this->table_checksum($output);
    return array(
      "output" => $output,
      "checksum" => $checksum,
    );
  }

  function write_table_directory($tables) {
    $offset = 12 + count($tables) * 16;
    $table_offset = array();
    $output = "";

    foreach ($tables as $table) {
      $output .= $table["tag"];
      $output .= pack("NNN", $table["checksum"], $offset, $table["length"]);
      $table_offset[$table["tag"]] = $offset;
      $offset += strlen($table["output"]);
    }

    $output = $this->table_padding($output);
    $checksum = $this->table_checksum($output);
    return array(
      "output" => $output,
      "offsets" => $table_offset,
      "length" => $offset,
      "checksum" => $checksum,
    );
  }

  function table_padding($output) {
     # long aligning and padding with zeors
    while ((strlen($output) % 4) != 0) {
      $output .= "\0";
    }
    return $output;
  }

  function table_checksum($output) {
    $checksum = 0;
    foreach(unpack("N*", $output) as $long) {
      $checksum += $long;
    }
    return $checksum;
  }

  function font_checksum($font, $tabl, $tables) {
    $checksum = $font["checksum"] + $tabl["checksum"];
    foreach($tables as $table) {
      $checksum += $table["checksum"];
    }
    return $checksum;
  }

  static $table_name = array(
    "acnt"                => "accent attachment",
    "aivar"               => "axis variation",
    "bdat"                => "bitmap data",
    "bheb"                => "bitmap fond header",
    "bloc"                => "bitmap location",
    "bsln"                => "baseline",
    "cmap" /* ttf, otf */ => "character code mapping",
    "cvar"                => "CVT variation",
    "EBSC"                => "embedded bitmap scaling control",
    "fdsc"                => "font descriptor",
    "feat"                => "layout feature",
    "fmtx"                => "font metrics",
    "fpgm"                => "font program",
    "fvar"                => "font variation",
    "gasp"                => "grid-fitting and scan-conversion procedure",
    "glyf" /* ttf      */ => "glyph outline",
    "gvar"                => "glyph variation",
    "hdmx"                => "horizontal device metrics",
    "head" /* ttf, otf */ => "font header",
    "hhea" /* ttf, otf */ => "horizontal header",
    "hmtx" /* ttf, otf */ => "horizontal metrics",
    "hsty"                => "horizontal style",
    "just"                => "justification",
    "kern"                => "kerning",
    "lcar"                => "ligature caret",
    "loca" /* ttf      */ => "glyph location",
    "maxp" /* ttf, otf */ => "maximum profile",
    "mort"                => "metamorphosis",
    "name" /* ttf      */ => "name",
    "opbd"                => "optical bounds",
    "OS/2" /*      otf */ => "compatibility",
    "post" /* ttf, otf */ => "glyph name and PostScript compatibility",
    "prep"                => "control value program",
    "prop"                => "properties",
    "trak"                => "tracking",
    "vhea"                => "vertical header",
    "vmtx"                => "vertical metrics",
    "Zapf"                => "glyph reference",
    /* TrueType Outlines */
    "cvt "                 => "control value table",
    /* Post Script Outlines */
    "CFF "                 => "compact font format",
    "VORG"                => "vertical origin",
    /* Advanced Typographic Tables */
    "BASE"                => "baseline data",
    "GDEF"                => "glyph definition data",
    "GPOS"                => "glyph positioning data",
    "GSUB"                => "glyph substituion data",
    "JSTF"                => "justification data",
    /* Other OpenType Tables */
    "DSIG"                => "digital signature",
    "LTSH"                => "linear threshold data",
    "PCLT"                => "PCL 5 data",
    "VDMX"                => "Vertical device metrics",
    /* FontForge Tables */
    "FFTM"                => "fontforge timestamp"
  );

  static $platform_name = array(
    "Unicode",
    "Macintosh",
    "(reserved; do not use)",
    "Microsoft",
  );

  static $encoding_name = array(
    array(
      "Unicode 1.0 semantics",
      "Unicode 1.1 semantics",
      "ISO 10646 semantics",
      "Unicode 2.0 or later semantics (cmap format 0, 4, 6)",
      "Unicode 2.0 or later semantics (cmap format 0, 4, 6, 10, 12)",
      "Unicode Variation Sequences (cmap format 14)",
      "Unicode full repetoire (cmap format 0, 4, 6, 10, 12, 13)",
    ),
    array("Roman", "Japanese", "Korean", "Arabic", "Hebrew", "Greek",
      "Russian", "RSymbol", "Devanagari", "Gujarati", "Oriya",
      "Bengali", "Tamil", "Telugu", "Kannada", "Malayalam",
      "Sinhalese", "Burmese", "Khmer", "Thai", "Laotian",
      "Georgian", "Armenian", "SimplifiedChinese", "Tibetan",
      "Mongolian", "Geez", "Slavic", "Vietnamese", "Sindhi",
      "(Uninterpreted)",
    ),
    array(),
    array(
      "Symbol",
      "Unicode BMP (UCS-2)",
      "ShiftJIS",
      "PRC",
      "Big5",
      "Wansung",
      "Johab",
      "Reserved",
      "Reserved",
      "Reserved",
      "Unicode UCS-4",
    ),
  );
}

?>
