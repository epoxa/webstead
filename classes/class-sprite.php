<?php

/**
 * Created 04.05.14
 */
class YY_Sprite extends YY_Data
{

  private $img;
  static private $game;

  static public function setGame($game) {
    self::$game = $game;
  }

  /**
   * @param $desc string
   *
   * @return YY_Sprite
   * @throws Exception
   */

  static public function find($desc) {
    if (!isset(self::$game['images'][$desc])) throw new Exception("sprite $desc not found");
    return self::$game['images'][$desc];
  }

  static public function load($fname, $desc = null)
  {
    if ($desc && isset(self::$game['images'][$desc])) return $desc;

    $res = new YY_Sprite(['game' => self::$game]);

    $res->img = self::trySpecialStatic($fname);

    if ($res->img) {

//      $desc = $fname;

    } else {

      if (preg_match('/^pad:((\d+)(\s\d+)?(\s\d+)?(\s\d+)?),(.*)$/', $fname, $a)) {
        $fname = $a[6];
        list($top, $right, $bottom, $left) = [$a[2], $a[3], $a[4], $a[5]];
        if (!isset($left)) {
          if (isset($right)) $left = $right;
          else $left = $top;
        }
        if (!isset($bottom)) {
          $bottom = $top;
        }
      }

      YY::Log('debug', "pad:(fname)=$fname");

      $f = LIB_DIR . 'games/' . $res['game']['name'] . '/' . $fname;
      if (!file_exists($f)) {
        $f = WEB_DIR . 'themes/games/' . $res['game']['name'] . '/' . $fname;
        if (!file_exists($f)) {
          throw new Exception("Can't find file " . $f);
        }
      }

      $res->img = imagecreatefromstring(file_get_contents($f));
      if (!$res->img) {
        throw new Exception("Can't load sprite from " . $fname);
      }
      //    imagealphablending($res->img, true);
      imagesavealpha($res->img, true);

    }

    $hash = md5($fname);
    $hash = intval(substr($hash, 0, 8), 16) ^ intval(substr($hash, 8, 8), 16) ^ intval(substr($hash, 16, 8), 16) ^ intval(substr($hash, 24, 8), 16);
    while (isset(self::$game['images'][$desc = 'spr:' . $hash])) {
      $hash++;
    }

    self::$game['images'][$desc] = $res;
    return $desc;
  }

  public static function trySpecialStatic($fname) {
    $img = null;
    if (preg_match('/blank:(\d+)x(\d+)/', $fname, $a)) {
      $w = intval($a[1]);
      $h = intval($a[2]);
      $img = imagecreatetruecolor($w, $h);
      $cl = imagecolorallocatealpha($img,0,0,0,127);
      imagecolortransparent($img, $cl);
      imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $cl);
      //      $res->img = imagecreate($w, $h);
    } else if (preg_match('/box:(\d+)x(\d+)(,((#[0-9a-f]{3,12})|([a-z]+))(,(\d+))?)?/', $fname, $a)) {
      $w = intval($a[1]);
      $h = intval($a[2]);
      $img = imagecreatetruecolor($w, $h);
      //      $res->img = imagecreate($w, $h);
      if (isset($a[3])) {
        if (isset($a[5]) && $a[5]) { // RGB
          $val = substr($a[5], 1);
        } else if (isset($a[6]) && $a[6]) { // Named colour
          $val = self::$colorNames[$a[6]];
        } else {
          $val = '';
        }
        if (isset($a[8])) {
          $alpha = intval($a[8]);
        } else {
          $alpha = 255;
        }
//        $len = strlen($val) / 3;
//        $r = intval(substr($val, 0, $len), 16);
//        $g = intval(substr($val, $len, $len), 16);
//        $b = intval(substr($val, 2 * $len, $len), 16);
        $r = ($val & (0xFF << 16)) >> 16;
        $g = ($val & (0xFF << 8)) >> 8;
        $b = $val & 0xFF;
        $a = round(($alpha / 255.0) * 127.0);
//        YY::Log('debug', "$fname => RGBA($r,$g,$b,$a)");
        $fill = imagecolorallocate($img, $r, $g, $b);
//        $fill = imagecolorallocatealpha($img, $r, $g, $b, $a);
      } else {
        $fill = imagecolorallocate($img,0,0,0);
//        $fill = imagecolorallocatealpha($img,0,0,0,127);
      }
      imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $fill);
      // TODO: Хрен знает, что делать с этой альфой
    } else if (preg_match('/pad:/', $fname, $a)) {
      // Ничего не надо делать. Будет обрабатываться позже, при загрузке изображения
    } else if (preg_match('/:/', $fname, $a)) {
      throw new Exception("Unknown source - " . $fname);
    }
    return $img;
  }

  /**
   * @return array // Список дескрипторов измененных спрайтов (в кавычках) для обновления на клиенте
   */
  public static function finalize() {
    $res = [];
    foreach(self::$game['images'] as $desc => $sprite) {
      if ($sprite->updateData()) {
        $res[] = '"' . $desc . '"';
      };
    }
    return $res;
  }

  public function draw() {
    $this->updateImage();
    header('Content-Type: image/png');
    imagepng($this->img);
  }

  public function getImage() {
    $this->updateImage();
    return $this->img;
  }

  public function getSize() {
    $this->updateImage();
    return[1 => imagesx($this->img), 2 => imagesy($this->img)];
  }

  /**
   * @param $source YY_Sprite
   * @param $x int
   * @param $y int
   * @param $w int
   * @param $h int
   * @param $dst_x int
   * @param $dst_y int
   * @param $alpha int
   *
   */

  public function copyFrom($source, $x, $y, $w, $h, $dst_x, $dst_y, $alpha) {
    imagecopymerge($this->getImage(), $source->getImage(), $dst_x, $dst_y, $x, $y, $w, $h, $alpha);
    unset($this['data']);
  }

  /**
   * @param $source YY_Sprite
   * @param $x int
   * @param $y int
   * @param $w int
   * @param $h int
   * @param $dst_x int
   * @param $dst_y int
   */

  public function composeFrom($source, $x, $y, $w, $h, $dst_x, $dst_y) {
    imagecopyresampled($this->getImage(), $source->getImage(), $dst_x, $dst_y, $x, $y, $w, $h, $w, $h);
    unset($this['data']);
  }

  private function updateImage()
  {
    if ($this->img) return;
    $this->img = imagecreatefromstring($this['data']);
//    imagealphablending($this->img, true);
    imagesavealpha($this->img, true);
  }

  public function updateData()
  {
    if (isset($this['data'])) return false;
    ob_start();
//    imagegd2($this->img);
    imagepng($this->img);
    $this['data'] = ob_get_clean();
    return true;
  }

  static private $colorNames = [
    "aliceblue" => 0xf0f8ff,
    "antiquewhite" => 0xfaebd7,
    "aqua" => 0x00ffff,
    "aquamarine" => 0x7fffd4,
    "azure" => 0xf0ffff,
    "beige" => 0xf5f5dc,
    "bisque" => 0xffe4c4,
    "black" => 0x000000,
    "blanchedalmond" => 0xffebcd,
    "blue" => 0x0000ff,
    "blueviolet" => 0x8a2be2,
    "brown" => 0xa52a2a,
    "burlywood" => 0xdeb887,
    "cadetblue" => 0x5f9ea0,
    "chartreuse" => 0x7fff00,
    "chocolate" => 0xd2691e,
    "coral" => 0xff7f50,
    "cornflowerblue" => 0x6495ed,
    "cornsilk" => 0xfff8dc,
    "crimson" => 0xdc143c,
    "cyan" => 0x00ffff,
    "darkblue" => 0x00008b,
    "darkcyan" => 0x008b8b,
    "darkgoldenrod" => 0xb8860b,
    "darkgray" => 0xa9a9a9,
    "darkgreen" => 0x006400,
    "darkkhaki" => 0xbdb76b,
    "darkmagenta" => 0x8b008b,
    "darkolivegreen" => 0x556b2f,
    "darkorange" => 0xff8c00,
    "darkorchid" => 0x9932cc,
    "darkred" => 0x8b0000,
    "darksalmon" => 0xe9967a,
    "darkseagreen" => 0x8fbc8f,
    "darkslateblue" => 0x483d8b,
    "darkslategray" => 0x2f4f4f,
    "darkturquoise" => 0x00ced1,
    "darkviolet" => 0x9400d3,
    "deeppink" => 0xff1493,
    "deepskyblue" => 0x00bfff,
    "dimgray" => 0x696969,
    "dodgerblue" => 0x1e90ff,
    "feldspar" => 0xd19275,
    "firebrick" => 0xb22222,
    "floralwhite" => 0xfffaf0,
    "forestgreen" => 0x228b22,
    "fuchsia" => 0xff00ff,
    "gainsboro" => 0xdcdcdc,
    "ghostwhite" => 0xf8f8ff,
    "gold" => 0xffd700,
    "goldenrod" => 0xdaa520,
    "gray" => 0x808080,
    "green" => 0x008000,
    "greenyellow" => 0xadff2f,
    "honeydew" => 0xf0fff0,
    "hotpink" => 0xff69b4,
    "indianred" => 0xcd5c5c,
    "indigo" => 0x4b0082,
    "ivory" => 0xfffff0,
    "khaki" => 0xf0e68c,
    "lavender" => 0xe6e6fa,
    "lavenderblush" => 0xfff0f5,
    "lawngreen" => 0x7cfc00,
    "lemonchiffon" => 0xfffacd,
    "lightblue" => 0xadd8e6,
    "lightcoral" => 0xf08080,
    "lightcyan" => 0xe0ffff,
    "lightgoldenrodyellow" => 0xfafad2,
    "lightgrey" => 0xd3d3d3,
    "lightgreen" => 0x90ee90,
    "lightpink" => 0xffb6c1,
    "lightsalmon" => 0xffa07a,
    "lightseagreen" => 0x20b2aa,
    "lightskyblue" => 0x87cefa,
    "lightslateblue" => 0x8470ff,
    "lightslategray" => 0x778899,
    "lightsteelblue" => 0xb0c4de,
    "lightyellow" => 0xffffe0,
    "lime" => 0x00ff00,
    "limegreen" => 0x32cd32,
    "linen" => 0xfaf0e6,
    "magenta" => 0xff00ff,
    "maroon" => 0x800000,
    "mediumaquamarine" => 0x66cdaa,
    "mediumblue" => 0x0000cd,
    "mediumorchid" => 0xba55d3,
    "mediumpurple" => 0x9370d8,
    "mediumseagreen" => 0x3cb371,
    "mediumslateblue" => 0x7b68ee,
    "mediumspringgreen" => 0x00fa9a,
    "mediumturquoise" => 0x48d1cc,
    "mediumvioletred" => 0xc71585,
    "midnightblue" => 0x191970,
    "mintcream" => 0xf5fffa,
    "mistyrose" => 0xffe4e1,
    "moccasin" => 0xffe4b5,
    "navajowhite" => 0xffdead,
    "navy" => 0x000080,
    "oldlace" => 0xfdf5e6,
    "olive" => 0x808000,
    "olivedrab" => 0x6b8e23,
    "orange" => 0xffa500,
    "orangered" => 0xff4500,
    "orchid" => 0xda70d6,
    "palegoldenrod" => 0xeee8aa,
    "palegreen" => 0x98fb98,
    "paleturquoise" => 0xafeeee,
    "palevioletred" => 0xd87093,
    "papayawhip" => 0xffefd5,
    "peachpuff" => 0xffdab9,
    "peru" => 0xcd853f,
    "pink" => 0xffc0cb,
    "plum" => 0xdda0dd,
    "powderblue" => 0xb0e0e6,
    "purple" => 0x800080,
    "red" => 0xff0000,
    "rosybrown" => 0xbc8f8f,
    "royalblue" => 0x4169e1,
    "saddlebrown" => 0x8b4513,
    "salmon" => 0xfa8072,
    "sandybrown" => 0xf4a460,
    "seagreen" => 0x2e8b57,
    "seashell" => 0xfff5ee,
    "sienna" => 0xa0522d,
    "silver" => 0xc0c0c0,
    "skyblue" => 0x87ceeb,
    "slateblue" => 0x6a5acd,
    "slategray" => 0x708090,
    "snow" => 0xfffafa,
    "springgreen" => 0x00ff7f,
    "steelblue" => 0x4682b4,
    "tan" => 0xd2b48c,
    "teal" => 0x008080,
    "thistle" => 0xd8bfd8,
    "tomato" => 0xff6347,
    "turquoise" => 0x40e0d0,
    "violet" => 0xee82ee,
    "violetred" => 0xd02090,
    "wheat" => 0xf5deb3,
    "white" => 0xffffff,
    "whitesmoke" => 0xf5f5f5,
    "yellow" => 0xffff00,
    "yellowgreen" => 0x9acd32,
  ];

}